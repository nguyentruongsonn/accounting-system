<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryMovementEvent;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\InventoryValuationRun;
use App\Models\Item;
use App\Models\OpeningBalanceInventoryLine;
use App\Models\OpeningBalancePackage;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryValuationRunInvalidator;
use App\Services\InventoryValuationService;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryValuationTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_stock_purchase_price_fallback_is_tenant_scoped(): void
    {
        $companyA = Company::create(['name' => 'Valuation tenant A']);
        $companyB = Company::create(['name' => 'Valuation tenant B']);
        $itemB = Item::create([
            'company_id' => $companyB->id,
            'type' => 'Goods',
            'code' => 'VALUATION-TENANT-B',
            'name' => 'Tenant B item',
            'purchase_price' => 950,
        ]);

        $cost = app(InventoryValuationService::class)->getMovingAverageCost($itemB->id, $companyA->id);

        $this->assertEquals(0, $cost);
    }

    public function test_valuation_requires_an_explicit_company_context(): void
    {
        $this->expectException(ValidationException::class);

        app(InventoryValuationService::class)->getMovingAverageCost(1);
    }

    public function test_authenticated_valuation_cannot_request_a_foreign_company(): void
    {
        $companyA = Company::create(['name' => 'Valuation actor tenant']);
        $companyB = Company::create(['name' => 'Valuation foreign tenant']);
        $actor = User::factory()->create(['company_id' => $companyA->id]);
        Sanctum::actingAs($actor);

        $this->expectException(ValidationException::class);

        app(InventoryValuationService::class)->getMovingAverageCost(1, $companyB->id);
    }

    public function test_moving_average_does_not_treat_a_purchase_invoice_as_a_second_stock_receipt(): void
    {
        $company = Company::create(['name' => 'Valuation movement source']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'VALUATION-SOURCE', 'name' => 'Valuation source item']);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'VALUATION-SUPPLIER', 'name' => 'Valuation supplier']);
        $receipt = InventoryReceipt::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-RECEIPT', 'voucher_date' => '2026-01-10', 'total_amount' => '10.00', 'is_posted' => true]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '10.0000', 'amount' => '10.00']);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'VALUATION-INVOICE', 'invoice_date' => '2026-01-10', 'total_amount' => '20.00', 'is_posted' => true]);
        PurchaseInvoiceLine::create(['purchase_invoice_id' => $invoice->id, 'item_id' => $item->id, 'debit_account' => '1561', 'quantity' => '1.00', 'unit_price' => '20.0000', 'amount' => '20.00']);

        $cost = app(InventoryValuationService::class)->getMovingAverageCost($item->id, $company->id, '2026-01-31');

        $this->assertSame(10.0, $cost);
    }

    public function test_exact_moving_average_uses_posting_date_and_preserves_four_decimal_rate(): void
    {
        $company = Company::create(['name' => 'Valuation exact posting date']);
        $item = Item::create([
            'company_id' => $company->id,
            'type' => 'Goods',
            'code' => 'VALUATION-EXACT-RATE',
            'name' => 'Exact valuation item',
        ]);
        $receipt = InventoryReceipt::create([
            'company_id' => $company->id,
            'voucher_number' => 'VALUATION-EXACT-RECEIPT',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-09-01',
            'total_amount' => '10.00',
            'is_posted' => true,
        ]);
        InventoryReceiptLine::create([
            'inventory_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'quantity' => '3.00',
            'unit_price' => '3.3333',
            'amount' => '10.00',
        ]);

        $valuation = app(InventoryValuationService::class);

        $this->assertSame('0.0000', $valuation->getMovingAverageCostExact($item->id, $company->id, '2026-08-31'));
        $this->assertSame('3.3333', $valuation->getMovingAverageCostExact($item->id, $company->id, '2026-09-01'));
    }

    public function test_month_end_weighted_average_uses_only_posted_inventory_receipts_as_inward_stock(): void
    {
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        $company = Company::create(['name' => 'Valuation monthly movement source']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'VALUATION-MONTHLY-SOURCE', 'name' => 'Monthly valuation source item']);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'VALUATION-MONTHLY-SUPPLIER', 'name' => 'Monthly valuation supplier']);
        $receipt = InventoryReceipt::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-MONTHLY-RECEIPT', 'voucher_date' => '2026-01-10', 'total_amount' => '10.00', 'is_posted' => true]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '10.0000', 'amount' => '10.00']);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'VALUATION-MONTHLY-INVOICE', 'invoice_date' => '2026-01-10', 'total_amount' => '20.00', 'is_posted' => true]);
        PurchaseInvoiceLine::create(['purchase_invoice_id' => $invoice->id, 'item_id' => $item->id, 'debit_account' => '1561', 'quantity' => '1.00', 'unit_price' => '20.0000', 'amount' => '20.00']);
        $issue = InventoryIssue::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-MONTHLY-ISSUE', 'voucher_date' => '2026-01-20', 'posting_date' => '2026-01-20', 'total_amount' => '0.00', 'status' => 'posted', 'is_posted' => true]);
        $issueLine = InventoryIssueLine::create(['inventory_issue_id' => $issue->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '0.0000', 'amount' => '0.00', 'debit_account' => '632', 'credit_account' => '1561']);

        app(InventoryValuationService::class)->runCostCalculation(['company_id' => $company->id, 'from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'method' => 'weighted_average']);

        $this->assertSame('10.00', (string) $issueLine->fresh()->amount);
    }

    public function test_fifo_uses_only_posted_inventory_receipts_as_inward_stock(): void
    {
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        $company = Company::create(['name' => 'Valuation FIFO movement source']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'VALUATION-FIFO-SOURCE', 'name' => 'FIFO source item']);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'VALUATION-FIFO-SUPPLIER', 'name' => 'FIFO supplier']);
        $receipt = InventoryReceipt::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-FIFO-RECEIPT', 'voucher_date' => '2026-01-10', 'total_amount' => '10.00', 'is_posted' => true]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '10.0000', 'amount' => '10.00']);
        $invoice = PurchaseInvoice::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'VALUATION-FIFO-INVOICE', 'invoice_date' => '2026-01-05', 'total_amount' => '20.00', 'is_posted' => true]);
        PurchaseInvoiceLine::create(['purchase_invoice_id' => $invoice->id, 'item_id' => $item->id, 'debit_account' => '1561', 'quantity' => '1.00', 'unit_price' => '20.0000', 'amount' => '20.00']);
        $issue = InventoryIssue::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-FIFO-ISSUE', 'voucher_date' => '2026-01-20', 'posting_date' => '2026-01-20', 'total_amount' => '0.00', 'status' => 'posted', 'is_posted' => true]);
        $issueLine = InventoryIssueLine::create(['inventory_issue_id' => $issue->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '0.0000', 'amount' => '0.00', 'debit_account' => '632', 'credit_account' => '1561']);

        app(InventoryValuationService::class)->runCostCalculation(['company_id' => $company->id, 'from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'method' => 'fifo']);

        $this->assertSame('10.00', (string) $issueLine->fresh()->amount);
    }

    public function test_fifo_refuses_to_return_an_incorrect_result_when_transfer_events_exist(): void
    {
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        $company = Company::create(['name' => 'Valuation FIFO transfer boundary']);
        $fromWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'FIFO-TRANSFER-FROM', 'name' => 'Kho nguồn']);
        $toWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'FIFO-TRANSFER-TO', 'name' => 'Kho nhận']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'FIFO-TRANSFER-ITEM', 'name' => 'Hàng FIFO chuyển kho']);
        InventoryMovementEvent::create([
            'company_id' => $company->id,
            'movement_date' => '2026-01-15',
            'warehouse_id' => $fromWarehouse->id,
            'item_id' => $item->id,
            'movement_type' => 'transfer_out',
            'quantity_delta' => '-1.0000',
            'amount_delta' => null,
            'source_type' => InventoryTransfer::class,
            'source_id' => 1001,
            'source_line_id' => 1001,
        ]);
        InventoryMovementEvent::create([
            'company_id' => $company->id,
            'movement_date' => '2026-01-15',
            'warehouse_id' => $toWarehouse->id,
            'item_id' => $item->id,
            'movement_type' => 'transfer_in',
            'quantity_delta' => '1.0000',
            'amount_delta' => null,
            'source_type' => InventoryTransfer::class,
            'source_id' => 1001,
            'source_line_id' => 1001,
        ]);

        try {
            app(InventoryValuationService::class)->runCostCalculation([
                'company_id' => $company->id,
                'from_date' => '2026-01-01',
                'to_date' => '2026-01-31',
                'method' => 'fifo',
            ]);
            $this->fail('FIFO must not ignore an unvalued transfer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inventory_transfers', $exception->errors());
        }
    }

    public function test_month_end_weighted_average_includes_confirmed_opening_inventory(): void
    {
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        $company = Company::create(['name' => 'Valuation confirmed opening']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'VALUATION-CONFIRMED-OPENING', 'name' => 'Confirmed opening item']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'VALUATION-CONFIRMED-WH', 'name' => 'Confirmed opening warehouse']);
        $opening = OpeningBalancePackage::create(['company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'confirmed']);
        OpeningBalanceInventoryLine::create(['package_id' => $opening->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'account_code' => '1561', 'quantity' => '2.0000', 'unit_cost' => '10.0000', 'total_value' => '20.00']);
        $receipt = InventoryReceipt::create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'voucher_number' => 'VALUATION-CONFIRMED-RECEIPT', 'voucher_date' => '2026-01-10', 'total_amount' => '60.00', 'is_posted' => true]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '3.00', 'unit_price' => '20.0000', 'amount' => '60.00']);
        $issue = InventoryIssue::create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'voucher_number' => 'VALUATION-CONFIRMED-ISSUE', 'voucher_date' => '2026-01-20', 'posting_date' => '2026-01-20', 'total_amount' => '0.00', 'status' => 'posted', 'is_posted' => true]);
        $issueLine = InventoryIssueLine::create(['inventory_issue_id' => $issue->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '1.00', 'unit_price' => '0.0000', 'amount' => '0.00', 'debit_account' => '632', 'credit_account' => '1561']);

        app(InventoryValuationService::class)->runCostCalculation(['company_id' => $company->id, 'from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'method' => 'weighted_average', 'warehouse_id' => $warehouse->id]);

        $this->assertSame('16.00', (string) $issueLine->fresh()->amount);
    }

    public function test_month_end_weighted_average_carries_the_source_cost_to_the_destination_warehouse_transfer(): void
    {
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        TwoRolePermissions::seed();

        $company = Company::create(['name' => 'Valuation transfer carrying cost']);
        $accountant = User::factory()->create(['company_id' => $company->id]);
        $accountant->assignRole('accountant');
        Sanctum::actingAs($accountant);
        $fromWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'VALUATION-TRANSFER-FROM', 'name' => 'Kho nguồn']);
        $toWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'VALUATION-TRANSFER-TO', 'name' => 'Kho nhận']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'VALUATION-TRANSFER-ITEM', 'name' => 'Hàng chuyển kho']);
        $opening = OpeningBalancePackage::create(['company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'confirmed']);
        OpeningBalanceInventoryLine::create([
            'package_id' => $opening->id,
            'item_id' => $item->id,
            'warehouse_id' => $fromWarehouse->id,
            'account_code' => '1561',
            'quantity' => '10.0000',
            'unit_cost' => '10.0000',
            'total_value' => '100.00',
        ]);
        $transfer = InventoryTransfer::create([
            'company_id' => $company->id,
            'transfer_number' => 'VALUATION-TRANSFER-001',
            'transfer_date' => '2026-01-15',
            'from_warehouse_id' => $fromWarehouse->id,
            'to_warehouse_id' => $toWarehouse->id,
            'status' => 'draft',
            'is_posted' => false,
        ]);
        InventoryTransferLine::create(['inventory_transfer_id' => $transfer->id, 'item_id' => $item->id, 'quantity' => '2.000000']);
        $this->postJson('/api/v1/inventory/transfers/'.$transfer->id.'/post')->assertOk();

        $issue = InventoryIssue::create([
            'company_id' => $company->id,
            'warehouse_id' => $toWarehouse->id,
            'voucher_number' => 'VALUATION-TRANSFER-ISSUE',
            'voucher_date' => '2026-01-20',
            'posting_date' => '2026-01-20',
            'total_amount' => '0.00',
            'status' => 'posted',
            'is_posted' => true,
        ]);
        $issueLine = InventoryIssueLine::create([
            'inventory_issue_id' => $issue->id,
            'item_id' => $item->id,
            'warehouse_id' => $toWarehouse->id,
            'quantity' => '2.00',
            'unit_price' => '0.0000',
            'amount' => '0.00',
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);

        app(InventoryValuationService::class)->runCostCalculation([
            'company_id' => $company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
            'warehouse_id' => $toWarehouse->id,
        ]);

        $this->assertSame('20.00', (string) $issueLine->fresh()->amount);
        $this->assertDatabaseHas('inventory_movement_events', [
            'company_id' => $company->id,
            'source_id' => $transfer->id,
            'warehouse_id' => $fromWarehouse->id,
            'movement_type' => 'transfer_out',
            'amount_delta' => '-20.00',
        ]);
        $this->assertDatabaseHas('inventory_movement_events', [
            'company_id' => $company->id,
            'source_id' => $transfer->id,
            'warehouse_id' => $toWarehouse->id,
            'movement_type' => 'transfer_in',
            'amount_delta' => '20.00',
        ]);

        app(InventoryValuationService::class)->runCostCalculation([
            'company_id' => $company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
            'warehouse_id' => $toWarehouse->id,
        ]);

        $this->assertSame('20.00', (string) $issueLine->fresh()->amount);
        $this->assertSame(2, InventoryValuationRun::where('company_id', $company->id)->count());
    }

    public function test_month_end_weighted_average_refuses_to_hide_a_negative_opening_quantity(): void
    {
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        $company = Company::create(['name' => 'Valuation negative opening']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'VALUATION-NEGATIVE-OPENING', 'name' => 'Negative opening item']);
        $priorIssue = InventoryIssue::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-NEGATIVE-PRIOR', 'voucher_date' => '2025-12-20', 'posting_date' => '2025-12-20', 'total_amount' => '20.00', 'status' => 'posted', 'is_posted' => true]);
        InventoryIssueLine::create(['inventory_issue_id' => $priorIssue->id, 'item_id' => $item->id, 'quantity' => '2.00', 'unit_price' => '10.0000', 'amount' => '20.00', 'debit_account' => '632', 'credit_account' => '1561']);
        $receipt = InventoryReceipt::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-NEGATIVE-RECEIPT', 'voucher_date' => '2026-01-10', 'total_amount' => '10.00', 'is_posted' => true]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '10.0000', 'amount' => '10.00']);

        try {
            app(InventoryValuationService::class)->runCostCalculation(['company_id' => $company->id, 'from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'method' => 'weighted_average']);
            $this->fail('Negative opening inventory must be corrected before weighted-average valuation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inventory_availability', $exception->errors());
        }
    }

    public function test_completed_cost_calculation_persists_its_tenant_scoped_run_evidence(): void
    {
        // This assertion covers run-evidence persistence for an empty period;
        // approved-mapping enforcement is exercised by the dedicated posting
        // and valuation gate tests.
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        $company = Company::create(['name' => 'Valuation run evidence']);

        $result = app(InventoryValuationService::class)->runCostCalculation([
            'company_id' => $company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('has_unverified_cost', $result['valuation_run']);
        $this->assertFalse($result['valuation_run']['has_unverified_cost']);
        $this->assertDatabaseHas('inventory_valuation_runs', [
            'company_id' => $company->id,
            'method' => 'weighted_average',
            'status' => 'completed',
        ]);
        $run = InventoryValuationRun::where('company_id', $company->id)->sole();
        $this->assertSame('2026-01-01', $run->from_date->toDateString());
        $this->assertSame('2026-01-31', $run->to_date->toDateString());
        $this->assertNotNull($run->completed_at);
    }

    public function test_backdated_inventory_movement_invalidates_affected_and_later_valuation_runs_only(): void
    {
        $company = Company::create(['name' => 'Valuation invalidation']);
        $unaffected = InventoryValuationRun::create([
            'company_id' => $company->id,
            'from_date' => '2025-12-01',
            'to_date' => '2025-12-31',
            'method' => 'weighted_average',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $affected = InventoryValuationRun::create([
            'company_id' => $company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $later = InventoryValuationRun::create([
            'company_id' => $company->id,
            'from_date' => '2026-02-01',
            'to_date' => '2026-02-28',
            'method' => 'fifo',
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        app(InventoryValuationRunInvalidator::class)
            ->invalidateForInventoryMovement($company->id, '2026-01-15', 'inventory_receipt_posted');

        $this->assertSame('completed', $unaffected->fresh()->status);
        $this->assertSame('invalidated', $affected->fresh()->status);
        $this->assertSame('inventory_receipt_posted', $affected->fresh()->invalidation_reason);
        $this->assertNotNull($affected->fresh()->invalidated_at);
        $this->assertSame('invalidated', $later->fresh()->status);
    }
}
