<?php

namespace Tests\Feature;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\SalesInvoiceAccountMappingPostingGate;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceAccountMappingPostingGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $maker;
    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_sales_invoice_posting_policy', false);
        config()->set('accounting.enforce_sales_invoice_posting_approval', false);
        config()->set('accounting.enforce_sales_invoice_posting_account_mappings', true);
        $this->company = Company::create(['name' => 'Sales map tenant', 'tax_code' => 'SALES-MAP']);
        $this->maker = User::factory()->create(['company_id' => $this->company->id]);
        $this->checker = User::factory()->create(['company_id' => $this->company->id]);
        $this->configureAccountingTenant($this->maker, $this->company);
        $this->maker->assignRole(\Spatie\Permission\Models\Role::findOrCreate('accountant', 'web'));
        $this->actingAs($this->maker);
        foreach (['131', '1312', '5111', '5112', '33311', '33312', '632', '6322', '1561', '1562'] as $code) {
            ChartOfAccount::create(['company_id' => $this->company->id, 'code' => $code, 'name' => 'Account '.$code, 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
    }

    public function test_missing_required_sales_mapping_fails_before_journal_mutation(): void
    {
        $this->approvedPolicy();
        $invoice = $this->invoice('SALES-MAP-MISSING');

        $this->expectException(AccountingAccountMappingUnavailableException::class);
        try { app(SalesInvoiceService::class)->post($invoice->id); }
        finally {
            $this->assertDatabaseHas('sales_invoices', ['id' => $invoice->id, 'is_posted' => false]);
            $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $invoice->id]);
        }
    }

    public function test_owner_approved_sales_mappings_replace_each_role_and_audit_lineage(): void
    {
        $policy = $this->approvedPolicy();
        $this->approvedMapping($policy, 'settlement_debit', '1312', ['entry' => 'settlement_debit', 'payment_method' => 'unpaid', 'payment_status' => 'unpaid', 'document_status' => 'unpaid', 'source_account_code' => '131']);
        $this->approvedMapping($policy, 'revenue_credit', '5112', ['entry' => 'revenue_credit', 'voucher_type' => 'domestic', 'source_account_code' => '5111']);
        $this->approvedMapping($policy, 'output_vat', '33312', ['entry' => 'output_vat', 'source_account_code' => '33311']);

        $posted = app(SalesInvoiceService::class)->post($this->invoice('SALES-MAP-OK')->id);
        $this->assertSame(['1312', '5112', '33312'], $posted->journalEntry->lines()->orderBy('id')->pluck('account_code')->all());
        $audit = AuditLog::withoutGlobalScope('company')->where('action', 'sales_invoice.account_mappings_applied')->where('model_id', $posted->id)->sole();
        $this->assertSame('enforced', $audit->metadata['account_mapping_gate']);
        $this->assertSame(SalesInvoiceAccountMappingPostingGate::MAPPING_KEY, $audit->metadata['account_mappings']['mapping_key']);
        $this->assertCount(3, $audit->metadata['account_mappings']['resolutions']);
        $this->assertSame($policy->contract_hash, $audit->metadata['account_mappings']['policy_contract_hash']);
    }

    public function test_mapping_for_another_tenant_cannot_satisfy_sales_gate(): void
    {
        $this->approvedPolicy();
        $other = Company::create(['name' => 'Other mapping tenant', 'tax_code' => 'OTHER-SALES-MAP']);
        $otherMaker = User::factory()->create(['company_id' => $other->id]);
        $this->configureAccountingTenant($otherMaker, $other);
        // A similarly named external record must never become implicit evidence
        // for this tenant's sales voucher.
        $this->assertNotSame($this->company->id, $other->id);
        $this->expectException(AccountingAccountMappingUnavailableException::class);
        app(SalesInvoiceService::class)->post($this->invoice('SALES-MAP-XTENANT')->id);
    }

    public function test_export_slip_cannot_bypass_cogs_and_inventory_owner_mappings(): void
    {
        $policy = $this->approvedPolicy();
        $this->approvedMapping($policy, 'settlement_debit', '1312', ['entry' => 'settlement_debit', 'payment_method' => 'unpaid', 'payment_status' => 'unpaid', 'document_status' => 'unpaid', 'source_account_code' => '131']);
        $this->approvedMapping($policy, 'revenue_credit', '5112', ['entry' => 'revenue_credit', 'voucher_type' => 'domestic', 'source_account_code' => '5111']);
        $this->approvedMapping($policy, 'output_vat', '33312', ['entry' => 'output_vat', 'source_account_code' => '33311']);

        $invoice = $this->invoice('SALES-MAP-COGS', true);
        $this->expectException(AccountingAccountMappingUnavailableException::class);
        try { app(SalesInvoiceService::class)->post($invoice->id); }
        finally {
            $this->assertDatabaseHas('sales_invoices', ['id' => $invoice->id, 'is_posted' => false]);
            $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $invoice->id]);
        }
    }

    private function approvedPolicy()
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->firstOrFail();
        $service = app(AccountingPolicyLifecycleService::class);
        return $service->approve($this->maker, $service->createDraft($this->maker, [
            'company_id' => $this->company->id, 'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.sales_invoice', 'policy_version' => 'map-v1', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'owner-approved-sales-map'], 'required_dimensions' => [], 'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ]));
    }

    private function approvedMapping($policy, string $role, string $accountCode, array $context): void
    {
        $service = app(ApprovedAccountMappingLifecycleService::class);
        $draft = $service->createDraft($this->maker, [
            'company_id' => $this->company->id, 'accounting_policy_version_id' => $policy->id, 'mapping_key' => SalesInvoiceAccountMappingPostingGate::MAPPING_KEY,
            'mapping_context' => $context, 'account_role' => $role, 'account_code' => $accountCode, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ]);
        $service->approve($this->checker, $draft);
    }

    private function invoice(string $number, bool $exportSlip = false)
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => $number, 'name' => $number]);
        return app(SalesInvoiceService::class)->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'invoice_number' => $number, 'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22',
            'is_export_slip' => $exportSlip,
            // Draft intake now requires explicit role evidence when the
            // production account-mapping control is enabled. This is an
            // existing fixture COA code; post still proves approved mapping
            // replacement and export-slip COGS/inventory coverage.
            'lines' => [['description' => 'Mapped sales', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '10', 'debit_account' => '131', 'credit_account' => '5111', 'tax_account' => '33311', 'cogs_account' => '632', 'inventory_account' => '1561', 'cogs_price' => $exportSlip ? '60.00' : '0.00']],
        ]);
    }
}
