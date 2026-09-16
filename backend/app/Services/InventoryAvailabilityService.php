<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Computes signed on-hand quantity from the same posted sources as stock reports. */
final class InventoryAvailabilityService
{
    public function availableQuantity(int $companyId, int $itemId, int $warehouseId, string $asOfDate): string
    {
        $opening = $this->openingQuantity($companyId, $itemId, $warehouseId, $asOfDate);
        $receipts = $this->movementQuantity('inventory_receipt_lines', 'inventory_receipts', 'inventory_receipt_id', $companyId, $itemId, $warehouseId, $asOfDate);
        $issues = $this->movementQuantity('inventory_issue_lines', 'inventory_issues', 'inventory_issue_id', $companyId, $itemId, $warehouseId, $asOfDate);
        $events = $this->eventQuantity($companyId, $itemId, $warehouseId, $asOfDate);

        return $opening->plus($receipts)->minus($issues)->plus($events)->toScale(4)->__toString();
    }

    private function movementQuantity(string $lineTable, string $headerTable, string $foreignKey, int $companyId, int $itemId, int $warehouseId, string $asOfDate): BigDecimal
    {
        // Inventory availability follows the accounting/posting date, just
        // like stock reports and valuation. Legacy rows without that column
        // explicitly fall back to their voucher date.
        $dateColumn = Schema::hasColumn($headerTable, 'posting_date')
            ? DB::raw('COALESCE(header.posting_date, header.voucher_date)')
            : 'header.voucher_date';
        $total = DB::table("{$lineTable} as line")
            ->join("{$headerTable} as header", "line.{$foreignKey}", '=', 'header.id')
            ->where('header.company_id', $companyId)
            ->where('header.is_posted', true)
            ->where('line.item_id', $itemId)
            ->whereDate($dateColumn, '<=', $asOfDate)
            ->where(function ($query) use ($warehouseId): void {
                $query->where('line.warehouse_id', $warehouseId)
                    ->orWhere(function ($legacy) use ($warehouseId): void {
                        $legacy->whereNull('line.warehouse_id')->where('header.warehouse_id', $warehouseId);
                    });
            })
            ->selectRaw('COALESCE(SUM(line.quantity), 0) as quantity')
            ->value('quantity');

        return BigDecimal::of((string) $total)->toScale(4);
    }

    private function openingQuantity(int $companyId, int $itemId, int $warehouseId, string $asOfDate): BigDecimal
    {
        if (! Schema::hasTable('opening_balance_packages') || ! Schema::hasTable('opening_balance_inventory_lines')) {
            return BigDecimal::zero()->toScale(4);
        }

        $total = DB::table('opening_balance_inventory_lines as line')
            ->join('opening_balance_packages as package', 'line.package_id', '=', 'package.id')
            ->where('package.company_id', $companyId)
            ->where('package.status', 'confirmed')
            ->where('line.item_id', $itemId)
            ->where('line.warehouse_id', $warehouseId)
            ->whereDate('package.effective_date', '<=', $asOfDate)
            ->selectRaw('COALESCE(SUM(line.quantity), 0) as quantity')
            ->value('quantity');

        return BigDecimal::of((string) $total)->toScale(4);
    }

    private function eventQuantity(int $companyId, int $itemId, int $warehouseId, string $asOfDate): BigDecimal
    {
        if (! Schema::hasTable('inventory_movement_events')) {
            return BigDecimal::zero()->toScale(4);
        }

        $total = DB::table('inventory_movement_events')
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->whereDate('movement_date', '<=', $asOfDate)
            ->selectRaw('COALESCE(SUM(quantity_delta), 0) as quantity')
            ->value('quantity');

        return BigDecimal::of((string) $total)->toScale(4);
    }
}
