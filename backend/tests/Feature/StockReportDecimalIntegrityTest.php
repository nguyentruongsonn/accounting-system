<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryMovementEvent;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\Item;
use App\Models\OpeningBalanceInventoryLine;
use App\Models\OpeningBalancePackage;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockReportDecimalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_report_adds_money_as_exact_scale_two_strings(): void
    {
        $company = Company::create(['name' => 'Stock decimal integrity']);
        $item = Item::create([
            'company_id' => $company->id,
            'type' => 'Goods',
            'code' => 'STOCK-DECIMAL-ITEM',
            'name' => 'Decimal stock item',
        ]);
        $supplier = Supplier::create([
            'company_id' => $company->id,
            'code' => 'STOCK-DECIMAL-SUPPLIER',
            'name' => 'Decimal stock supplier',
        ]);

        $receipt = InventoryReceipt::create([
            'company_id' => $company->id,
            'voucher_number' => 'STOCK-DECIMAL-RECEIPT',
            'voucher_date' => '2026-08-20',
            'total_amount' => '0.10',
            'is_posted' => true,
        ]);
        InventoryReceiptLine::create([
            'inventory_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'quantity' => '1.00',
            'unit_price' => '0.1000',
            'amount' => '0.10',
        ]);

        $invoice = PurchaseInvoice::create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'STOCK-DECIMAL-INVOICE',
            'invoice_date' => '2026-08-20',
            'total_amount' => '0.20',
            'is_posted' => true,
        ]);
        PurchaseInvoiceLine::create([
            'purchase_invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'debit_account' => '1561',
            'quantity' => '1.00',
            'unit_price' => '0.20',
            'amount' => '0.20',
        ]);

        $row = collect(app(StockReportService::class)->generateReport($company->id))
            ->firstWhere('item_id', $item->id);

        $this->assertNotNull($row);
        // The invoice is commercial evidence only. The posted receipt is the
        // single operational inventory movement, so this purchase is not
        // counted twice.
        $this->assertSame('0.10', $row['in_amt']);
        $this->assertSame('0.10', $row['end_amt']);
    }

    public function test_stock_report_uses_posting_date_for_period_boundaries(): void
    {
        $company = Company::create(['name' => 'Stock posting date basis']);
        $item = Item::create([
            'company_id' => $company->id,
            'type' => 'Goods',
            'code' => 'STOCK-POSTING-DATE-ITEM',
            'name' => 'Posting date stock item',
        ]);
        $receipt = InventoryReceipt::create([
            'company_id' => $company->id,
            'voucher_number' => 'STOCK-POSTING-DATE-RECEIPT',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-09-01',
            'total_amount' => '10.00',
            'is_posted' => true,
        ]);
        InventoryReceiptLine::create([
            'inventory_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'quantity' => '1.0000',
            'unit_price' => '10.0000',
            'amount' => '10.00',
        ]);

        $row = collect(app(StockReportService::class)->generateReport($company->id, [
            'from_date' => '2026-09-01',
            'to_date' => '2026-09-30',
        ]))->sole();

        $this->assertSame('0.0000', $row['opening_qty']);
        $this->assertSame('1.0000', $row['in_qty']);
        $this->assertSame('1.0000', $row['end_qty']);
    }

    public function test_direct_stock_report_rejects_an_authenticated_foreign_company(): void
    {
        $companyA = Company::create(['name' => 'Stock authenticated company']);
        $companyB = Company::create(['name' => 'Stock foreign company']);
        Sanctum::actingAs(User::factory()->create(['company_id' => $companyA->id]));

        try {
            app(StockReportService::class)->generateReport($companyB->id);
            $this->fail('A direct stock-report caller must not read a foreign company.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }
    }

    public function test_stock_report_uses_confirmed_opening_and_line_warehouse_without_hiding_negative_stock(): void
    {
        $company = Company::create(['name' => 'Stock opening warehouse']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'OPEN-WH-ITEM', 'name' => 'Opening warehouse item']);
        $warehouseA = Warehouse::create(['company_id' => $company->id, 'code' => 'OPEN-WH-A', 'name' => 'Opening warehouse A']);
        $warehouseB = Warehouse::create(['company_id' => $company->id, 'code' => 'OPEN-WH-B', 'name' => 'Opening warehouse B']);
        $package = OpeningBalancePackage::create(['company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'confirmed']);
        OpeningBalanceInventoryLine::create(['package_id' => $package->id, 'item_id' => $item->id, 'warehouse_id' => $warehouseA->id, 'account_code' => '1561', 'quantity' => '2.0000', 'unit_cost' => '10.0000', 'total_value' => '20.00']);

        $issue = InventoryIssue::create(['company_id' => $company->id, 'warehouse_id' => $warehouseA->id, 'voucher_number' => 'OPEN-WH-ISSUE', 'voucher_date' => '2026-01-05', 'total_amount' => '30.00', 'is_posted' => true]);
        InventoryIssueLine::create(['inventory_issue_id' => $issue->id, 'item_id' => $item->id, 'warehouse_id' => $warehouseB->id, 'quantity' => '3.00', 'unit_price' => '10.0000', 'amount' => '30.00']);

        $a = collect(app(StockReportService::class)->generateReport($company->id, ['from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'warehouse_id' => $warehouseA->id]))->sole();
        $b = collect(app(StockReportService::class)->generateReport($company->id, ['from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'warehouse_id' => $warehouseB->id]))->sole();

        $this->assertSame('2.0000', $a['opening_qty']);
        $this->assertSame('2.0000', $a['end_qty']);
        $this->assertSame('0.0000', $b['opening_qty']);
        $this->assertSame('-3.0000', $b['end_qty']);
        $this->assertSame('-30.00', $b['end_amt']);
    }

    public function test_stock_report_excludes_draft_opening_inventory(): void
    {
        $company = Company::create(['name' => 'Draft stock opening']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'DRAFT-OPEN-ITEM', 'name' => 'Draft opening item']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'DRAFT-OPEN-WH', 'name' => 'Draft opening warehouse']);
        $package = OpeningBalancePackage::create(['company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'draft']);
        OpeningBalanceInventoryLine::create(['package_id' => $package->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'account_code' => '1561', 'quantity' => '9.0000', 'unit_cost' => '10.0000', 'total_value' => '90.00']);

        $rows = app(StockReportService::class)->generateReport($company->id, ['from_date' => '2026-01-01', 'to_date' => '2026-01-31']);

        $this->assertSame([], $rows);
    }

    public function test_stock_report_carries_transfer_values_between_warehouses(): void
    {
        $company = Company::create(['name' => 'Stock transfer values']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'TRANSFER-VALUE-ITEM', 'name' => 'Transfer value item']);
        $source = Warehouse::create(['company_id' => $company->id, 'code' => 'TRANSFER-VALUE-SOURCE', 'name' => 'Transfer value source']);
        $target = Warehouse::create(['company_id' => $company->id, 'code' => 'TRANSFER-VALUE-TARGET', 'name' => 'Transfer value target']);
        $package = OpeningBalancePackage::create(['company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'confirmed']);
        OpeningBalanceInventoryLine::create(['package_id' => $package->id, 'item_id' => $item->id, 'warehouse_id' => $source->id, 'account_code' => '1561', 'quantity' => '2.0000', 'unit_cost' => '10.0000', 'total_value' => '20.00']);

        InventoryMovementEvent::create([
            'company_id' => $company->id,
            'movement_date' => '2026-01-15',
            'warehouse_id' => $source->id,
            'item_id' => $item->id,
            'movement_type' => 'transfer_out',
            'quantity_delta' => '-2.0000',
            'amount_delta' => '-20.00',
            'source_type' => 'transfer',
            'source_id' => 1001,
            'source_line_id' => 2001,
        ]);
        InventoryMovementEvent::create([
            'company_id' => $company->id,
            'movement_date' => '2026-01-15',
            'warehouse_id' => $target->id,
            'item_id' => $item->id,
            'movement_type' => 'transfer_in',
            'quantity_delta' => '2.0000',
            'amount_delta' => '20.00',
            'source_type' => 'transfer',
            'source_id' => 1001,
            'source_line_id' => 2002,
        ]);
        InventoryMovementEvent::create([
            'company_id' => $company->id,
            'movement_date' => '2026-01-20',
            'warehouse_id' => $target->id,
            'item_id' => $item->id,
            'movement_type' => 'transfer_out',
            'quantity_delta' => '-1.0000',
            'amount_delta' => '-10.00',
            'source_type' => 'transfer',
            'source_id' => 1002,
            'source_line_id' => 2003,
        ]);

        $sourceRow = collect(app(StockReportService::class)->generateReport($company->id, ['from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'warehouse_id' => $source->id]))->sole();
        $targetRow = collect(app(StockReportService::class)->generateReport($company->id, ['from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'warehouse_id' => $target->id]))->sole();

        $this->assertSame('0.00', $sourceRow['end_amt']);
        $this->assertSame('20.00', $sourceRow['out_amt']);
        $this->assertSame('20.00', $targetRow['in_amt']);
        $this->assertSame('10.00', $targetRow['out_amt']);
        $this->assertSame('1.0000', $targetRow['out_qty']);
        $this->assertSame('10.00', $targetRow['end_amt']);
    }
}
