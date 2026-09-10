<?php

namespace Tests\Feature;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\PurchaseInvoiceAccountMappingPostingGate;
use App\Services\PurchaseInvoiceService;
use App\Support\DecimalMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceAccountMappingPostingGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $maker;
    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_purchase_invoice_posting_policy', false);
        config()->set('accounting.enforce_purchase_invoice_posting_approval', false);
        config()->set('accounting.enforce_purchase_invoice_posting_dimensions', false);
        config()->set('accounting.enforce_purchase_invoice_posting_account_mappings', true);
        $this->company = Company::create(['name' => 'Purchase map tenant', 'tax_code' => 'PURCHASE-MAP']);
        $this->maker = User::factory()->create(['company_id' => $this->company->id]);
        $this->checker = User::factory()->create(['company_id' => $this->company->id]);
        $this->configureAccountingTenant($this->maker, $this->company);
        $this->maker->assignRole(\Spatie\Permission\Models\Role::findOrCreate('accountant', 'web'));
        $this->actingAs($this->maker);
        foreach (['1561', '331', '1331', '1562', '3312', '1332'] as $code) {
            ChartOfAccount::create(['company_id' => $this->company->id, 'code' => $code, 'name' => 'Account '.$code, 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
    }

    public function test_missing_any_required_mapping_fails_closed_without_a_journal(): void
    {
        $this->approvedPolicy();
        $invoice = $this->invoice('MAP-MISSING');

        $this->expectException(AccountingAccountMappingUnavailableException::class);
        try {
            app(PurchaseInvoiceService::class)->post($invoice->id);
        } finally {
            $this->assertDatabaseHas('purchase_invoices', ['id' => $invoice->id, 'is_posted' => false]);
            $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $invoice->id]);
        }
    }

    public function test_all_explicit_owner_mappings_replace_accounts_and_record_immutable_lineage(): void
    {
        $policy = $this->approvedPolicy();
        $this->approvedMapping($policy, 'settlement_credit', '3312', [
            'entry' => 'settlement_credit', 'payment_method' => 'unpaid', 'payment_status' => 'draft', 'source_account_code' => '331',
        ]);
        $this->approvedMapping($policy, 'purchase_debit', '1562', [
            'entry' => 'purchase_debit', 'voucher_type' => 'domestic_inward', 'source_account_code' => '1561',
        ]);
        $this->approvedMapping($policy, 'input_vat', '1332', [
            'entry' => 'input_vat', 'source_account_code' => '1331',
        ]);

        $posted = app(PurchaseInvoiceService::class)->post($this->invoice('MAP-OK')->id);
        $lines = $posted->journalEntry->lines()->orderBy('id')->get();
        $this->assertSame(['3312', '1562', '1332'], $lines->pluck('account_code')->all());
        // MySQL returns DECIMAL zeroes as the truthy string "0.00"; choose
        // the non-zero side explicitly instead of relying on PHP truthiness.
        $this->assertSame(['110.00', '100.00', '10.00'], $lines->map(function ($line): string {
            $credit = (string) $line->getRawOriginal('credit_amount');
            $debit = (string) $line->getRawOriginal('debit_amount');

            return DecimalMoney::normalize(
                DecimalMoney::compare($credit, DecimalMoney::ZERO) !== 0 ? $credit : $debit
            );
        })->all());

        $audit = AuditLog::withoutGlobalScope('company')->where('model_id', $posted->id)->where('action', 'purchase_invoice.account_mappings_applied')->sole();
        $this->assertSame('enforced', $audit->metadata['account_mapping_gate']);
        $this->assertSame(PurchaseInvoiceAccountMappingPostingGate::MAPPING_KEY, $audit->metadata['account_mappings']['mapping_key']);
        $this->assertCount(3, $audit->metadata['account_mappings']['resolutions']);
        $this->assertSame($policy->contract_hash, $audit->metadata['account_mappings']['policy_contract_hash']);
    }

    private function approvedPolicy()
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->firstOrFail();
        $service = app(AccountingPolicyLifecycleService::class);
        return $service->approve($this->maker, $service->createDraft($this->maker, [
            'company_id' => $this->company->id, 'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.purchase_invoice', 'policy_version' => 'map-v1',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'owner-approved-purchase-map'], 'required_dimensions' => [],
            'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ]));
    }

    private function approvedMapping($policy, string $role, string $accountCode, array $context): void
    {
        $service = app(ApprovedAccountMappingLifecycleService::class);
        $draft = $service->createDraft($this->maker, [
            'company_id' => $this->company->id, 'accounting_policy_version_id' => $policy->id,
            'mapping_key' => PurchaseInvoiceAccountMappingPostingGate::MAPPING_KEY, 'mapping_context' => $context,
            'account_role' => $role, 'account_code' => $accountCode,
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ]);
        $service->approve($this->checker, $draft);
    }

    private function invoice(string $number)
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'code' => $number, 'name' => 'Supplier '.$number]);
        return app(PurchaseInvoiceService::class)->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'invoice_number' => $number,
            'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22', 'due_date' => '2026-09-22',
            'lines' => [[
                // Draft intake now requires explicit role evidence when the
                // production account-mapping control is enabled. These are
                // existing fixture COA codes; the test still exercises the
                // later approved-mapping replacement at post time.
                'description' => 'Mapped purchase', 'debit_account' => '1561', 'credit_account' => '331', 'quantity' => '1', 'unit_price' => '100.00',
                'tax_rate' => '10', 'tax_account' => '1331',
            ]],
        ]);
    }
}
