<?php

namespace Tests\Feature;

use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoicePostingPolicyGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_sales_invoice_posting_policy', true);
        config()->set('accounting.enforce_sales_invoice_posting_approval', false);
        config()->set('accounting.enforce_sales_invoice_posting_dimensions', false);
        config()->set('accounting.enforce_sales_invoice_posting_account_mappings', false);
        $this->company = Company::create(['name' => 'Sales policy tenant', 'tax_code' => 'SALES-POLICY']);
        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
        $this->configureAccountingTenant($this->actor, $this->company);
        $this->actor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('accountant', 'web'));
        $this->actingAs($this->actor);

        foreach ([
            ['code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'nature' => 'credit'],
        ] as $account) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                ...$account,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }
    }

    public function test_sales_posting_fails_closed_without_an_approved_tenant_policy(): void
    {
        $invoice = $this->newInvoice('SALES-POLICY-MISSING');

        $this->expectException(AccountingPolicyUnavailableException::class);
        try {
            app(SalesInvoiceService::class)->post($invoice->id);
        } finally {
            $this->assertDatabaseHas('sales_invoices', ['id' => $invoice->id, 'is_posted' => false]);
            $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $invoice->id]);
        }
    }

    public function test_sales_posting_uses_approved_contract_and_audits_policy_lineage(): void
    {
        $year = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)->where('year', 2026)->orderByDesc('id')->firstOrFail();
        $profile = $year->accountingRegimeProfile()->firstOrFail();
        $policy = app(AccountingPolicyLifecycleService::class)->approve($this->actor,
            app(AccountingPolicyLifecycleService::class)->createDraft($this->actor, [
                'company_id' => $this->company->id,
                'accounting_regime_profile_id' => $profile->id,
                'policy_key' => 'posting.sales_invoice',
                'policy_version' => '2026.1',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
                'posting_rule_contract' => ['mapping_reference' => 'tenant-approved-sales-map-v1'],
                'required_dimensions' => ['customer'],
                'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
            ])
        );

        $posted = app(SalesInvoiceService::class)->post($this->newInvoice('SALES-POLICY-APPROVED')->id);
        $this->assertTrue($posted->is_posted);
        $this->assertNotNull($posted->journal_entry_id);
        $audit = AuditLog::withoutGlobalScope('company')->where('action', 'sales_invoice.policy_applied')
            ->where('model_id', $posted->id)->sole();
        $this->assertSame('enforced', $audit->metadata['accounting_policy_gate']);
        $this->assertSame($policy->id, $audit->metadata['accounting_policy']['policy_id']);
        $this->assertSame($policy->contract_hash, $audit->metadata['accounting_policy']['contract_hash']);
    }

    public function test_sales_totals_and_posted_journal_use_exact_decimal_strings(): void
    {
        config()->set('accounting.enforce_sales_invoice_posting_policy', false);
        $invoice = app(SalesInvoiceService::class)->create([
            'company_id' => $this->company->id,
            'customer_id' => Customer::create(['company_id' => $this->company->id, 'code' => 'EXACT', 'name' => 'Exact customer'])->id,
            'invoice_number' => 'SALES-EXACT',
            'invoice_date' => '2026-08-22',
            'accounting_date' => '2026-08-22',
            'lines' => [[
                'quantity' => '3.00', 'unit_price' => '0.10', 'discount_rate' => '10.00', 'tax_rate' => '10.00',
                'credit_account' => '5111', 'tax_account' => '33311',
            ]],
        ]);

        $this->assertSame('0.30', $invoice->getRawOriginal('sub_total'));
        $this->assertSame('0.03', $invoice->getRawOriginal('discount_amount'));
        $this->assertSame('0.03', $invoice->getRawOriginal('tax_amount'));
        $this->assertSame('0.30', $invoice->getRawOriginal('total_amount'));
        $posted = app(SalesInvoiceService::class)->post($invoice->id);
        $this->assertSame('0.30', $posted->journalEntry->lines()->where('account_code', '131')->value('debit_amount'));
        $this->assertSame('0.27', $posted->journalEntry->lines()->where('account_code', '5111')->value('credit_amount'));
        $this->assertSame('0.03', $posted->journalEntry->lines()->where('account_code', '33311')->value('credit_amount'));
    }

    private function newInvoice(string $number)
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => $number, 'name' => 'Khách hàng '.$number]);

        return app(SalesInvoiceService::class)->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'invoice_number' => $number,
            'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22', 'due_date' => '2026-09-22',
            'lines' => [['description' => 'Hàng hóa', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '10', 'credit_account' => '5111', 'tax_account' => '33311']],
        ]);
    }
}
