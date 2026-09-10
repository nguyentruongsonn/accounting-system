<?php

namespace Tests\Feature;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Models\AuditLog;
use App\Models\CashPayment;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\CashBankVoucherAccountMappingPostingGate;
use App\Services\CashPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashBankVoucherAccountMappingPostingGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $maker;
    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_cash_bank_posting_policy', false);
        config()->set('accounting.enforce_cash_bank_posting_approval', false);
        config()->set('accounting.enforce_cash_bank_posting_account_mappings', true);
        $this->company = Company::create(['name' => 'Cash-bank map tenant', 'tax_code' => 'CB-MAP']);
        $this->maker = User::factory()->create(['company_id' => $this->company->id]);
        $this->checker = User::factory()->create(['company_id' => $this->company->id]);
        $this->configureAccountingTenant($this->maker, $this->company);
        $this->maker->assignRole(\Spatie\Permission\Models\Role::findOrCreate('accountant', 'web'));
        $this->actingAs($this->maker);
        foreach (['331', '1111', '3312', '1112'] as $code) {
            ChartOfAccount::create(['company_id' => $this->company->id, 'code' => $code, 'name' => 'Account '.$code, 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
    }

    public function test_missing_mapping_fails_closed_without_journal_entry(): void
    {
        $this->approvedPolicy();
        $voucher = $this->voucher('CB-MAP-MISSING');
        $this->expectException(AccountingAccountMappingUnavailableException::class);
        try {
            app(CashPaymentService::class)->post($voucher->id);
        } finally {
            $this->assertDatabaseHas('cash_payments', ['id' => $voucher->id, 'is_posted' => false]);
            $this->assertDatabaseMissing('journal_entries', ['source_document_type' => CashPayment::class, 'source_document_id' => $voucher->id]);
        }
    }

    public function test_owner_approved_debit_and_credit_mapping_replace_accounts_and_are_audited(): void
    {
        $policy = $this->approvedPolicy();
        $voucher = $this->voucher('CB-MAP-OK');
        $this->approvedMapping($policy, $voucher, 'debit', '331', '3312');
        $this->approvedMapping($policy, $voucher, 'credit', '1111', '1112');

        $posted = app(CashPaymentService::class)->post($voucher->id);
        $this->assertSame(['3312', '1112'], JournalEntry::findOrFail($posted->journal_entry_id)->lines()->orderBy('id')->pluck('account_code')->all());
        $audit = AuditLog::withoutGlobalScope('company')->where('model_id', $voucher->id)->where('action', 'cash_payment.account_mappings_applied')->sole();
        $this->assertSame('enforced', $audit->metadata['account_mapping_gate']);
        $this->assertSame(CashBankVoucherAccountMappingPostingGate::MAPPING_KEY, $audit->metadata['account_mappings']['mapping_key']);
        $this->assertCount(2, $audit->metadata['account_mappings']['resolutions']);
    }

    public function test_bank_context_uses_existing_description_field_not_cash_reason(): void
    {
        $voucher = new \App\Models\BankPayment(['voucher_type' => 'UNC', 'description' => 'Pay supplier']);
        $context = CashBankVoucherAccountMappingPostingGate::contextFor($voucher, '1121');
        $this->assertSame(['voucher_family' => 'bank_payment', 'voucher_type' => 'UNC', 'voucher_reason' => 'Pay supplier', 'source_account_code' => '1121'], $context);
    }

    private function approvedPolicy()
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->firstOrFail();
        $service = app(AccountingPolicyLifecycleService::class);
        return $service->approve($this->maker, $service->createDraft($this->maker, [
            'company_id' => $this->company->id, 'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.cash_payment', 'policy_version' => 'map-v1', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'owner-approved-cash-bank-map'], 'required_dimensions' => [], 'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ]));
    }

    private function approvedMapping($policy, CashPayment $voucher, string $role, string $source, string $mapped): void
    {
        $service = app(ApprovedAccountMappingLifecycleService::class);
        $draft = $service->createDraft($this->maker, [
            'company_id' => $this->company->id, 'accounting_policy_version_id' => $policy->id,
            'mapping_key' => CashBankVoucherAccountMappingPostingGate::MAPPING_KEY,
            'mapping_context' => CashBankVoucherAccountMappingPostingGate::contextFor($voucher, $source),
            'account_role' => $role, 'account_code' => $mapped, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ]);
        $service->approve($this->checker, $draft);
    }

    private function voucher(string $number): CashPayment
    {
        return app(CashPaymentService::class)->create([
            'company_id' => $this->company->id, 'voucher_type' => 'supplier_payment', 'voucher_number' => $number,
            'voucher_date' => '2026-08-22', 'posting_date' => '2026-08-22', 'reason' => 'Controlled supplier payment',
            'lines' => [['description' => 'Payment line', 'debit_account' => '331', 'credit_account' => '1111', 'amount' => 100]],
        ]);
    }
}
