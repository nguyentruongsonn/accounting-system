<?php

namespace Tests\Feature;

use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoicePostingPolicyGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_purchase_invoice_posting_policy', true);
        config()->set('accounting.enforce_purchase_invoice_posting_approval', false);
        config()->set('accounting.enforce_purchase_invoice_posting_dimensions', false);
        config()->set('accounting.enforce_purchase_invoice_posting_account_mappings', false);

        $this->company = Company::create(['name' => 'Policy posting tenant', 'tax_code' => 'PURCHASE-POLICY']);
        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
        $this->configureAccountingTenant($this->actor, $this->company);
        $this->actor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('accountant', 'web'));
        $this->actingAs($this->actor);

        foreach ([
            ['code' => '1561', 'name' => 'Hàng hóa'],
            ['code' => '331', 'name' => 'Phải trả người bán'],
            ['code' => '1331', 'name' => 'Thuế GTGT được khấu trừ'],
        ] as $account) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                ...$account,
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }
    }

    public function test_purchase_posting_fails_closed_when_no_approved_policy_covers_its_date(): void
    {
        $invoice = $this->newInvoice('POLICY-MISSING');

        try {
            app(PurchaseInvoiceService::class)->post($invoice->id);
            $this->fail('A missing policy must block canonical purchase posting.');
        } catch (AccountingPolicyUnavailableException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoice->id, 'is_posted' => false]);
        $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $invoice->id]);
    }

    public function test_purchase_posting_requires_approved_policy_and_records_immutable_policy_lineage(): void
    {
        $year = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('year', 2026)
            ->orderByDesc('id')
            ->firstOrFail();
        $profile = $year->accountingRegimeProfile()->firstOrFail();
        $policy = app(AccountingPolicyLifecycleService::class)->approve($this->actor,
            app(AccountingPolicyLifecycleService::class)->createDraft($this->actor, [
                'company_id' => $this->company->id,
                'accounting_regime_profile_id' => $profile->id,
                'policy_key' => 'posting.purchase_invoice',
                'policy_version' => '2026.1',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
                'posting_rule_contract' => ['mapping_reference' => 'tenant-approved-purchase-map-v1'],
                'required_dimensions' => ['supplier'],
                'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
            ])
        );

        $posted = app(PurchaseInvoiceService::class)->post($this->newInvoice('POLICY-APPROVED')->id);

        $this->assertTrue($posted->is_posted);
        $this->assertNotNull($posted->journal_entry_id);
        $audits = AuditLog::withoutGlobalScope('company')
            ->where('action', 'purchase_invoice.policy_applied')
            ->where('model_id', $posted->id)
            ->get();
        $this->assertCount(1, $audits);
        $audit = $audits->first();
        $this->assertSame('enforced', $audit->metadata['accounting_policy_gate']);
        $this->assertSame($policy->id, $audit->metadata['accounting_policy']['policy_id']);
        $this->assertSame($policy->contract_hash, $audit->metadata['accounting_policy']['contract_hash']);
    }

    private function newInvoice(string $number)
    {
        $supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => $number,
            'name' => 'Nhà cung cấp '.$number,
        ]);

        return app(PurchaseInvoiceService::class)->create([
            'company_id' => $this->company->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-08-22',
            'accounting_date' => '2026-08-22',
            'due_date' => '2026-09-22',
            'lines' => [[
                'description' => 'Hàng hóa kiểm tra policy',
                'debit_account' => '1561',
                'credit_account' => '331',
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '10',
                'tax_account' => '1331',
            ]],
        ]);
    }
}
