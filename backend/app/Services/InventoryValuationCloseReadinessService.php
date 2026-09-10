<?php

namespace App\Services;

use App\Models\InventoryIssue;
use App\Models\InventoryMovementEvent;
use App\Models\InventoryReceipt;
use App\Models\InventoryTransfer;
use App\Models\InventoryValuationRun;

class InventoryValuationCloseReadinessService
{
    /** @return array{eligible: bool, reason: string, latest_run: array<string, mixed>|null} */
    public function evaluate(int $companyId, string $fromDate, string $toDate): array
    {
        $postedDateInPeriod = static function ($query) use ($fromDate, $toDate): void {
            $query->where(function ($dateQuery) use ($fromDate, $toDate): void {
                $dateQuery->whereBetween('posting_date', [$fromDate, $toDate])
                    ->orWhere(function ($legacyQuery) use ($fromDate, $toDate): void {
                        $legacyQuery->whereNull('posting_date')
                            ->whereBetween('voucher_date', [$fromDate, $toDate]);
                    });
            });
        };

        $hasPhysicalMovement = InventoryReceipt::query()
            ->where('company_id', $companyId)
            ->where('is_posted', true)
            ->where($postedDateInPeriod)
            ->whereHas('lines')
            ->exists()
            || InventoryIssue::query()
                ->where('company_id', $companyId)
                ->where('is_posted', true)
                ->where($postedDateInPeriod)
                ->whereHas('lines')
                ->exists()
            || InventoryMovementEvent::query()
                ->where('company_id', $companyId)
                ->where('source_type', InventoryTransfer::class)
                ->whereIn('movement_type', ['transfer_out', 'transfer_in'])
                ->whereBetween('movement_date', [$fromDate, $toDate])
                ->whereIn('source_id', InventoryTransfer::query()
                    ->where('company_id', $companyId)
                    ->where('status', 'posted')
                    ->where('is_posted', true)
                    ->select('id'))
                ->exists();

        if (! $hasPhysicalMovement) {
            return [
                'eligible' => true,
                'reason' => 'no_inventory_movement_in_period',
                'latest_run' => null,
            ];
        }

        // Period close uses one all-warehouse/all-item result. A narrow
        // recalculation cannot prove the complete stock balance for the
        // accounting period.
        $run = InventoryValuationRun::query()
            ->where('company_id', $companyId)
            ->whereNull('warehouse_id')
            ->whereNull('item_id')
            ->whereDate('from_date', '<=', $fromDate)
            ->whereDate('to_date', '>=', $toDate)
            ->latest('completed_at')
            ->latest('id')
            ->first();

        $serializedRun = $run === null ? null : [
            'id' => $run->id,
            'status' => $run->status,
            'has_unverified_cost' => (bool) $run->has_unverified_cost,
            'method' => $run->method,
            'from_date' => $run->from_date?->toDateString(),
            'to_date' => $run->to_date?->toDateString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'invalidated_at' => $run->invalidated_at?->toISOString(),
            'invalidation_reason' => $run->invalidation_reason,
        ];

        if ($run === null) {
            return [
                'eligible' => false,
                'reason' => 'inventory_valuation_run_missing',
                'latest_run' => null,
            ];
        }

        if ($run->status !== 'completed') {
            return [
                'eligible' => false,
                'reason' => 'inventory_valuation_recalculation_required',
                'latest_run' => $serializedRun,
            ];
        }

        if ($run->has_unverified_cost) {
            return [
                'eligible' => false,
                'reason' => 'inventory_valuation_unverified_cost',
                'latest_run' => $serializedRun,
            ];
        }

        return [
            'eligible' => true,
            'reason' => 'inventory_valuation_current',
            'latest_run' => $serializedRun,
        ];
    }
}
