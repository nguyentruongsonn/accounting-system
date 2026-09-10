<?php

namespace App\Services;

use App\Models\InventoryValuationRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class InventoryValuationRunInvalidator
{
    /**
     * A movement dated within or before a completed valuation's end date can
     * change that run's closing stock and every later opening position.
     */
    public function invalidateForInventoryMovement(
        int $companyId,
        CarbonInterface|string $movementDate,
        string $reason,
        ?int $warehouseId = null,
        ?int $itemId = null,
    ): int {
        $runs = InventoryValuationRun::query()
            ->where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereDate('to_date', '>=', $movementDate)
            ->when($warehouseId !== null, function (Builder $query) use ($warehouseId): void {
                // A company-wide run includes every warehouse, so it is also
                // affected by a warehouse-specific movement.
                $query->where(fn (Builder $scope) => $scope
                    ->whereNull('warehouse_id')
                    ->orWhere('warehouse_id', $warehouseId));
            })
            ->when($itemId !== null, function (Builder $query) use ($itemId): void {
                // The same rule applies to an all-item run.
                $query->where(fn (Builder $scope) => $scope
                    ->whereNull('item_id')
                    ->orWhere('item_id', $itemId));
            });

        return $runs->update([
            'status' => 'invalidated',
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
        ]);
    }
}
