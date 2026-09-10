<?php

namespace Tests\Feature;

use App\Models\ApprovalDecision;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\PeriodCloseSignoffPolicy;
use App\Models\PeriodCloseSignoffPackage;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\PeriodCloseReadinessService;
use App\Services\PeriodCloseSignoffService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PeriodCloseSignoffEvidenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_signoff_is_immutable_tenant_bound_evidence_and_never_closes_a_period(): void
    {
        [$maker, $policyChecker, $reviewer, $period] = $this->actorsAndPeriod();
        $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
        $this->activePolicies($maker, $policyChecker);
        $readiness = app(PeriodCloseReadinessService::class)->evaluate($maker->company_id, $period->id, $maker->id);
        $service = app(PeriodCloseSignoffService::class);

        $package = $service->prepare($maker, $period->id, $readiness->id, CarbonImmutable::parse('2026-08-22 09:00:00'));
        $this->assertSame($readiness->snapshot_hash, $package->readiness_snapshot_hash);
        $this->assertFalse((bool) data_get($package->package_snapshot, 'readiness.eligible_to_close'));
        $this->assertSame('prepared', $service->state($package));

        $submitted = $service->submit($maker, $package, ['checklist' => 'prepared'], CarbonImmutable::parse('2026-08-22 10:00:00'));
        $this->assertSame('submitted', $service->state($submitted));
        $approved = $service->decide($reviewer, $submitted, 1, 'approved', ['review_note' => 'reviewed'], CarbonImmutable::parse('2026-08-22 11:00:00'));
        $this->assertSame('approved_evidence_only', $service->state($approved));
        $this->assertFalse($period->fresh()->is_closed);
        $this->assertDatabaseCount('approval_decisions', 1);
        $this->assertDatabaseCount('period_close_signoff_events', 2);

        $this->expectException(QueryException::class);
        DB::table('period_close_signoff_packages')->where('id', $package->id)->update(['package_hash' => str_repeat('0', 64)]);
    }

    public function test_only_configured_role_can_review_and_maker_cannot_approve_own_package(): void
    {
        [$maker, $policyChecker, $reviewer, $period] = $this->actorsAndPeriod();
        $this->activePolicies($maker, $policyChecker);
        $readiness = app(PeriodCloseReadinessService::class)->evaluate($maker->company_id, $period->id, $maker->id);
        $service = app(PeriodCloseSignoffService::class);
        $package = $service->submit($maker, $service->prepare($maker, $period->id, $readiness->id));

        try { $service->decide($reviewer, $package, 1, 'approved'); $this->fail('Reviewer without configured role must be rejected.'); } catch (AuthorizationException) { $this->assertTrue(true); }
        $maker->assignRole(Role::findOrCreate('admin', 'web'));
        try { $service->decide($maker, $package, 1, 'approved'); $this->fail('Package maker must not approve own signoff.'); } catch (AuthorizationException) { $this->assertTrue(true); }
    }

    public function test_policy_creation_rejects_an_actor_without_a_positive_tenant_id(): void
    {
        $actor = User::factory()->create(['company_id' => null]);

        $this->expectException(AuthorizationException::class);
        app(PeriodCloseSignoffService::class)->createDraftPolicy(
            $actor,
            'invalid-tenant-policy',
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
            ['admin'],
        );
    }

    public function test_policy_creation_rejects_legacy_or_arbitrary_reviewer_roles(): void
    {
        [$maker] = $this->actorsAndPeriod();
        $service = app(PeriodCloseSignoffService::class);

        foreach (['period-close-reviewer', 'auditor'] as $legacyRole) {
            try {
                $service->createDraftPolicy(
                    $maker,
                    'invalid-role-'.$legacyRole,
                    CarbonImmutable::parse('2026-01-01'),
                    CarbonImmutable::parse('2026-12-31'),
                    [$legacyRole],
                );
                $this->fail("Legacy or arbitrary reviewer role [{$legacyRole}] must be rejected.");
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('reviewer_roles', $exception->errors());
            }
        }
    }

    public function test_prepare_fails_closed_when_an_existing_active_policy_contains_a_legacy_reviewer_role(): void
    {
        [$maker, $policyChecker, $reviewer, $period] = $this->actorsAndPeriod();
        $readiness = app(PeriodCloseReadinessService::class)->evaluate($maker->company_id, $period->id, $maker->id);
        $legacyPolicy = PeriodCloseSignoffPolicy::withoutGlobalScope('company')->create([
            'company_id' => $maker->company_id,
            'policy_version' => 'legacy-role-policy',
            'effective_from' => CarbonImmutable::parse('2026-01-01'),
            'effective_to' => CarbonImmutable::parse('2026-12-31'),
            'reviewer_roles' => ['period-close-reviewer'],
            'separation_of_duties_required' => true,
            'approval_key' => 'gl.period-close.signoff',
            'created_by' => $maker->id,
        ]);
        $legacyPolicy->forceFill([
            'status' => 'active',
            'activated_by' => $policyChecker->id,
            'activated_at' => CarbonImmutable::parse('2026-01-02'),
        ])->save();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(PeriodCloseSignoffService::class)->prepare($maker, $period->id, $readiness->id, CarbonImmutable::parse('2026-08-22'));
    }

    /** @return array{User,User,User,Period} */
    private function actorsAndPeriod(): array
    {
        $company = Company::findOrFail(1);
        $fiscal = FiscalYear::where('company_id', $company->id)->firstOrFail();
        $period = Period::create(['fiscal_year_id' => $fiscal->id, 'period' => 8, 'period_number' => 8, 'name' => 'August 2026', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'open', 'is_closed' => false]);
        return [User::factory()->create(['company_id' => $company->id]), User::factory()->create(['company_id' => $company->id]), User::factory()->create(['company_id' => $company->id]), $period];
    }

    private function activePolicies(User $maker, User $policyChecker): void
    {
        $approval = app(ApprovalWorkflowService::class);
        $generic = $approval->createDraftPolicy($maker, 'gl.period-close.signoff', '1.0', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), [['required_approvals' => 1]]);
        $approval->activatePolicy($policyChecker, $generic);
        $service = app(PeriodCloseSignoffService::class);
        $policy = $service->createDraftPolicy($maker, '1.0', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), ['admin']);
        $service->activatePolicy($policyChecker, $policy);
    }
}
