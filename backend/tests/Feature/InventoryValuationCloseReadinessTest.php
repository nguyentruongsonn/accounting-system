<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\InventoryMovementEvent;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\InventoryValuationRun;
use App\Models\Item;
use App\Models\Period;
use App\Models\Warehouse;
use App\Services\InventoryValuationCloseReadinessService;
use App\Services\PeriodCloseReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryValuationCloseReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_in_period_requires_a_current_full_scope_valuation_before_close(): void
    {
        $company = Company::create(['name' => 'Valuation close transfer readiness']);
        $fromWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'CLOSE-FROM', 'name' => 'Kho nguồn']);
        $toWarehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'CLOSE-TO', 'name' => 'Kho nhận']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'CLOSE-TRANSFER-ITEM', 'name' => 'Hàng điều chuyển']);
        $transfer = InventoryTransfer::create([
            'company_id' => $company->id,
            'transfer_number' => 'CLOSE-TRANSFER-001',
            'transfer_date' => '2026-01-15',
            'from_warehouse_id' => $fromWarehouse->id,
            'to_warehouse_id' => $toWarehouse->id,
            'status' => 'posted',
            'is_posted' => true,
        ]);
        $line = InventoryTransferLine::create([
            'inventory_transfer_id' => $transfer->id,
            'item_id' => $item->id,
            'quantity' => '1.0000',
        ]);
        InventoryMovementEvent::create([
            'company_id' => $company->id,
            'movement_date' => '2026-01-15',
            'warehouse_id' => $fromWarehouse->id,
            'item_id' => $item->id,
            'movement_type' => 'transfer_out',
            'quantity_delta' => '-1.0000',
            'source_type' => InventoryTransfer::class,
            'source_id' => $transfer->id,
            'source_line_id' => $line->id,
        ]);
        InventoryMovementEvent::create([
            'company_id' => $company->id,
            'movement_date' => '2026-01-15',
            'warehouse_id' => $toWarehouse->id,
            'item_id' => $item->id,
            'movement_type' => 'transfer_in',
            'quantity_delta' => '1.0000',
            'source_type' => InventoryTransfer::class,
            'source_id' => $transfer->id,
            'source_line_id' => $line->id,
        ]);

        $readiness = app(InventoryValuationCloseReadinessService::class)
            ->evaluate($company->id, '2026-01-01', '2026-01-31');

        $this->assertFalse($readiness['eligible']);
        $this->assertSame('inventory_valuation_run_missing', $readiness['reason']);
    }

    public function test_inventory_movement_period_is_blocked_when_its_latest_full_scope_valuation_is_invalidated(): void
    {
        $company = Company::create(['name' => 'Valuation close readiness']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'VALUATION-CLOSE', 'name' => 'Valuation close item']);
        $receipt = InventoryReceipt::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-CLOSE-RECEIPT', 'voucher_date' => '2026-01-15', 'is_posted' => true]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '10.0000', 'amount' => '10.00']);
        InventoryValuationRun::create([
            'company_id' => $company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
            'status' => 'invalidated',
            'completed_at' => now()->subMinute(),
            'invalidated_at' => now(),
            'invalidation_reason' => 'inventory_receipt_posted',
        ]);

        $readiness = app(InventoryValuationCloseReadinessService::class)
            ->evaluate($company->id, '2026-01-01', '2026-01-31');

        $this->assertFalse($readiness['eligible']);
        $this->assertSame('inventory_valuation_recalculation_required', $readiness['reason']);
        $this->assertSame('invalidated', $readiness['latest_run']['status']);
    }

    public function test_period_close_readiness_snapshot_exposes_invalidated_inventory_valuation_as_a_blocker(): void
    {
        $company = Company::create(['name' => 'Valuation close snapshot']);
        $fiscalYear = FiscalYear::create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $period = Period::create([
            'fiscal_year_id' => $fiscalYear->id,
            'name' => 'Tháng 01/2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'VALUATION-SNAPSHOT', 'name' => 'Valuation snapshot item']);
        $receipt = InventoryReceipt::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-SNAPSHOT-RECEIPT', 'voucher_date' => '2026-01-15', 'is_posted' => true]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '10.0000', 'amount' => '10.00']);
        InventoryValuationRun::create([
            'company_id' => $company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
            'status' => 'invalidated',
            'completed_at' => now()->subMinute(),
            'invalidated_at' => now(),
        ]);

        $snapshot = app(PeriodCloseReadinessService::class)->evaluate($company->id, $period->id);
        $check = collect($snapshot->snapshot['checks'])->firstWhere('code', 'INVENTORY.VALUATION_CURRENT');

        $this->assertNotNull($check);
        $this->assertSame('fail', $check['status']);
        $this->assertSame('inventory_valuation_recalculation_required', $check['details']['reason']);
    }

    public function test_completed_valuation_with_unverified_cost_cannot_make_period_close_eligible(): void
    {
        $company = Company::create(['name' => 'Valuation close unverified cost']);
        $item = Item::create(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'VALUATION-UNVERIFIED', 'name' => 'Unverified cost item']);
        $receipt = InventoryReceipt::create(['company_id' => $company->id, 'voucher_number' => 'VALUATION-UNVERIFIED-RECEIPT', 'voucher_date' => '2026-01-15', 'is_posted' => true]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '10.0000', 'amount' => '10.00']);
        $run = InventoryValuationRun::create([
            'company_id' => $company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
            'status' => 'completed',
            'has_unverified_cost' => true,
            'completed_at' => now(),
        ]);

        $readiness = app(InventoryValuationCloseReadinessService::class)
            ->evaluate($company->id, '2026-01-01', '2026-01-31');

        $this->assertFalse($readiness['eligible']);
        $this->assertSame('inventory_valuation_unverified_cost', $readiness['reason']);
        $this->assertTrue($readiness['latest_run']['has_unverified_cost']);
        $this->assertSame('completed', $run->fresh()->status);
    }
}
