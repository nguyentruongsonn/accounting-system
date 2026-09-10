<?php

namespace Tests\Feature;

use App\Models\AccountingDimensionDefinition;
use App\Models\AccountingDimensionValue;
use App\Models\AccountingDocumentDimensionAssignment;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\AccountingDimensionRequirementService;
use App\Services\AccountingDocumentDimensionAssignmentService;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\SalesInvoiceDimensionReadinessService;
use App\Services\SalesInvoiceDimensionSelectionContextService;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class SalesInvoiceDimensionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $actor;
    private SalesInvoice $invoice;
    private AccountingDimensionValue $value;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_sales_invoice_posting_policy', false);
        config()->set('accounting.enforce_sales_invoice_posting_approval', false);
        config()->set('accounting.enforce_sales_invoice_posting_account_mappings', false);
        config()->set('accounting.enforce_sales_invoice_posting_dimensions', false);
        $this->company = Company::create(['name' => 'Sales dimension tenant', 'tax_code' => 'SALE-DIM-001']);
        FiscalYear::withoutGlobalScope('company')->create(['company_id' => $this->company->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
        $this->configureAccountingTenant($this->actor, $this->company);
        $this->actor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('accountant', 'web'));
        $this->actingAs($this->actor);
        foreach ([['code' => '131', 'type' => 'asset', 'nature' => 'debit'], ['code' => '5111', 'type' => 'revenue', 'nature' => 'credit'], ['code' => '33311', 'type' => 'liability', 'nature' => 'credit']] as $account) {
            ChartOfAccount::create(['company_id' => $this->company->id, 'name' => $account['code'], ...$account, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
        $definition = AccountingDimensionDefinition::create(['company_id' => $this->company->id, 'code' => 'sales_channel', 'name' => 'Sales channel', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $this->value = AccountingDimensionValue::create(['company_id' => $this->company->id, 'accounting_dimension_definition_id' => $definition->id, 'code' => 'ONLINE', 'name' => 'Online', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $this->approvePolicy($definition->id);
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => 'SALES-DIM-CUST', 'name' => 'No inference customer']);
        $this->invoice = app(SalesInvoiceService::class)->create(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'invoice_number' => 'SALES-DIM-1', 'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22', 'lines' => [['description' => 'No inference item', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '10', 'credit_account' => '5111', 'tax_account' => '33311']]]);
    }

    public function test_explicit_sales_snapshot_is_effective_append_only_and_makes_readiness_ready(): void
    {
        $service = app(AccountingDocumentDimensionAssignmentService::class);
        $first = $service->saveSalesInvoice($this->actor, $this->invoice->id, ['sales_channel' => $this->value->id]);
        $second = $service->saveSalesInvoice($this->actor, $this->invoice->id, ['sales_channel' => $this->value->id]);
        $this->assertSame(1, $first->sole()->revision);
        $this->assertSame(2, $second->sole()->revision);
        $this->assertSame('ready', app(SalesInvoiceDimensionReadinessService::class)->inspect($this->invoice->fresh())['status']);
        $this->expectException(LogicException::class);
        $first->sole()->update(['dimension_code' => 'tampered']);
    }

    public function test_context_never_infers_customer_or_line_values_and_posted_invoice_is_read_only(): void
    {
        $context = app(SalesInvoiceDimensionSelectionContextService::class)->inspect($this->invoice);
        $this->assertSame('available', $context['status']);
        $this->assertSame('sales_channel', $context['required_dimensions'][0]['code']);
        $this->assertSame($this->value->id, $context['required_dimensions'][0]['values'][0]['id']);
        $this->invoice->forceFill(['is_posted' => true])->save();
        $this->expectException(ValidationException::class);
        app(AccountingDocumentDimensionAssignmentService::class)->saveSalesInvoice($this->actor, $this->invoice->id, ['sales_channel' => $this->value->id]);
    }

    public function test_controlled_posting_gate_revalidates_snapshot_and_records_lineage(): void
    {
        config()->set('accounting.enforce_sales_invoice_posting_dimensions', true);
        try {
            app(SalesInvoiceService::class)->post($this->invoice->id);
            $this->fail('Posting without explicit dimension evidence must fail closed.');
        } catch (ValidationException) {
            $this->assertFalse($this->invoice->fresh()->is_posted);
        }

        app(AccountingDocumentDimensionAssignmentService::class)->saveSalesInvoice($this->actor, $this->invoice->id, ['sales_channel' => $this->value->id]);
        $posted = app(SalesInvoiceService::class)->post($this->invoice->id);
        $this->assertTrue($posted->is_posted);
        $audit = AuditLog::withoutGlobalScope('company')->where('model_type', SalesInvoice::class)->where('model_id', $posted->id)->where('action', 'sales_invoice.dimensions_applied')->sole();
        $this->assertSame('satisfied', $audit->metadata['status']);
        $this->assertSame($this->value->id, $audit->metadata['dimension_values'][0]['dimension_value_id']);
    }

    private function approvePolicy(int $definitionId): void
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->firstOrFail();
        $lifecycle = app(AccountingPolicyLifecycleService::class);
        $policy = $lifecycle->createDraft($this->actor, ['company_id' => $this->company->id, 'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id, 'policy_key' => 'posting.sales_invoice', 'policy_version' => 'sales-dim-v1', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'posting_rule_contract' => ['mapping_reference' => 'approved'], 'required_dimensions' => ['sales_channel'], 'regulatory_dependencies' => ['TT99/2025/TT-BTC']]);
        app(AccountingDimensionRequirementService::class)->replaceDraftRequirements($this->actor, $policy, [['accounting_dimension_definition_id' => $definitionId]]);
        $lifecycle->approve($this->actor, $policy);
    }
}
