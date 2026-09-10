<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\PurchaseInvoiceDimensionReadinessService;
use App\Services\PurchaseInvoiceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceDimensionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_purchase_invoice_posting_policy', true);
        config()->set('accounting.enforce_purchase_invoice_posting_approval', false);
        $this->company = Company::create(['name' => 'Dimension readiness tenant', 'tax_code' => 'PURCHASE-DIM-READY']);
        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
        $this->configureAccountingTenant($this->actor, $this->company);
        // Posting is a two-role operation in production; keep this readiness
        // fixture authenticated as the canonical accountant instead of relying
        // on an implicit/roleless test principal.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actor->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->actor);
        foreach ([['code' => '1561', 'name' => 'Goods'], ['code' => '331', 'name' => 'Payable']] as $account) {
            ChartOfAccount::create(['company_id' => $this->company->id, ...$account, 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
    }

    public function test_it_reports_unavailable_without_inferring_a_dimension_from_purchase_fields(): void
    {
        $policy = $this->approvedPolicy(['cost_center']);
        $invoice = $this->newInvoice('DIM-NO-INFERENCE');

        $readiness = app(PurchaseInvoiceDimensionReadinessService::class)->inspect($invoice);

        $this->assertSame('unavailable', $readiness['status']);
        $this->assertFalse($readiness['can_enforce']);
        $this->assertSame('typed_dimension_evidence_not_supported', $readiness['reason_code']);
        $this->assertSame($policy->id, $readiness['policy_id']);
        $this->assertSame(['cost_center'], $readiness['required_dimensions']);
        $this->assertStringContainsString('No dimension value is inferred', $readiness['reason']);

        // Posting is still controlled by the approved policy, but the new
        // readiness indicator must not fabricate a dimension gate from fields
        // whose semantics are not policy-approved dimension evidence.
        $this->assertTrue(app(PurchaseInvoiceService::class)->post($invoice->id)->is_posted);
    }

    public function test_it_reports_not_required_only_for_an_explicit_empty_policy_declaration(): void
    {
        $policy = $this->approvedPolicy([]);
        $readiness = app(PurchaseInvoiceDimensionReadinessService::class)->inspect($this->newInvoice('DIM-NOT-REQUIRED'));

        $this->assertSame('not_required', $readiness['status']);
        $this->assertTrue($readiness['can_enforce']);
        $this->assertSame($policy->contract_hash, $readiness['policy_contract_hash']);
        $this->assertSame([], $readiness['required_dimensions']);
    }

    private function approvedPolicy(array $dimensions)
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->firstOrFail();
        $lifecycle = app(AccountingPolicyLifecycleService::class);
        return $lifecycle->approve($this->actor, $lifecycle->createDraft($this->actor, [
            'company_id' => $this->company->id,
            'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.purchase_invoice',
            'policy_version' => '2026.1',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'approved-purchase-map-v1'],
            'required_dimensions' => $dimensions,
            'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ]));
    }

    private function newInvoice(string $number)
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'code' => $number, 'name' => 'Supplier '.$number]);
        return app(PurchaseInvoiceService::class)->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id,
            'invoice_number' => $number, 'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22',
            'lines' => [['description' => 'Evidence boundary', 'debit_account' => '1561', 'credit_account' => '331', 'quantity' => '1', 'unit_price' => '100.00']],
        ]);
    }
}
