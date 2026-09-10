<?php

namespace Tests\Feature;

use App\Models\AccountingDimensionDefinition;
use App\Models\AccountingDimensionValue;
use App\Models\AccountingDocumentDimensionAssignment;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingDimensionRequirementService;
use App\Services\AccountingDocumentDimensionAssignmentService;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\PurchaseInvoiceDimensionReadinessService;
use App\Services\PurchaseInvoiceDimensionSelectionContextService;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class AccountingDocumentDimensionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $actor;
    private AccountingDimensionValue $value;
    private PurchaseInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_purchase_invoice_posting_policy', false);
        config()->set('accounting.enforce_purchase_invoice_posting_approval', false);
        $this->company = Company::create(['name' => 'Document dimensions', 'tax_code' => 'DOC-DIM-001']);
        FiscalYear::withoutGlobalScope('company')->create(['company_id' => $this->company->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
        foreach ([['code' => '1561', 'name' => 'Goods'], ['code' => '331', 'name' => 'Payable']] as $account) {
            ChartOfAccount::create(['company_id' => $this->company->id, ...$account, 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
        $definition = AccountingDimensionDefinition::create(['company_id' => $this->company->id, 'code' => 'cost_center', 'name' => 'Cost center', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $this->value = AccountingDimensionValue::create(['company_id' => $this->company->id, 'accounting_dimension_definition_id' => $definition->id, 'code' => 'CC-01', 'name' => 'Head office', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $this->approvedPolicy($definition->id);
        $supplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'SUP-DIM', 'name' => 'Dimension supplier']);
        $this->invoice = app(PurchaseInvoiceService::class)->create(['company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'DIM-ASSIGN-1', 'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22', 'lines' => [['description' => 'dimension evidence', 'debit_account' => '1561', 'credit_account' => '331', 'quantity' => '1', 'unit_price' => '100.00']]]);
    }

    public function test_explicit_complete_selection_creates_immutable_revision_and_makes_readiness_ready(): void
    {
        $service = app(AccountingDocumentDimensionAssignmentService::class);
        $first = $service->savePurchaseInvoice($this->actor, $this->invoice->id, ['cost_center' => $this->value->id]);
        $second = $service->savePurchaseInvoice($this->actor, $this->invoice->id, ['cost_center' => $this->value->id]);

        $this->assertSame(1, $first->sole()->revision);
        $this->assertSame(2, $second->sole()->revision);
        $this->assertSame(2, AccountingDocumentDimensionAssignment::withoutGlobalScope('company')->count());
        $this->assertSame('ready', app(PurchaseInvoiceDimensionReadinessService::class)->inspect($this->invoice->fresh())['status']);

        $this->expectException(LogicException::class);
        $first->sole()->update(['dimension_code' => 'tampered']);
    }

    public function test_it_refuses_cross_tenant_or_wrong_dimension_value_evidence(): void
    {
        $other = Company::create(['name' => 'Other document dimension', 'tax_code' => 'DOC-DIM-002']);
        $foreignDefinition = AccountingDimensionDefinition::create(['company_id' => $other->id, 'code' => 'cost_center', 'name' => 'Other', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $foreignValue = AccountingDimensionValue::create(['company_id' => $other->id, 'accounting_dimension_definition_id' => $foreignDefinition->id, 'code' => 'OTHER', 'name' => 'Other', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);

        $this->expectException(ValidationException::class);
        app(AccountingDocumentDimensionAssignmentService::class)->savePurchaseInvoice($this->actor, $this->invoice->id, ['cost_center' => $foreignValue->id]);
    }

    public function test_it_refuses_new_snapshots_after_posting_without_turning_on_a_dimension_posting_gate(): void
    {
        $this->invoice->forceFill(['is_posted' => true])->save();

        $this->expectException(ValidationException::class);
        app(AccountingDocumentDimensionAssignmentService::class)->savePurchaseInvoice($this->actor, $this->invoice->id, ['cost_center' => $this->value->id]);
    }

    public function test_selection_context_only_exposes_effective_tenant_values_declared_by_the_policy(): void
    {
        $context = app(PurchaseInvoiceDimensionSelectionContextService::class)->inspect($this->invoice);

        $this->assertSame('available', $context['status']);
        $this->assertSame('2026-08-22', $context['posting_date']);
        $this->assertSame('cost_center', $context['required_dimensions'][0]['code']);
        $this->assertSame($this->value->id, $context['required_dimensions'][0]['values'][0]['id']);
        $this->assertSame('CC-01', $context['required_dimensions'][0]['values'][0]['code']);
    }

    private function approvedPolicy(int $definitionId): void
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->firstOrFail();
        $lifecycle = app(AccountingPolicyLifecycleService::class);
        $policy = $lifecycle->createDraft($this->actor, ['company_id' => $this->company->id, 'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id, 'policy_key' => 'posting.purchase_invoice', 'policy_version' => 'document-dim-v1', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'posting_rule_contract' => ['mapping_reference' => 'approved'], 'required_dimensions' => ['cost_center'], 'regulatory_dependencies' => ['TT99/2025/TT-BTC']]);
        app(AccountingDimensionRequirementService::class)->replaceDraftRequirements($this->actor, $policy, [['accounting_dimension_definition_id' => $definitionId]]);
        $lifecycle->approve($this->actor, $policy);
    }
}
