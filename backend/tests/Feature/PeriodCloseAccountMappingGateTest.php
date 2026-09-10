<?php

namespace Tests\Feature;

use App\Models\AccountingPolicyVersion;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\PeriodCloseReadinessSnapshot;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\JournalEntryService;
use App\Services\PeriodClosingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PeriodCloseAccountMappingGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_uses_only_owner_approved_close_sources_and_target_accounts(): void
    {
        config()->set('accounting.enforce_period_close_account_mappings', true);

        $company = Company::create(['name' => 'Controlled close tenant', 'tax_code' => 'CLOSE-CONTROLLED']);
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $maker->assignRole(Role::findOrCreate('admin', 'web'));
        $this->configureAccountingTenant($maker, $company);
        $this->actingAs($maker);

        foreach (['5119', '6429', '711', '9999', '8888'] as $code) {
            ChartOfAccount::create([
                'company_id' => $company->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $policy = $this->approvedClosePolicy($company, $maker);
        $this->approveCloseMapping($company, $maker, $checker, $policy, 'result_clearing', '9999');
        $this->approveCloseMapping($company, $maker, $checker, $policy, 'retained_earnings', '8888');

        app(JournalEntryService::class)->createPosted([
            'company_id' => $company->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'CLOSE-SOURCE-01',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Controlled close source balances',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '5119', 'debit_amount' => '0.00', 'credit_amount' => '100.00'],
                ['account_code' => '6429', 'debit_amount' => '40.00', 'credit_amount' => '0.00'],
                ['account_code' => '711', 'debit_amount' => '0.00', 'credit_amount' => '50.00'],
                ['account_code' => '9999', 'debit_amount' => '110.00', 'credit_amount' => '0.00'],
            ],
        ]);
        $preview = app(PeriodClosingService::class)->preview($company->id, '2026-08-01', '2026-08-31');

        $this->assertTrue($preview['execution_available']);
        $this->assertSame('100.00', $preview['total_revenue']);
        $this->assertSame('40.00', $preview['total_expenses']);
        $this->assertSame('60.00', $preview['net_profit']);
        $this->assertSame([
            ['debit_account' => '5119', 'credit_account' => '9999', 'amount' => '100.00'],
            ['debit_account' => '9999', 'credit_account' => '6429', 'amount' => '40.00'],
            ['debit_account' => '9999', 'credit_account' => '8888', 'amount' => '60.00'],
        ], array_map(
            fn (array $line): array => array_intersect_key($line, array_flip(['debit_account', 'credit_account', 'amount'])),
            $preview['suggested_lines'],
        ));
        $this->assertSame($policy->id, $preview['account_mapping_lineage']['policy_id']);
        $this->assertSame('period_close.result', $preview['account_mapping_lineage']['mapping_key']);
        $this->assertCount(2, $preview['account_mapping_lineage']['resolutions']);
    }

    public function test_close_rejects_an_authenticated_foreign_company_before_loading_period_evidence(): void
    {
        $owner = Company::create(['name' => 'Close owner tenant', 'tax_code' => 'CLOSE-OWNER']);
        $foreign = Company::create(['name' => 'Close foreign tenant', 'tax_code' => 'CLOSE-FOREIGN']);
        Sanctum::actingAs(User::factory()->create(['company_id' => $owner->id]));

        try {
            app(PeriodClosingService::class)->executeForCompany($foreign->id, [
                'period_id' => 1,
                'from_date' => '2026-08-01',
                'to_date' => '2026-08-31',
            ]);
            $this->fail('Period close must reject a foreign company before resolving period evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }
    }

    public function test_accountant_cannot_bypass_the_admin_only_close_boundary_by_calling_the_service(): void
    {
        $company = Company::create(['name' => 'Accountant close tenant', 'tax_code' => 'CLOSE-ACCOUNTANT']);
        $accountant = User::factory()->create(['company_id' => $company->id]);
        $accountant->assignRole(Role::findOrCreate('accountant', 'web'));
        $year = $this->configureAccountingTenant($accountant, $company);
        Sanctum::actingAs($accountant);
        $period = Period::create([
            'fiscal_year_id' => $year->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'August 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
        $snapshot = [
            'schema' => 'period-close-readiness.v1',
            'company_id' => $company->id,
            'period' => ['id' => $period->id],
            'eligible_to_close' => true,
            'status' => 'ready',
            'checks' => [],
        ];
        $readiness = PeriodCloseReadinessSnapshot::withoutGlobalScope('company')->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'period_id' => $period->id,
            'status' => 'ready',
            'eligible_to_close' => true,
            'schema_version' => 'period-close-readiness.v1',
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'snapshot' => $snapshot,
            'requested_by' => $accountant->id,
            'evaluated_at' => now(),
        ]);

        $this->expectException(AuthorizationException::class);
        app(PeriodClosingService::class)->executeForCompany($company->id, [
            'period_id' => $period->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'period_close_readiness_snapshot_id' => $readiness->id,
            'close_reason' => 'Đã duyệt đối chiếu và mapping',
        ]);
    }

    public function test_close_uses_mapping_effective_on_posting_date_and_audits_its_lineage(): void
    {
        config()->set('accounting.enforce_period_close_account_mappings', true);
        config()->set('accounting.enforce_period_close_signoff', false);

        $company = Company::create(['name' => 'Close posting date tenant', 'tax_code' => 'CLOSE-POST-DATE']);
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $maker->assignRole(Role::findOrCreate('admin', 'web'));
        $fiscalYear = $this->configureAccountingTenant($maker, $company);
        $this->actingAs($maker);

        $period = Period::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'August 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
        foreach (['5119', '6429', '9999', '8888'] as $code) {
            ChartOfAccount::create([
                'company_id' => $company->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }
        $policy = $this->approvedClosePolicy($company, $maker, '2026-09-01', '2026-12-31');
        $this->approveCloseMapping($company, $maker, $checker, $policy, 'result_clearing', '9999', '2026-09-01', '2026-12-31');
        $this->approveCloseMapping($company, $maker, $checker, $policy, 'retained_earnings', '8888', '2026-09-01', '2026-12-31');

        $sourceEntry = app(JournalEntryService::class)->createPosted([
            'company_id' => $company->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'CLOSE-POST-DATE-SOURCE',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-08-31',
            'description' => 'August balances',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '5119', 'debit_amount' => '0.00', 'credit_amount' => '100.00'],
                ['account_code' => '6429', 'debit_amount' => '40.00', 'credit_amount' => '0.00'],
                ['account_code' => '9999', 'debit_amount' => '60.00', 'credit_amount' => '0.00'],
            ],
        ]);
        $this->assertSame('posted', $sourceEntry->status);
        $this->assertSame(3, $sourceEntry->lines()->count());
        $this->assertSame('general_journal', $sourceEntry->voucher_type);
        $this->assertSame('2026-08-31', $sourceEntry->posting_date->toDateString());
        $this->assertSame(['5119', '6429', '9999'], $sourceEntry->lines()->orderBy('id')->pluck('account_code')->all());
        $snapshot = [
            'schema' => 'period-close-readiness.v1',
            'company_id' => $company->id,
            'period' => ['id' => $period->id],
            'eligible_to_close' => true,
            'status' => 'ready',
            'checks' => [],
        ];
        $readiness = PeriodCloseReadinessSnapshot::withoutGlobalScope('company')->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'period_id' => $period->id,
            'status' => 'ready',
            'eligible_to_close' => true,
            'schema_version' => 'period-close-readiness.v1',
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'snapshot' => $snapshot,
            'requested_by' => $maker->id,
            'evaluated_at' => now(),
        ]);

        $mappedPreview = app(PeriodClosingService::class)->preview(
            $company->id,
            '2026-08-01',
            '2026-08-31',
            '2026-09-01',
        );
        $this->assertSame(['5119', '6429'], array_column($mappedPreview['account_mapping_lineage']['source_accounts'], 'account_code'));
        $this->assertSame('100.00', $mappedPreview['total_revenue']);
        $this->assertSame('40.00', $mappedPreview['total_expenses']);
        $this->assertCount(3, $mappedPreview['suggested_lines']);

        $closing = app(PeriodClosingService::class)->executeForCompany($company->id, [
            'period_id' => $period->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'posting_date' => '2026-09-01',
            'voucher_number' => 'CLOSE-POST-DATE-01',
            'period_close_readiness_snapshot_id' => $readiness->id,
            'close_reason' => 'Đã duyệt đối chiếu tháng 8',
        ]);

        $this->assertSame('posted', $closing->status);
        $this->assertSame(
            ['5119', '9999', '9999', '6429', '9999', '8888'],
            $closing->lines()->orderBy('id')->pluck('account_code')->all(),
        );
        $this->assertDatabaseHas('periods', ['id' => $period->id, 'status' => 'closed', 'is_closed' => true]);
        $audit = AuditLog::withoutGlobalScope('company')
            ->where('model_id', $closing->id)
            ->where('action', 'period_close.account_mappings_applied')
            ->sole();
        $this->assertSame($policy->id, $audit->metadata['account_mappings']['policy_id']);
        $this->assertCount(2, $audit->metadata['account_mappings']['resolutions']);
    }

    public function test_close_is_unavailable_when_owner_approved_close_mapping_is_missing(): void
    {
        $company = Company::create([
            'name' => 'Close Mapping Gate Co.',
            'tax_code' => '0101234567',
            'address' => 'Ha Noi',
        ]);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        Sanctum::actingAs($admin);
        $fiscalYear = FiscalYear::create([
            'company_id' => $company->id,
            'name' => 'FY 2026',
            'code' => 'FY2026-CLOSE-MAPPING-GATE',
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_closed' => false,
        ]);
        $period = Period::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'August 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        $snapshot = [
            'schema' => 'period-close-readiness.v1',
            'company_id' => $company->id,
            'period' => ['id' => $period->id],
            'eligible_to_close' => true,
            'status' => 'ready',
            'checks' => [],
        ];
        $readiness = PeriodCloseReadinessSnapshot::withoutGlobalScope('company')->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'period_id' => $period->id,
            'status' => 'ready',
            'eligible_to_close' => true,
            'schema_version' => 'period-close-readiness.v1',
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'snapshot' => $snapshot,
            'requested_by' => null,
            'evaluated_at' => now(),
        ]);

        config()->set('accounting.enforce_period_close_account_mappings', true);

        try {
            app(PeriodClosingService::class)->executeForCompany($company->id, [
                'period_id' => $period->id,
                'from_date' => '2026-08-01',
                'to_date' => '2026-08-31',
                'voucher_number' => 'CLOSE-MAPPING-GATE-01',
                'period_close_readiness_snapshot_id' => $readiness->id,
                'close_reason' => 'Kiểm tra mapping trước khi khóa kỳ',
            ]);
            $this->fail('Close must remain unavailable without owner-approved effective account mappings.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Chưa thể đóng kỳ: chưa có mapping tài khoản kết chuyển được phê duyệt theo doanh nghiệp, chế độ kế toán và ngày hiệu lực.',
                $exception->errors()['period_close_account_mappings'][0],
            );
        } finally {
            // Do not leak the production gate setting into neighbouring legacy
            // diagnostic tests in the same PHP process.
            config()->set('accounting.enforce_period_close_account_mappings', false);
        }

        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $company->id,
            'voucher_number' => 'CLOSE-MAPPING-GATE-01',
        ]);
        $this->assertDatabaseHas('periods', [
            'id' => $period->id,
            'is_closed' => false,
            'status' => 'open',
        ]);
    }

    private function approvedClosePolicy(
        Company $company,
        User $maker,
        string $effectiveFrom = '2026-01-01',
        string $effectiveTo = '2026-12-31',
    ): AccountingPolicyVersion {
        $year = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('year', 2026)
            ->firstOrFail();
        $service = app(AccountingPolicyLifecycleService::class);
        $draft = $service->createDraft($maker, [
            'company_id' => $company->id,
            'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.period_closing',
            'policy_version' => 'period-close-v1',
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'posting_rule_contract' => [
                'schema' => 'period-closing.v1',
                'source_accounts' => [
                    ['account_code' => '5119', 'category' => 'revenue'],
                    ['account_code' => '6429', 'category' => 'expense'],
                ],
            ],
            'required_dimensions' => [],
            'regulatory_dependencies' => [],
        ]);

        return $service->approve($maker, $draft);
    }

    private function approveCloseMapping(
        Company $company,
        User $maker,
        User $checker,
        AccountingPolicyVersion $policy,
        string $role,
        string $accountCode,
        string $effectiveFrom = '2026-01-01',
        string $effectiveTo = '2026-12-31',
    ): void {
        $service = app(ApprovedAccountMappingLifecycleService::class);
        $draft = $service->createDraft($maker, [
            'company_id' => $company->id,
            'accounting_policy_version_id' => $policy->id,
            'mapping_key' => 'period_close.result',
            'mapping_context' => [],
            'account_role' => $role,
            'account_code' => $accountCode,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'regulatory_dependencies' => [],
        ]);
        $service->approve($checker, $draft);
    }
}
