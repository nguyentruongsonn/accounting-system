<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\PeriodCloseReadinessSnapshot;
use App\Models\PeriodCloseSignoffPackage;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\JournalEntryService;
use App\Services\PeriodCloseSignoffPostingGate;
use App\Services\PeriodCloseSignoffService;
use App\Services\PeriodClosingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PeriodCloseSignoffPostingGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_accepts_only_exact_approved_immutable_lineage_and_independent_closer(): void
    {
        [$maker, $policyChecker, $reviewer, $closer, $period, $readiness] = $this->fixture();
        $package = $this->approvedPackage($maker, $policyChecker, $reviewer, $period, $readiness);

        $lineage = app(PeriodCloseSignoffPostingGate::class)->requireApproved(
            $maker->company_id, $period, $readiness, $closer->id,
        );

        $this->assertSame($package->id, $lineage['signoff_package_id']);
        $this->assertSame($readiness->snapshot_hash, $lineage['readiness_snapshot_hash']);
        $this->assertNotEmpty($lineage['decision_hashes']);
    }

    public function test_gate_rejects_preparer_but_allows_admin_reviewer_to_close_and_rejects_unavailable_source(): void
    {
        [$maker, $policyChecker, $reviewer, $closer, $period, $readiness] = $this->fixture();
        $this->approvedPackage($maker, $policyChecker, $reviewer, $period, $readiness);
        $gate = app(PeriodCloseSignoffPostingGate::class);

        try {
            $gate->requireApproved($maker->company_id, $period, $readiness, $maker->id);
            $this->fail('Package preparer must not close their own period under SoD.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('period_close_signoff', $exception->errors());
        }

        $reviewerLineage = $gate->requireApproved($maker->company_id, $period, $readiness, $reviewer->id);
        $this->assertSame($reviewer->id, $reviewerLineage['resolved_by']);

        $unavailable = $this->readiness($maker->company_id, $period, [
            ['code' => 'SOURCE.TEST', 'status' => 'not_available'],
        ]);
        try {
            $gate->requireApproved($maker->company_id, $period, $unavailable, $closer->id);
            $this->fail('A readiness snapshot with an unavailable source must never be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('period_close_readiness', $exception->errors());
        }
    }

    public function test_period_close_consumes_the_exact_signoff_inside_the_posting_transaction_and_audits_lineage(): void
    {
        [$maker, $policyChecker, $reviewer, $closer, $period, $readiness] = $this->fixture();
        $package = $this->approvedPackage($maker, $policyChecker, $reviewer, $period, $readiness);
        foreach ([
            ['131', 'Phải thu', 'asset', 'amphibious'], ['5111', 'Doanh thu', 'revenue', 'credit'],
            ['911', 'Xác định kết quả', 'equity', 'amphibious'], ['4212', 'LN chưa phân phối', 'equity', 'amphibious'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create(['company_id' => $maker->company_id, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
        app(JournalEntryService::class)->create(['company_id' => $maker->company_id, 'voucher_number' => 'SIGNOFF-REV', 'voucher_date' => '2026-08-10', 'posting_date' => '2026-08-10', 'status' => 'posted', 'lines' => [['debit_account' => '131', 'credit_account' => '5111', 'amount' => '100.00']]]);
        config()->set('accounting.enforce_period_close_signoff', true);
        // This test isolates signoff lineage. The production close-account
        // mapping gate is exercised fail-closed by
        // PeriodCloseAccountMappingGateTest; no owner-approved COA is invented
        // here merely to make this signoff-only fixture post.
        $closeMappingGate = config('accounting.enforce_period_close_account_mappings', true);
        config()->set('accounting.enforce_period_close_account_mappings', false);
        Sanctum::actingAs($reviewer);

        try {
            $closing = app(PeriodClosingService::class)->executeForCompany($maker->company_id, [
                'period_id' => $period->id, 'period_close_readiness_snapshot_id' => $readiness->id,
                'from_date' => '2026-08-01', 'to_date' => '2026-08-31', 'voucher_number' => 'SIGNOFF-CLOSE',
                // Exercise the legacy alias; the audit must still use the
                // canonical close_reason metadata key.
                'reason' => 'Đã hoàn tất đối chiếu tháng 8',
            ]);
        } finally {
            config()->set('accounting.enforce_period_close_account_mappings', $closeMappingGate);
        }

        $this->assertSame('posted', $closing->status);
        $this->assertTrue((bool) $period->fresh()->is_closed);
        $closeAudit = AuditLog::withoutGlobalScope('company')->where('action', 'period.closed')->where('model_id', $closing->id)->sole();
        $this->assertSame('Đã hoàn tất đối chiếu tháng 8', $closeAudit->metadata['close_reason']);
        $this->assertSame('Đã hoàn tất đối chiếu tháng 8', $closeAudit->metadata['reason']);
        $audit = AuditLog::withoutGlobalScope('company')->where('action', 'period_close.signoff_applied')->where('model_id', $closing->id)->sole();
        $this->assertSame($package->uuid, data_get($audit->metadata, 'signoff.signoff_package_uuid'));
        $this->assertSame($readiness->snapshot_hash, data_get($audit->metadata, 'signoff.readiness_snapshot_hash'));
    }

    /** @return array{User,User,User,User,Period,PeriodCloseReadinessSnapshot} */
    private function fixture(): array
    {
        $company = Company::findOrFail(1);
        $fiscal = FiscalYear::where('company_id', $company->id)->firstOrFail();
        $period = Period::create(['fiscal_year_id' => $fiscal->id, 'period' => 8, 'period_number' => 8, 'name' => 'August 2026', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'open', 'is_closed' => false]);
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $reviewer = User::factory()->create(['company_id' => $company->id]);
        $closer = User::factory()->create(['company_id' => $company->id]);
        $reviewer->assignRole(Role::findOrCreate('admin', 'web'));

        return [$maker, $checker, $reviewer, $closer, $period, $this->readiness($company->id, $period, [
            ['code' => 'RECONCILIATION.ENFORCED_CLOSE_GATE', 'status' => 'pass'],
            ['code' => 'SOURCE.COMPLETE', 'status' => 'pass'],
        ])];
    }

    private function approvedPackage(User $maker, User $checker, User $reviewer, Period $period, PeriodCloseReadinessSnapshot $readiness): PeriodCloseSignoffPackage
    {
        $approval = app(ApprovalWorkflowService::class);
        $approval->activatePolicy($checker, $approval->createDraftPolicy($maker, 'gl.period-close.signoff', '1.0', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), [['required_approvals' => 1]]));
        $signoff = app(PeriodCloseSignoffService::class);
        $signoff->activatePolicy($checker, $signoff->createDraftPolicy($maker, '1.0', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), ['admin']));
        $package = $signoff->prepare($maker, $period->id, $readiness->id, CarbonImmutable::parse('2026-08-22 09:00:00'));
        $submitted = $signoff->submit($maker, $package, ['checklist' => 'complete'], CarbonImmutable::parse('2026-08-22 10:00:00'));

        return $signoff->decide($reviewer, $submitted, 1, 'approved', ['review_note' => 'independent review'], CarbonImmutable::parse('2026-08-22 11:00:00'));
    }

    /** @param list<array{code:string,status:string}> $checks */
    private function readiness(int $companyId, Period $period, array $checks): PeriodCloseReadinessSnapshot
    {
        $at = CarbonImmutable::parse('2026-08-22 08:00:00');
        $snapshot = ['schema' => 'period-close-readiness.v1', 'company_id' => $companyId, 'period' => ['id' => $period->id], 'eligible_to_close' => true, 'status' => 'ready', 'checks' => $checks];

        return PeriodCloseReadinessSnapshot::withoutGlobalScope('company')->create([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'period_id' => $period->id,
            'status' => 'ready', 'eligible_to_close' => true, 'schema_version' => 'period-close-readiness.v1',
            // Keep the fixture hash aligned with the production canonical
            // JSON contract. MySQL may reorder JSON object keys on storage;
            // hashing the insertion order would make this fixture pass on
            // SQLite but fail on the MySQL control-smoke job.
            'snapshot_hash' => hash('sha256', json_encode($this->canonicalise($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)),
            'snapshot' => $snapshot, 'requested_by' => null, 'evaluated_at' => $at,
        ]);
    }

    /** @return array<string|int, mixed> */
    private function canonicalise(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalise($item);
            }
        }

        return $value;
    }
}
