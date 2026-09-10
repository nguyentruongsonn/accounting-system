<?php

namespace Tests\Feature;

use App\Models\ApprovedAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\SettlementAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\CommercialAdjustmentAccountMappingPostingGate;
use App\Services\PurchaseReturnService;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommercialAdjustmentPostingAccountMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_return_posts_with_owner_approved_accounts_and_audits_lineage(): void
    {
        TwoRolePermissions::seed();
        $actor = User::factory()->create(['company_id' => 1]);
        $actor->assignRole('accountant');
        Sanctum::actingAs($actor);
        config()->set('accounting.enforce_return_discount_posting_account_mappings', true);

        foreach (['331', '1561', '9991', '9992'] as $code) {
            ChartOfAccount::create([
                'company_id' => 1,
                'code' => $code,
                'name' => 'Mapping '.$code,
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $return = PurchaseReturn::create([
            'company_id' => 1,
            'voucher_type' => 'purchase_return',
            'voucher_number' => 'PR-MAPPED-001',
            'voucher_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'payment_method' => 'reduce_payable',
            'sub_total' => '100.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
            'grand_total' => '100.00',
            'status' => 'draft',
            'is_posted' => false,
            'is_outward' => true,
            'is_export_slip' => true,
        ]);
        $line = PurchaseReturnLine::create([
            'purchase_return_id' => $return->id,
            'item_id' => null,
            'description' => 'Mapped return',
            'amount' => '100.00',
            'quantity' => '1.0000',
            'unit_price' => '100.00',
            'debit_account' => '331',
            'credit_account' => '1561',
            'tax_amount' => '0.00',
        ]);

        $policy = $this->approvePolicy($actor);
        $checker = User::factory()->create(['company_id' => 1]);
        $mappings = app(ApprovedAccountMappingLifecycleService::class);
        foreach ([
            ['role' => 'settlement_debit', 'source' => '331', 'target' => '9991'],
            ['role' => 'inventory_credit', 'source' => '1561', 'target' => '9992'],
        ] as $definition) {
            $draft = $mappings->createDraft($actor, [
                'company_id' => 1,
                'accounting_policy_version_id' => $policy->id,
                'mapping_key' => 'purchase.return',
                'mapping_context' => CommercialAdjustmentAccountMappingPostingGate::contextFor(
                    $return,
                    $definition['role'] === 'settlement_debit' ? null : $line,
                    $definition['role'],
                    $definition['source'],
                ),
                'account_role' => $definition['role'],
                'account_code' => $definition['target'],
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
                'regulatory_dependencies' => [],
            ]);
            $mappings->approve($checker, $draft);
        }

        $stored = ApprovedAccountMapping::withoutGlobalScope('company')->where('mapping_key', 'purchase.return')->where('account_role', 'settlement_debit')->firstOrFail();
        $this->assertSame((int) $policy->id, (int) $stored->accounting_policy_version_id);
        $this->assertSame('approved', $stored->status);
        $this->assertNotNull($stored->approved_at);
        $this->assertSame(
            $stored->context_hash,
            ApprovedAccountMapping::contextHash(CommercialAdjustmentAccountMappingPostingGate::contextFor($return, null, 'settlement_debit', '331')),
        );
        $this->assertSame(1, ApprovedAccountMapping::withoutGlobalScope('company')
            ->where('company_id', 1)->where('accounting_policy_version_id', $policy->id)
            ->where('mapping_key', 'purchase.return')->where('context_hash', $stored->context_hash)
            ->where('account_role', 'settlement_debit')->where('status', 'approved')
            ->whereNotNull('approved_at')->whereNotNull('contract_hash')->whereDate('effective_from', '<=', '2026-08-20')->whereDate('effective_to', '>=', '2026-08-20')->count());
        app(CommercialAdjustmentAccountMappingPostingGate::class)->requireSatisfied($return->fresh('lines'), [
            'policy_id' => $policy->id,
            'contract_hash' => $policy->contract_hash,
        ]);

        $posted = app(PurchaseReturnService::class)->post($return->id);

        $this->assertTrue($posted->is_posted);
        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $posted->journal_entry_id, 'account_code' => '9991']);
        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $posted->journal_entry_id, 'account_code' => '9992']);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => PurchaseReturn::class,
            'model_id' => $return->id,
            'action' => 'purchase_return.account_mappings_applied',
        ]);
    }

    public function test_debt_reducing_posted_return_creates_one_canonical_allocation_and_blocks_unpost(): void
    {
        TwoRolePermissions::seed();
        $actor = User::factory()->create(['company_id' => 1]);
        $actor->assignRole('accountant');
        Sanctum::actingAs($actor);
        config()->set('accounting.enforce_return_discount_posting_account_mappings', false);

        foreach (['331', '1561'] as $code) {
            ChartOfAccount::create([
                'company_id' => 1,
                'code' => $code,
                'name' => 'Legacy '.$code,
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP-ALLOC-001', 'name' => 'Allocation supplier', 'is_active' => true]);
        $invoice = PurchaseInvoice::create([
            'company_id' => 1,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'PI-ALLOC-001',
            'invoice_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'total_amount' => '100.00',
            'status' => 'posted',
            'is_posted' => true,
        ]);
        $return = PurchaseReturn::create([
            'company_id' => 1,
            'supplier_id' => $supplier->id,
            'voucher_type' => 'purchase_return',
            'voucher_number' => 'PR-ALLOC-001',
            'voucher_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'payment_method' => 'reduce_payable',
            'sub_total' => '100.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
            'grand_total' => '100.00',
            'is_posted' => false,
            'is_outward' => false,
            'is_decrease_debt' => true,
            'reference_invoice_id' => $invoice->id,
            'status' => 'draft',
        ]);
        PurchaseReturnLine::create([
            'purchase_return_id' => $return->id,
            'description' => 'Allocation return',
            'amount' => '100.00',
            'quantity' => '1.0000',
            'unit_price' => '100.00',
            'debit_account' => '331',
            'credit_account' => '1561',
            'tax_amount' => '0.00',
        ]);

        $posted = app(PurchaseReturnService::class)->post($return->id);

        $allocation = SettlementAllocation::withoutGlobalScope('company')
            ->where('source_document_type', 'purchase_return')
            ->where('source_document_id', $posted->id)
            ->firstOrFail();
        $this->assertSame('100.00', (string) $allocation->amount_raw);
        $this->assertSame(2, (int) $allocation->amount_scale);
        $this->assertSame('purchase_invoice', $allocation->target_document_type);
        $this->assertSame((int) $invoice->id, (int) $allocation->target_document_id);
        $this->assertSame(1, SettlementAllocation::withoutGlobalScope('company')->where('source_document_id', $posted->id)->count());

        $this->expectException(ValidationException::class);
        app(PurchaseReturnService::class)->unpost($posted->id);
    }

    public function test_debt_reducing_return_rolls_back_journal_and_source_when_target_capacity_is_exceeded(): void
    {
        TwoRolePermissions::seed();
        $actor = User::factory()->create(['company_id' => 1]);
        $actor->assignRole('accountant');
        Sanctum::actingAs($actor);
        config()->set('accounting.enforce_return_discount_posting_account_mappings', false);

        foreach (['331', '1561'] as $code) {
            ChartOfAccount::create([
                'company_id' => 1,
                'code' => $code,
                'name' => 'Rollback '.$code,
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $supplier = Supplier::create(['company_id' => 1, 'code' => 'SUP-ALLOC-ROLLBACK', 'name' => 'Rollback supplier', 'is_active' => true]);
        $invoice = PurchaseInvoice::create([
            'company_id' => 1,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'PI-ALLOC-ROLLBACK',
            'invoice_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'total_amount' => '100.00',
            'status' => 'posted',
            'is_posted' => true,
        ]);

        $first = $this->createDebtReducingReturn($supplier->id, $invoice->id, 'PR-ALLOC-ROLLBACK-001', '60.00');
        $second = $this->createDebtReducingReturn($supplier->id, $invoice->id, 'PR-ALLOC-ROLLBACK-002', '50.00');
        $service = app(PurchaseReturnService::class);

        $posted = $service->post($first->id);
        $this->assertTrue($posted->fresh()->is_posted);
        $this->assertDatabaseHas('settlement_allocations', [
            'source_document_type' => 'purchase_return',
            'source_document_id' => $first->id,
            'target_document_id' => $invoice->id,
            'amount_raw' => '60.00',
        ]);

        try {
            $service->post($second->id);
            $this->fail('The second return should exceed the invoice allocation capacity.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('exceeds the posted invoice amount', $exception->getMessage());
        }

        $rolledBack = $second->fresh();
        $this->assertFalse((bool) $rolledBack->is_posted);
        $this->assertSame('draft', $rolledBack->status);
        $this->assertNull($rolledBack->journal_entry_id);
        $this->assertDatabaseMissing('journal_entries', [
            'source_document_type' => PurchaseReturn::class,
            'source_document_id' => $second->id,
        ]);
        $this->assertSame(1, SettlementAllocation::withoutGlobalScope('company')
            ->where('target_document_type', 'purchase_invoice')
            ->where('target_document_id', $invoice->id)
            ->where('status', 'posted')
            ->count());
    }

    private function createDebtReducingReturn(int $supplierId, int $invoiceId, string $voucherNumber, string $amount): PurchaseReturn
    {
        $return = PurchaseReturn::create([
            'company_id' => 1,
            'supplier_id' => $supplierId,
            'voucher_type' => 'purchase_return',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'payment_method' => 'reduce_payable',
            'sub_total' => $amount,
            'tax_amount' => '0.00',
            'total_amount' => $amount,
            'grand_total' => $amount,
            'is_posted' => false,
            'is_outward' => false,
            'is_decrease_debt' => true,
            'reference_invoice_id' => $invoiceId,
            'status' => 'draft',
        ]);
        PurchaseReturnLine::create([
            'purchase_return_id' => $return->id,
            'description' => 'Rollback return',
            'amount' => $amount,
            'quantity' => '1.0000',
            'unit_price' => $amount,
            'debit_account' => '331',
            'credit_account' => '1561',
            'tax_amount' => '0.00',
        ]);

        return $return->load('lines');
    }

    private function approvePolicy(User $maker): object
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', 1)->where('year', 2026)->firstOrFail();
        $lifecycle = app(AccountingPolicyLifecycleService::class);
        $draft = $lifecycle->createDraft($maker, [
            'company_id' => 1,
            'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.purchase_return',
            'policy_version' => 'commercial-adjustment-map-v1',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'owner-approved-commercial-adjustment-map'],
            'required_dimensions' => [],
            'regulatory_dependencies' => [],
        ]);

        return $lifecycle->approve(User::factory()->create(['company_id' => 1]), $draft);
    }
}
