<?php

namespace Tests\Feature;

use App\Models\AccountingDimensionDefinition;
use App\Models\AccountingDimensionValue;
use App\Models\AccountingDocumentDimensionAssignment;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingDimensionRequirementService;
use App\Services\AccountingDocumentDimensionAssignmentService;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseInvoiceDimensionPostingGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $actor;
    private AccountingDimensionDefinition $costCenter;
    private AccountingDimensionValue $costCenterValue;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_purchase_invoice_posting_policy', false);
        config()->set('accounting.enforce_purchase_invoice_posting_approval', false);
        config()->set('accounting.enforce_purchase_invoice_posting_dimensions', true);
        $this->company = Company::create(['name' => 'Purchase dimension gate', 'tax_code' => 'PUR-DIM-GATE']);
        FiscalYear::withoutGlobalScope('company')->create(['company_id' => $this->company->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
        $this->actor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('accountant', 'web'));
        $this->actingAs($this->actor);
        $this->be($this->actor);
        foreach ([['code' => '1561', 'name' => 'Goods'], ['code' => '331', 'name' => 'Payable']] as $account) {
            ChartOfAccount::create(['company_id' => $this->company->id, ...$account, 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
        $this->costCenter = AccountingDimensionDefinition::create(['company_id' => $this->company->id, 'code' => 'cost_center', 'name' => 'Cost centre', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $this->costCenterValue = AccountingDimensionValue::create(['company_id' => $this->company->id, 'accounting_dimension_definition_id' => $this->costCenter->id, 'code' => 'HQ', 'name' => 'Head office', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    }

    public function test_it_posts_only_with_the_latest_matching_snapshot_and_records_exact_lineage(): void
    {
        $policy = $this->approvedPolicy([$this->costCenter]);
        $invoice = $this->invoice('DIM-GATE-OK');
        app(AccountingDocumentDimensionAssignmentService::class)->savePurchaseInvoice($this->actor, $invoice->id, ['cost_center' => $this->costCenterValue->id]);

        $posted = app(PurchaseInvoiceService::class)->post($invoice->id);

        $this->assertTrue($posted->is_posted);
        $audit = AuditLog::withoutGlobalScope('company')->where('action', 'purchase_invoice.dimensions_applied')->sole();
        $lineage = $audit->metadata['dimensions'];
        $this->assertSame($policy->id, $lineage['policy_id']);
        $this->assertSame($policy->contract_hash, $lineage['policy_contract_hash']);
        $this->assertSame(1, $lineage['assignment_revision']);
        $this->assertSame($this->costCenterValue->id, $lineage['dimension_values'][0]['dimension_value_id']);
        $this->assertSame(hash('sha256', json_encode($lineage['dimension_values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $lineage['dimension_evidence_hash']);
    }

    public function test_it_fails_closed_for_a_stale_policy_snapshot(): void
    {
        $policy = $this->approvedPolicy([$this->costCenter]);
        $invoice = $this->invoice('DIM-GATE-STALE');
        $saved = app(AccountingDocumentDimensionAssignmentService::class)->savePurchaseInvoice($this->actor, $invoice->id, ['cost_center' => $this->costCenterValue->id])->sole();
        $this->appendSnapshot($invoice->id, 2, $policy->id, str_repeat('0', 64), $this->costCenter, $this->costCenterValue);

        try {
            app(PurchaseInvoiceService::class)->post($invoice->id);
            $this->fail('Stale dimension policy evidence must block posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dimensions', $exception->errors());
        }
        $this->assertFalse($invoice->fresh()->is_posted);
        $this->assertSame(1, AccountingDocumentDimensionAssignment::withoutGlobalScope('company')->where('revision', 1)->count());
        $this->assertSame($policy->contract_hash, $saved->policy_contract_hash);
    }

    public function test_it_fails_closed_when_a_required_dimension_is_missing(): void
    {
        $project = AccountingDimensionDefinition::create(['company_id' => $this->company->id, 'code' => 'project', 'name' => 'Project', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $policy = $this->approvedPolicy([$this->costCenter, $project]);
        $invoice = $this->invoice('DIM-GATE-MISSING');
        $this->appendSnapshot($invoice->id, 1, $policy->id, $policy->contract_hash, $this->costCenter, $this->costCenterValue);

        $this->expectException(ValidationException::class);
        app(PurchaseInvoiceService::class)->post($invoice->id);
    }

    public function test_it_fails_closed_for_cross_tenant_dimension_evidence_and_posted_evidence_cannot_be_replaced(): void
    {
        $policy = $this->approvedPolicy([$this->costCenter]);
        $invoice = $this->invoice('DIM-GATE-FOREIGN');
        $other = Company::create(['name' => 'Foreign dimension tenant', 'tax_code' => 'PUR-DIM-FOREIGN']);
        $foreignDefinition = AccountingDimensionDefinition::create(['company_id' => $other->id, 'code' => 'cost_center', 'name' => 'Foreign', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $foreignValue = AccountingDimensionValue::create(['company_id' => $other->id, 'accounting_dimension_definition_id' => $foreignDefinition->id, 'code' => 'X', 'name' => 'Foreign X', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $this->appendSnapshot($invoice->id, 1, $policy->id, $policy->contract_hash, $foreignDefinition, $foreignValue);

        try {
            app(PurchaseInvoiceService::class)->post($invoice->id);
            $this->fail('Cross-tenant evidence must block posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dimensions', $exception->errors());
        }
        $this->assertFalse($invoice->fresh()->is_posted);

        $validInvoice = $this->invoice('DIM-GATE-IMMUTABLE');
        app(AccountingDocumentDimensionAssignmentService::class)->savePurchaseInvoice($this->actor, $validInvoice->id, ['cost_center' => $this->costCenterValue->id]);
        app(PurchaseInvoiceService::class)->post($validInvoice->id);
        $this->expectException(ValidationException::class);
        app(AccountingDocumentDimensionAssignmentService::class)->savePurchaseInvoice($this->actor, $validInvoice->id, ['cost_center' => $this->costCenterValue->id]);
    }

    /** @param list<AccountingDimensionDefinition> $definitions */
    private function approvedPolicy(array $definitions)
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->firstOrFail();
        $lifecycle = app(AccountingPolicyLifecycleService::class);
        $policy = $lifecycle->createDraft($this->actor, ['company_id' => $this->company->id, 'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id, 'policy_key' => 'posting.purchase_invoice', 'policy_version' => 'dimension-gate-'.uniqid(), 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'posting_rule_contract' => ['mapping_reference' => 'approved'], 'required_dimensions' => array_map(fn ($definition) => $definition->code, $definitions), 'regulatory_dependencies' => ['TT99/2025/TT-BTC']]);
        app(AccountingDimensionRequirementService::class)->replaceDraftRequirements($this->actor, $policy, array_map(fn ($definition) => ['accounting_dimension_definition_id' => $definition->id], $definitions));
        return $lifecycle->approve($this->actor, $policy);
    }

    private function invoice(string $number)
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'code' => $number, 'name' => 'Supplier '.$number]);
        return app(PurchaseInvoiceService::class)->create(['company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'invoice_number' => $number, 'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22', 'lines' => [['description' => 'Dimension control', 'debit_account' => '1561', 'credit_account' => '331', 'quantity' => '1', 'unit_price' => '100.00']]]);
    }

    private function appendSnapshot(int $invoiceId, int $revision, int $policyId, string $hash, AccountingDimensionDefinition $definition, AccountingDimensionValue $value): void
    {
        AccountingDocumentDimensionAssignment::withoutGlobalScope('company')->create(['company_id' => $this->company->id, 'accounting_document_type' => \App\Models\PurchaseInvoice::class, 'accounting_document_id' => $invoiceId, 'revision' => $revision, 'accounting_policy_version_id' => $policyId, 'policy_contract_hash' => $hash, 'posting_date' => '2026-08-22', 'dimension_code' => 'cost_center', 'accounting_dimension_definition_id' => $definition->id, 'accounting_dimension_value_id' => $value->id, 'selected_by' => $this->actor->id, 'selected_at' => now(), 'metadata' => ['test_only' => true]]);
    }
}
