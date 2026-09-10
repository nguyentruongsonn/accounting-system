<?php

namespace Tests\Feature;

use App\Models\ApArSubledgerGlReconciliationRun;
use App\Models\BankAccount;
use App\Models\BankGlReconciliationRun;
use App\Models\Company;
use App\Models\FixedAssetGlReconciliationRun;
use App\Models\InventorySubledgerGlReconciliationRun;
use App\Models\User;
use App\Services\ControlledPeriodCloseReconciliationAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ControlledPeriodCloseReconciliationAggregatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_domain_evidence_is_explicitly_not_available_and_never_close_ready(): void
    {
        $company = Company::query()->firstOrFail();
        $result = app(ControlledPeriodCloseReconciliationAggregator::class)->evaluate($company->id, '2026-08-31');

        $this->assertFalse($result['eligible_to_close']);
        $this->assertSame('not_available', $result['status']);
        $this->assertSame('not_available', $this->check($result, 'AP_SUBLEDGER_GL')['status']);
        $this->assertSame('approved_bank_scope_missing', $this->check($result, 'BANK_GL')['details']['reason_code']);
    }

    public function test_direct_evaluation_rejects_an_authenticated_foreign_company(): void
    {
        $company = Company::query()->firstOrFail();
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));

        $this->expectException(ValidationException::class);
        app(ControlledPeriodCloseReconciliationAggregator::class)->evaluate($company->id + 1, '2026-08-31');
    }

    public function test_does_not_reuse_prior_cutoff_or_treat_it_as_current_evidence(): void
    {
        $company = Company::query()->firstOrFail();
        $this->approvedApAr($company->id, 'ap', '2026-08-30');

        $result = app(ControlledPeriodCloseReconciliationAggregator::class)->evaluate($company->id, '2026-08-31');

        $ap = $this->check($result, 'AP_SUBLEDGER_GL');
        $this->assertSame('not_available', $ap['status']);
        $this->assertSame('missing_same_cutoff_run', $ap['details']['reason_code']);
    }

    public function test_requires_every_domain_to_supply_latest_same_cutoff_approved_controlled_result(): void
    {
        $company = Company::query()->firstOrFail();
        $cutoff = '2026-08-31';
        $this->approvedApAr($company->id, 'ap', $cutoff);
        $this->approvedApAr($company->id, 'ar', $cutoff);
        $this->approved(InventorySubledgerGlReconciliationRun::class, $company->id, $cutoff);
        $this->approved(FixedAssetGlReconciliationRun::class, $company->id, $cutoff);
        $bank = BankAccount::create(['company_id' => $company->id, 'account_number' => 'TEST-'.Str::random(12), 'bank_name' => 'Test', 'currency' => 'VND', 'is_active' => true]);
        $this->approved(BankGlReconciliationRun::class, $company->id, $cutoff, ['bank_account_id' => $bank->id]);

        $result = app(ControlledPeriodCloseReconciliationAggregator::class)->evaluate($company->id, $cutoff);

        $this->assertTrue($result['eligible_to_close']);
        $this->assertSame('controlled_reconciled', $result['status']);
        $this->assertSame('pass', $this->check($result, 'BANK_GL')['status']);
    }

    public function test_latest_same_cutoff_not_available_capture_blocks_even_when_an_older_approved_capture_exists(): void
    {
        $company = Company::query()->firstOrFail();
        $cutoff = '2026-08-31';
        $approved = $this->approvedApAr($company->id, 'ap', $cutoff);
        ApArSubledgerGlReconciliationRun::withoutGlobalScope('company')->create([
            'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'ledger' => 'ap', 'as_of_date' => $cutoff,
            'status' => 'not_available', 'algorithm_version' => 'test.v1', 'contract_hash' => str_repeat('b', 64),
            'source_completeness' => ['source' => ['available' => false]], 'divergence_count' => 1,
            'snapshot' => ['as_of_date' => $cutoff, 'status' => 'not_available'], 'snapshot_hash' => str_repeat('c', 64),
            'recorded_at' => $approved->recorded_at->addSecond(),
        ]);

        $result = app(ControlledPeriodCloseReconciliationAggregator::class)->evaluate($company->id, $cutoff);
        $ap = $this->check($result, 'AP_SUBLEDGER_GL');
        $this->assertSame('not_available', $ap['status']);
        $this->assertContains('run_status_not_approved', $ap['details']['reasons']);
    }

    public function test_same_cutoff_ap_and_ar_runs_with_different_contract_families_block_close(): void
    {
        $company = Company::query()->firstOrFail();
        $cutoff = '2026-08-31';
        $this->approvedApAr($company->id, 'ap', $cutoff);
        $this->approvedApAr($company->id, 'ar', $cutoff, 'different-contract-family.v2');

        $result = app(ControlledPeriodCloseReconciliationAggregator::class)->evaluate($company->id, $cutoff);

        $compatibility = $this->check($result, 'AP_AR_CONTRACT_COMPATIBILITY');
        $this->assertFalse($result['eligible_to_close']);
        $this->assertSame('not_available', $compatibility['status']);
        $this->assertSame('contract_family_mismatch', $compatibility['details']['reason_code']);
    }

    private function approvedApAr(int $companyId, string $ledger, string $cutoff, string $algorithmVersion = 'controlled-test.v1'): ApArSubledgerGlReconciliationRun
    {
        /** @var ApArSubledgerGlReconciliationRun $run */
        $run = $this->approved(ApArSubledgerGlReconciliationRun::class, $companyId, $cutoff, ['ledger' => $ledger, 'algorithm_version' => $algorithmVersion]);
        return $run;
    }

    private function approved(string $model, int $companyId, string $cutoff, array $extra = []): mixed
    {
        return $model::withoutGlobalScope('company')->create($extra + [
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'as_of_date' => $cutoff,
            'status' => 'approved', 'algorithm_version' => 'controlled-test.v1',
            'contract_hash' => str_repeat('a', 64), 'source_completeness' => ['complete_source' => ['available' => true]],
            'divergence_count' => 0,
            'snapshot' => ['as_of_date' => $cutoff, 'status' => 'reconciled', 'control' => ['mode' => 'controlled', 'eligible_for_period_close' => true, 'approved_at' => '2026-09-01T00:00:00Z']],
            'snapshot_hash' => str_repeat('d', 64), 'recorded_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function check(array $result, string $code): array
    {
        foreach ($result['checks'] as $check) if ($check['code'] === $code) return $check;
        $this->fail("Missing check [{$code}].");
    }
}
