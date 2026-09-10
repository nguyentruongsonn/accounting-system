<?php

namespace App\Services;

use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryMovementEvent;
use App\Models\InventoryReceiptLine;
use App\Models\InventoryValuationRun;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Support\DecimalMoney;
use App\Support\InventoryCostArithmetic;
use App\Support\InventoryTenantGuard;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryValuationService
{
    public function __construct(
        private readonly AccountingPeriodGuard $periodGuard,
    ) {}

    /**
     * Return the exact four-decimal moving-average rate used while preparing
     * an issue draft. Posting date is the period basis; legacy rows without
     * one explicitly fall back to their voucher date.
     */
    public function getMovingAverageCostExact($item_id, ?int $company_id = null, $date = null): string
    {
        // Preserve explicit trusted worker context, but never allow an
        // authenticated actor to ask valuation for another tenant.
        $company_id = InventoryTenantGuard::companyId(['company_id' => $company_id]);
        $dateString = ($date ? Carbon::parse($date) : Carbon::today())->toDateString();

        $postedOnOrBefore = static function ($query) use ($dateString): void {
            $query->where(function ($dateQuery) use ($dateString): void {
                $dateQuery->whereDate('posting_date', '<=', $dateString)
                    ->orWhere(function ($legacyQuery) use ($dateString): void {
                        $legacyQuery->whereNull('posting_date')->whereDate('voucher_date', '<=', $dateString);
                    });
            });
        };

        // A purchase invoice is commercial/AP evidence. A posted inventory
        // receipt is the single stock movement, otherwise the same purchase
        // is counted twice and the average cost becomes incorrect.
        $irQuery = InventoryReceiptLine::where('item_id', $item_id)
            ->whereHas('receipt', function ($q) use ($company_id, $postedOnOrBefore): void {
                $q->where('company_id', $company_id)->where('is_posted', true);
                $postedOnOrBefore($q);
            })->selectRaw('SUM(quantity) as qty, SUM(amount) as amt')->first();

        // Issues reduce the available pool before a new draft is prepared.
        $iiQuery = InventoryIssueLine::where('item_id', $item_id)
            ->whereHas('issue', function ($q) use ($company_id, $postedOnOrBefore): void {
                $q->where('company_id', $company_id)->where('is_posted', true);
                $postedOnOrBefore($q);
            })->selectRaw('SUM(quantity) as qty, SUM(amount) as amt')->first();

        $totalInQty = InventoryCostArithmetic::quantity((string) ($irQuery->qty ?? '0'));
        $totalOutQty = InventoryCostArithmetic::quantity((string) ($iiQuery->qty ?? '0'));
        $netQty = BigDecimal::of($totalInQty)->minus(BigDecimal::of($totalOutQty));
        $netAmt = DecimalMoney::subtract(
            DecimalMoney::normalize((string) ($irQuery->amt ?? '0')),
            DecimalMoney::normalize((string) ($iiQuery->amt ?? '0')),
        );

        if (! $netQty->isPositive()) {
            // Fallback to item purchase price or zero. The fallback remains
            // tenant-scoped and is returned at the same exact rate scale.
            $item = Item::where('company_id', $company_id)->find($item_id);

            return $item ? InventoryCostArithmetic::rate((string) ($item->purchase_price ?? '0')) : InventoryCostArithmetic::ZERO_RATE;
        }

        return InventoryCostArithmetic::weightedUnitRate($netAmt, $netQty->toScale(2)->__toString());
    }

    /**
     * Backward-compatible numeric wrapper for legacy callers. Core issue
     * preparation uses getMovingAverageCostExact so persisted values do not
     * pass through a floating-point calculation.
     */
    public function getMovingAverageCost($item_id, ?int $company_id = null, $date = null)
    {
        return (float) $this->getMovingAverageCostExact($item_id, $company_id, $date);
    }

    /**
     * Run cost calculation engine
     */
    public function runCostCalculation(array $params): array
    {
        $params = $this->normalizeCostCalculationParams($params);

        $method = $params['method'] ?? 'weighted_average';

        return DB::transaction(function () use ($params, $method): array {
            $result = $method === 'fifo'
                ? $this->calculateFifo($params)
                : $this->calculateMonthlyWeightedAverage($params);

            $run = InventoryValuationRun::create([
                'company_id' => (int) $params['company_id'],
                'warehouse_id' => $params['warehouse_id'] ?? null,
                'item_id' => $params['item_id'] ?? null,
                'from_date' => $result['from_date'],
                'to_date' => $result['to_date'],
                'method' => $result['method'],
                'status' => 'completed',
                'has_unverified_cost' => ! empty($result['unverified_cost_items']),
                'updated_issues_count' => (int) $result['updated_issues_count'],
                'total_cost_amount' => $result['total_cost_amount'],
                'completed_at' => now(),
            ]);

            $result['valuation_run'] = [
                'id' => $run->id,
                'status' => $run->status,
                'has_unverified_cost' => (bool) $run->has_unverified_cost,
                'completed_at' => $run->completed_at?->toISOString(),
            ];

            return $result;
        });
    }

    /**
     * Thuật toán Bình quân gia quyền cuối kỳ (Monthly Weighted Average)
     */
    public function calculateMonthlyWeightedAverage(array $params): array
    {
        $params = $this->normalizeCostCalculationParams($params);

        return DB::transaction(function () use ($params) {
            $companyId = (int) $params['company_id'];
            $fromDate = ! empty($params['from_date']) ? Carbon::parse($params['from_date'])->startOfDay() : now()->startOfMonth();
            $toDate = ! empty($params['to_date']) ? Carbon::parse($params['to_date'])->endOfDay() : now()->endOfMonth();
            $warehouseId = $params['warehouse_id'] ?? null;

            // Cost calculation rewrites issue-line amounts and the linked GL
            // entry.  Establish the source-period invariant before loading or
            // mutating any candidate line so a closed range cannot leave a
            // partially revalued issue behind.
            $this->periodGuard->assertRangeOpen(
                $companyId,
                $fromDate,
                $toDate,
                'tính giá xuất kho'
            );

            $itemsQuery = Item::where('company_id', $companyId);
            if (! empty($params['item_id'])) {
                $itemsQuery->where('id', $params['item_id']);
            }
            $items = $itemsQuery->get();

            // A transfer is one physical movement between two warehouses,
            // not an inward purchase. Resolve its carrying value before the
            // per-warehouse average is read below so the destination receives
            // exactly the amount removed from the source.
            $this->allocateWeightedAverageTransferAmounts($companyId, $fromDate, $toDate, $items->pluck('id')->all());

            $updatedIssuesCount = 0;
            $totalCostAmount = DecimalMoney::ZERO;
            $processedItems = [];
            $unverifiedCostItems = [];

            foreach ($items as $item) {
                // A. Opening balance before from_date
                // A confirmed opening package is inventory evidence, while a
                // draft package must never affect cost. It is additive to
                // posted receipt/issue history before the selected period.
                $openingBalance = DB::table('opening_balance_inventory_lines as line')
                    ->join('opening_balance_packages as package', 'line.package_id', '=', 'package.id')
                    ->where('package.company_id', $companyId)
                    ->where('package.status', 'confirmed')
                    ->where('line.item_id', $item->id)
                    ->whereDate('package.effective_date', '<=', $fromDate)
                    ->when($warehouseId, fn ($query) => $query->where('line.warehouse_id', $warehouseId))
                    ->selectRaw('COALESCE(SUM(line.quantity), 0) as qty, COALESCE(SUM(line.total_value), 0) as amt')
                    ->first();
                $openIr = InventoryReceiptLine::where('item_id', $item->id)
                    ->whereHas('receipt', function ($q) use ($companyId, $fromDate, $warehouseId) {
                        $q->where('company_id', $companyId)
                            ->where('is_posted', true)
                            ->where('voucher_date', '<', $fromDate);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })->selectRaw('SUM(quantity) as qty, SUM(amount) as amt')->first();

                // Issues before from_date
                $openIi = InventoryIssueLine::where('item_id', $item->id)
                    ->whereHas('issue', function ($q) use ($companyId, $fromDate, $warehouseId) {
                        $q->where('company_id', $companyId)
                            ->where('is_posted', true)
                            ->where('voucher_date', '<', $fromDate);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })->selectRaw('SUM(quantity) as qty, SUM(amount) as amt')->first();

                $openTransfers = $this->transferMovementTotals($companyId, $item->id, $warehouseId, null, $fromDate);

                // Purchase invoices are commercial/AP evidence. Posted
                // inventory receipts are the only inward stock movements.
                $openingInQty = InventoryCostArithmetic::addQuantity(
                    InventoryCostArithmetic::addQuantity((string) ($openingBalance->qty ?? '0'), (string) ($openIr->qty ?? '0')),
                    $openTransfers['in_quantity'],
                );
                $openingInAmt = DecimalMoney::add(
                    DecimalMoney::add((string) ($openingBalance->amt ?? '0'), (string) ($openIr->amt ?? '0')),
                    $openTransfers['in_amount'],
                );
                $openingOutQty = InventoryCostArithmetic::addQuantity((string) ($openIi->qty ?? '0'), $openTransfers['out_quantity']);
                $openingOutAmt = DecimalMoney::add((string) ($openIi->amt ?? '0'), $openTransfers['out_amount']);

                if (InventoryCostArithmetic::compareQuantity($openingInQty, $openingOutQty) < 0) {
                    throw ValidationException::withMessages([
                        'inventory_availability' => "Tồn đầu kỳ của hàng {$item->code} âm; cần điều chỉnh kho trước khi tính giá xuất kho.",
                    ]);
                } else {
                    $openingQty = InventoryCostArithmetic::subtractQuantity($openingInQty, $openingOutQty);
                    $openingAmt = DecimalMoney::subtract($openingInAmt, $openingOutAmt);
                }

                // B. Inward receipts during period (from_date to to_date)
                $periodIr = InventoryReceiptLine::where('item_id', $item->id)
                    ->whereHas('receipt', function ($q) use ($companyId, $fromDate, $toDate, $warehouseId) {
                        $q->where('company_id', $companyId)
                            ->where('is_posted', true)
                            ->whereBetween('voucher_date', [$fromDate, $toDate]);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })->selectRaw('SUM(quantity) as qty, SUM(amount) as amt')->first();

                $periodTransfers = $this->transferMovementTotals($companyId, $item->id, $warehouseId, $fromDate, $toDate);

                $periodInQty = InventoryCostArithmetic::addQuantity((string) ($periodIr->qty ?? '0'), $periodTransfers['in_quantity']);
                $periodInAmt = DecimalMoney::add((string) ($periodIr->amt ?? '0'), $periodTransfers['in_amount']);

                // C. Calculate weighted average unit cost
                $totalAvailableQty = InventoryCostArithmetic::subtractQuantity(
                    InventoryCostArithmetic::addQuantity($openingQty, $periodInQty),
                    $periodTransfers['out_quantity'],
                );
                $totalAvailableAmt = DecimalMoney::subtract(
                    DecimalMoney::add($openingAmt, $periodInAmt),
                    $periodTransfers['out_amount'],
                );

                $hasAvailableStock = InventoryCostArithmetic::compareQuantity($totalAvailableQty, InventoryCostArithmetic::ZERO_QUANTITY) > 0;
                $unitCost = $hasAvailableStock
                    ? InventoryCostArithmetic::weightedUnitRate($totalAvailableAmt, $totalAvailableQty)
                    : InventoryCostArithmetic::rate((string) ($item->purchase_price ?: ($item->cost_price ?: '0')));

                // D. Update all inventory issue lines in the period
                $issueLines = InventoryIssueLine::where('item_id', $item->id)
                    ->whereHas('issue', function ($q) use ($companyId, $fromDate, $toDate, $warehouseId) {
                        $q->where('company_id', $companyId)
                            ->where('is_posted', true)
                            ->whereBetween('voucher_date', [$fromDate, $toDate]);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })->get();

                $itemCostVerified = $hasAvailableStock || $issueLines->isEmpty();
                if (! $itemCostVerified) {
                    $unverifiedCostItems[] = ['item_id' => $item->id, 'item_code' => $item->code, 'reason' => 'Không đủ tồn/giá trị tồn để tính bình quân; đang dùng giá dự phòng.'];
                }

                $affectedIssueIds = [];
                $itemTotalCost = DecimalMoney::ZERO;

                foreach ($issueLines as $line) {
                    $newAmount = $hasAvailableStock
                        ? InventoryCostArithmetic::weightedIssueAmount($totalAvailableAmt, (string) $line->quantity, $totalAvailableQty)
                        : InventoryCostArithmetic::amountForQuantityAtRate((string) $line->quantity, $unitCost);
                    $line->unit_price = $unitCost;
                    $line->amount = $newAmount;
                    $line->save();

                    $itemTotalCost = DecimalMoney::add($itemTotalCost, $newAmount);
                    $affectedIssueIds[$line->inventory_issue_id] = true;
                }

                // Update affected inventory issues and their GL entries
                foreach (array_keys($affectedIssueIds) as $issueId) {
                    $this->syncIssueAndJournalEntry($companyId, (int) $issueId);
                    $updatedIssuesCount++;
                }

                $totalCostAmount = DecimalMoney::add($totalCostAmount, $itemTotalCost);

                $processedItems[] = [
                    'item_id' => $item->id,
                    'item_code' => $item->code,
                    'item_name' => $item->name,
                    'unit' => $item->unit ?? 'Cái',
                    'opening_qty' => $openingQty,
                    'opening_amt' => $openingAmt,
                    'in_qty' => $periodInQty,
                    'in_amt' => $periodInAmt,
                    'unit_cost' => $unitCost,
                    'total_out_cost' => $itemTotalCost,
                    'cost_status' => $itemCostVerified ? 'verified' : 'unverified',
                ];
            }

            return [
                'success' => true,
                'method' => 'weighted_average',
                'from_date' => $fromDate->toDateString(),
                'to_date' => $toDate->toDateString(),
                'updated_issues_count' => $updatedIssuesCount,
                'total_cost_amount' => $totalCostAmount,
                'items' => $processedItems,
                'unverified_cost_items' => $unverifiedCostItems,
            ];
        });
    }

    /**
     * Thuật toán Nhập trước - Xuất trước (FIFO)
     */
    public function calculateFifo(array $params): array
    {
        $params = $this->normalizeCostCalculationParams($params);

        $this->assertFifoHasNoTransferEvents($params);

        return DB::transaction(function () use ($params) {
            $companyId = (int) $params['company_id'];
            $fromDate = ! empty($params['from_date']) ? Carbon::parse($params['from_date'])->startOfDay() : now()->startOfMonth();
            $toDate = ! empty($params['to_date']) ? Carbon::parse($params['to_date'])->endOfDay() : now()->endOfMonth();
            $warehouseId = $params['warehouse_id'] ?? null;

            // See the weighted-average path above. FIFO has the same source
            // and GL rewrite semantics, so it must be closed-period safe at
            // the first mutation boundary as well.
            $this->periodGuard->assertRangeOpen(
                $companyId,
                $fromDate,
                $toDate,
                'tính giá xuất kho'
            );

            $itemsQuery = Item::where('company_id', $companyId);
            if (! empty($params['item_id'])) {
                $itemsQuery->where('id', $params['item_id']);
            }
            $items = $itemsQuery->get();

            $updatedIssuesCount = 0;
            $totalCostAmount = DecimalMoney::ZERO;
            $processedItems = [];
            $unverifiedCostItems = [];

            foreach ($items as $item) {
                // Report-only opening/inward figures.  FIFO allocation itself
                // is reconstructed from immutable batches below; all monetary
                // and quantity values remain exact decimal strings.
                $openIr = InventoryReceiptLine::where('item_id', $item->id)
                    ->whereHas('receipt', function ($q) use ($companyId, $fromDate, $warehouseId) {
                        $q->where('company_id', $companyId)->where('is_posted', true)->where('voucher_date', '<', $fromDate);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })->selectRaw('SUM(quantity) as qty, SUM(amount) as amt')->first();
                $openIi = InventoryIssueLine::where('item_id', $item->id)
                    ->whereHas('issue', function ($q) use ($companyId, $fromDate, $warehouseId) {
                        $q->where('company_id', $companyId)->where('is_posted', true)->where('voucher_date', '<', $fromDate);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })->selectRaw('SUM(quantity) as qty, SUM(amount) as amt')->first();
                $openingInQty = InventoryCostArithmetic::addQuantity((string) ($openingBalance->qty ?? '0'), (string) ($openIr->qty ?? '0'));
                $openingInAmt = DecimalMoney::add((string) ($openingBalance->amt ?? '0'), (string) ($openIr->amt ?? '0'));
                $openingOutQty = InventoryCostArithmetic::quantity((string) ($openIi->qty ?? '0'));
                if (InventoryCostArithmetic::compareQuantity($openingInQty, $openingOutQty) < 0) {
                    throw ValidationException::withMessages([
                        'inventory_availability' => "Tồn đầu kỳ của hàng {$item->code} âm; cần điều chỉnh kho trước khi tính giá xuất kho.",
                    ]);
                } else {
                    $openingQty = InventoryCostArithmetic::subtractQuantity($openingInQty, $openingOutQty);
                    $openingAmt = DecimalMoney::subtract($openingInAmt, (string) ($openIi->amt ?? '0'));
                }

                $periodIr = InventoryReceiptLine::where('item_id', $item->id)
                    ->whereHas('receipt', function ($q) use ($companyId, $fromDate, $toDate, $warehouseId) {
                        $q->where('company_id', $companyId)
                            ->where('is_posted', true)
                            ->whereBetween('voucher_date', [$fromDate, $toDate]);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })->selectRaw('SUM(quantity) as qty, SUM(amount) as amt')->first();

                $periodInQty = InventoryCostArithmetic::quantity((string) ($periodIr->qty ?? '0'));
                $periodInAmt = DecimalMoney::normalize((string) ($periodIr->amt ?? '0'));

                // 1. Gather all inward batches up to to_date (chronological order).
                // Confirmed opening stock and posted inventory receipts are the
                // only physical inward movements. Purchase invoices are AP
                // evidence and must not become stock a second time.
                $openingBatches = DB::table('opening_balance_inventory_lines as line')
                    ->join('opening_balance_packages as package', 'line.package_id', '=', 'package.id')
                    ->where('package.company_id', $companyId)
                    ->where('package.status', 'confirmed')
                    ->where('line.item_id', $item->id)
                    ->whereDate('package.effective_date', '<=', $toDate)
                    ->when($warehouseId, fn ($query) => $query->where('line.warehouse_id', $warehouseId))
                    ->orderBy('package.effective_date')
                    ->orderBy('line.id')
                    ->get(['line.id', 'line.quantity', 'line.unit_cost', 'package.effective_date'])
                    ->map(function ($line) {
                        return [
                            'source' => 'opening_balance',
                            'date' => Carbon::parse($line->effective_date)->format('Y-m-d'),
                            'id' => (int) $line->id,
                            'unit_price' => InventoryCostArithmetic::rate((string) $line->unit_cost),
                            'quantity' => InventoryCostArithmetic::quantity((string) $line->quantity),
                            'remaining_qty' => InventoryCostArithmetic::quantity((string) $line->quantity),
                        ];
                    });

                $receipts = InventoryReceiptLine::where('item_id', $item->id)
                    ->whereHas('receipt', function ($q) use ($companyId, $toDate, $warehouseId) {
                        $q->where('company_id', $companyId)
                            ->where('is_posted', true)
                            ->where('voucher_date', '<=', $toDate);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })
                    ->with('receipt')
                    ->get()
                    ->map(function ($r) {
                        return [
                            'source' => 'receipt',
                            'date' => $r->receipt->voucher_date->format('Y-m-d'),
                            'id' => $r->id,
                            'unit_price' => InventoryCostArithmetic::rate((string) $r->unit_price),
                            'quantity' => InventoryCostArithmetic::quantity((string) $r->quantity),
                            'remaining_qty' => InventoryCostArithmetic::quantity((string) $r->quantity),
                        ];
                    });

                $inwardBatches = $openingBatches->concat($receipts)
                    ->sortBy(function ($b) {
                        return $b['date'].'_'.$b['id'];
                    })
                    ->values()
                    ->toArray();

                // 2. Get all previous issues before from_date to consume initial stock
                $priorIssues = InventoryIssueLine::where('item_id', $item->id)
                    ->whereHas('issue', function ($q) use ($companyId, $fromDate, $warehouseId) {
                        $q->where('company_id', $companyId)
                            ->where('is_posted', true)
                            ->where('voucher_date', '<', $fromDate);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })
                    ->with('issue')
                    ->get()
                    ->sortBy(function ($i) {
                        return $i->issue->voucher_date->format('Y-m-d').'_'.$i->id;
                    });

                foreach ($priorIssues as $priorLine) {
                    $neededQty = InventoryCostArithmetic::quantity((string) $priorLine->quantity);
                    for ($b = 0; $b < count($inwardBatches) && InventoryCostArithmetic::compareQuantity($neededQty, InventoryCostArithmetic::ZERO_QUANTITY) > 0; $b++) {
                        if (InventoryCostArithmetic::compareQuantity($inwardBatches[$b]['remaining_qty'], InventoryCostArithmetic::ZERO_QUANTITY) > 0) {
                            $consume = InventoryCostArithmetic::compareQuantity($inwardBatches[$b]['remaining_qty'], $neededQty) < 0 ? $inwardBatches[$b]['remaining_qty'] : $neededQty;
                            $inwardBatches[$b]['remaining_qty'] = InventoryCostArithmetic::subtractQuantity($inwardBatches[$b]['remaining_qty'], $consume);
                            $neededQty = InventoryCostArithmetic::subtractQuantity($neededQty, $consume);
                        }
                    }
                }

                // 3. Now consume batches for current period issues
                $currentPeriodIssues = InventoryIssueLine::where('item_id', $item->id)
                    ->whereHas('issue', function ($q) use ($companyId, $fromDate, $toDate, $warehouseId) {
                        $q->where('company_id', $companyId)
                            ->where('is_posted', true)
                            ->whereBetween('voucher_date', [$fromDate, $toDate]);
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    })
                    ->with('issue')
                    ->get()
                    ->sortBy(function ($i) {
                        return $i->issue->voucher_date->format('Y-m-d').'_'.$i->id;
                    });

                $itemCostVerified = true;

                $affectedIssueIds = [];
                $itemTotalCost = DecimalMoney::ZERO;

                foreach ($currentPeriodIssues as $issueLine) {
                    $qtyNeeded = InventoryCostArithmetic::quantity((string) $issueLine->quantity);
                    $lineTotalCost = DecimalMoney::ZERO;

                    for ($b = 0; $b < count($inwardBatches) && InventoryCostArithmetic::compareQuantity($qtyNeeded, InventoryCostArithmetic::ZERO_QUANTITY) > 0; $b++) {
                        if (InventoryCostArithmetic::compareQuantity($inwardBatches[$b]['remaining_qty'], InventoryCostArithmetic::ZERO_QUANTITY) > 0) {
                            $consume = InventoryCostArithmetic::compareQuantity($inwardBatches[$b]['remaining_qty'], $qtyNeeded) < 0 ? $inwardBatches[$b]['remaining_qty'] : $qtyNeeded;
                            $lineTotalCost = DecimalMoney::add($lineTotalCost, InventoryCostArithmetic::amountForQuantityAtRate($consume, $inwardBatches[$b]['unit_price']));
                            $inwardBatches[$b]['remaining_qty'] = InventoryCostArithmetic::subtractQuantity($inwardBatches[$b]['remaining_qty'], $consume);
                            $qtyNeeded = InventoryCostArithmetic::subtractQuantity($qtyNeeded, $consume);
                        }
                    }

                    // If remaining quantity could not be matched with batches, use last batch price or purchase price
                    if (InventoryCostArithmetic::compareQuantity($qtyNeeded, InventoryCostArithmetic::ZERO_QUANTITY) > 0) {
                        $itemCostVerified = false;
                        $unverifiedCostItems[] = ['item_id' => $item->id, 'item_code' => $item->code, 'reason' => 'Không đủ lô nhập để phân bổ FIFO; đang dùng giá dự phòng.'];
                        $fallbackPrice = ! empty($inwardBatches)
                            ? $inwardBatches[array_key_last($inwardBatches)]['unit_price']
                            : InventoryCostArithmetic::rate((string) ($item->purchase_price ?? '0'));
                        $lineTotalCost = DecimalMoney::add($lineTotalCost, InventoryCostArithmetic::amountForQuantityAtRate($qtyNeeded, $fallbackPrice));
                    }

                    $unitCost = InventoryCostArithmetic::compareQuantity((string) $issueLine->quantity, InventoryCostArithmetic::ZERO_QUANTITY) > 0
                        ? InventoryCostArithmetic::weightedUnitRate($lineTotalCost, (string) $issueLine->quantity)
                        : InventoryCostArithmetic::ZERO_RATE;
                    $issueLine->unit_price = $unitCost;
                    $issueLine->amount = $lineTotalCost;
                    $issueLine->save();

                    $itemTotalCost = DecimalMoney::add($itemTotalCost, (string) $issueLine->amount);
                    $affectedIssueIds[$issueLine->inventory_issue_id] = true;
                }

                // Update affected inventory issues and their GL entries
                foreach (array_keys($affectedIssueIds) as $issueId) {
                    $this->syncIssueAndJournalEntry($companyId, (int) $issueId);
                    $updatedIssuesCount++;
                }

                $totalCostAmount = DecimalMoney::add($totalCostAmount, $itemTotalCost);

                $totalOutQty = InventoryCostArithmetic::ZERO_QUANTITY;
                foreach ($currentPeriodIssues as $currentPeriodIssue) {
                    $totalOutQty = InventoryCostArithmetic::addQuantity($totalOutQty, (string) $currentPeriodIssue->quantity);
                }
                $avgUnitCost = InventoryCostArithmetic::compareQuantity($totalOutQty, InventoryCostArithmetic::ZERO_QUANTITY) > 0
                    ? InventoryCostArithmetic::weightedUnitRate($itemTotalCost, $totalOutQty)
                    : InventoryCostArithmetic::ZERO_RATE;

                $processedItems[] = [
                    'item_id' => $item->id,
                    'item_code' => $item->code,
                    'item_name' => $item->name,
                    'unit' => $item->unit ?? 'Cái',
                    'opening_qty' => $openingQty,
                    'opening_amt' => $openingAmt,
                    'in_qty' => $periodInQty,
                    'in_amt' => $periodInAmt,
                    'unit_cost' => $avgUnitCost,
                    'total_out_cost' => $itemTotalCost,
                    'cost_status' => $itemCostVerified ? 'verified' : 'unverified',
                ];
            }

            return [
                'success' => true,
                'method' => 'fifo',
                'from_date' => $fromDate->toDateString(),
                'to_date' => $toDate->toDateString(),
                'updated_issues_count' => $updatedIssuesCount,
                'total_cost_amount' => $totalCostAmount,
                'items' => $processedItems,
                'unverified_cost_items' => $unverifiedCostItems,
            ];
        });
    }

    /** @param array<string, mixed> $params */
    private function assertFifoHasNoTransferEvents(array $params): void
    {
        $fromDate = ! empty($params['from_date']) ? Carbon::parse($params['from_date'])->startOfDay() : now()->startOfMonth();
        $toDate = ! empty($params['to_date']) ? Carbon::parse($params['to_date'])->endOfDay() : now()->endOfMonth();
        $query = InventoryMovementEvent::query()
            ->where('company_id', (int) $params['company_id'])
            ->whereIn('movement_type', [
                'transfer_out',
                'transfer_in',
                'transfer_out_reversal',
                'transfer_in_reversal',
            ])
            ->whereBetween('movement_date', [$fromDate->toDateString(), $toDate->toDateString()]);
        if (! empty($params['item_id'])) {
            $query->where('item_id', (int) $params['item_id']);
        }
        if (! empty($params['warehouse_id'])) {
            $query->where('warehouse_id', (int) $params['warehouse_id']);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'inventory_transfers' => 'FIFO chưa hỗ trợ điều chuyển kho trong kỳ này; hãy dùng Bình quân gia quyền cuối kỳ để tránh sai giá trị tồn kho.',
            ]);
        }
    }

    /**
     * Assign carrying values to transfer event pairs for a periodic weighted
     * average run. The source warehouse keeps its periodic rate after an
     * outbound transfer; the destination rate is blended with that carried
     * amount. Processing by event id makes same-day chained transfers
     * deterministic without creating a journal entry.
     *
     * @param  array<int, int>  $itemIds
     */
    private function allocateWeightedAverageTransferAmounts(int $companyId, Carbon $fromDate, Carbon $toDate, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        $events = InventoryMovementEvent::query()
            ->where('company_id', $companyId)
            ->whereIn('item_id', $itemIds)
            ->whereBetween('movement_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->whereIn('movement_type', ['transfer_out', 'transfer_out_reversal'])
            ->orderBy('movement_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var array<string, array{quantity: string, amount: string}> $pools */
        $pools = [];
        foreach ($events as $event) {
            $key = $event->item_id.':'.$event->warehouse_id;
            if (! isset($pools[$key])) {
                $pools[$key] = $this->weightedAverageTransferPool(
                    $companyId,
                    (int) $event->item_id,
                    (int) $event->warehouse_id,
                    $fromDate,
                    $toDate,
                );
            }

            $counterpartType = $event->movement_type === 'transfer_out'
                ? 'transfer_in'
                : 'transfer_in_reversal';
            $counterpart = InventoryMovementEvent::query()
                ->where('company_id', $companyId)
                ->where('source_type', $event->source_type)
                ->where('source_id', $event->source_id)
                ->where('source_line_id', $event->source_line_id)
                ->where('movement_type', $counterpartType)
                ->lockForUpdate()
                ->sole();

            $quantity = (string) $event->quantity_delta;
            $quantity = str_starts_with($quantity, '-') ? substr($quantity, 1) : $quantity;
            $quantity = InventoryCostArithmetic::quantity($quantity);
            if (InventoryCostArithmetic::compareQuantity($quantity, InventoryCostArithmetic::ZERO_QUANTITY) === 0) {
                continue;
            }

            if ($event->movement_type === 'transfer_out_reversal') {
                $originalAmount = InventoryMovementEvent::query()
                    ->where('company_id', $companyId)
                    ->where('source_type', $event->source_type)
                    ->where('source_id', $event->source_id)
                    ->where('source_line_id', $event->source_line_id)
                    ->where('movement_type', 'transfer_out')
                    ->value('amount_delta');
                if ($originalAmount === null) {
                    throw ValidationException::withMessages([
                        'inventory_transfers' => 'Phiếu điều chuyển cần được tính giá trước khi bỏ ghi sổ ở kỳ sau.',
                    ]);
                }
                $amount = DecimalMoney::abs((string) $originalAmount);
                $pools[$key]['quantity'] = InventoryCostArithmetic::addQuantity($pools[$key]['quantity'], $quantity);
                $pools[$key]['amount'] = DecimalMoney::add($pools[$key]['amount'], $amount);
                $event->update(['amount_delta' => $amount]);
                $counterpart->update(['amount_delta' => DecimalMoney::negate($amount)]);

                continue;
            }

            if (InventoryCostArithmetic::compareQuantity($pools[$key]['quantity'], $quantity) < 0) {
                throw ValidationException::withMessages([
                    'inventory_availability' => 'Không đủ tồn có giá trị tại kho xuất để tính giá điều chuyển.',
                ]);
            }
            $rate = InventoryCostArithmetic::weightedUnitRate($pools[$key]['amount'], $pools[$key]['quantity']);
            $amount = InventoryCostArithmetic::amountForQuantityAtRate($quantity, $rate);
            $pools[$key]['quantity'] = InventoryCostArithmetic::subtractQuantity($pools[$key]['quantity'], $quantity);
            $pools[$key]['amount'] = DecimalMoney::subtract($pools[$key]['amount'], $amount);
            $event->update(['amount_delta' => DecimalMoney::negate($amount)]);
            $counterpart->update(['amount_delta' => $amount]);

            $destinationKey = $event->item_id.':'.$counterpart->warehouse_id;
            if (! isset($pools[$destinationKey])) {
                $pools[$destinationKey] = $this->weightedAverageTransferPool(
                    $companyId,
                    (int) $event->item_id,
                    (int) $counterpart->warehouse_id,
                    $fromDate,
                    $toDate,
                );
            }
            $pools[$destinationKey]['quantity'] = InventoryCostArithmetic::addQuantity($pools[$destinationKey]['quantity'], $quantity);
            $pools[$destinationKey]['amount'] = DecimalMoney::add($pools[$destinationKey]['amount'], $amount);
        }
    }

    /** @return array{quantity: string, amount: string} */
    private function weightedAverageTransferPool(int $companyId, int $itemId, int $warehouseId, Carbon $fromDate, Carbon $toDate): array
    {
        $opening = $this->weightedAverageOpeningPool($companyId, $itemId, $warehouseId, $fromDate);
        $receipts = InventoryReceiptLine::query()
            ->where('item_id', $itemId)
            ->whereHas('receipt', function ($query) use ($companyId, $warehouseId, $fromDate, $toDate): void {
                $query->where('company_id', $companyId)
                    ->where('is_posted', true)
                    ->whereBetween('voucher_date', [$fromDate, $toDate])
                    ->where('warehouse_id', $warehouseId);
            })
            ->selectRaw('COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(amount), 0) as amt')
            ->first();

        return [
            'quantity' => InventoryCostArithmetic::addQuantity($opening['quantity'], (string) ($receipts->qty ?? '0')),
            'amount' => DecimalMoney::add($opening['amount'], (string) ($receipts->amt ?? '0')),
        ];
    }

    /** @return array{quantity: string, amount: string} */
    private function weightedAverageOpeningPool(int $companyId, int $itemId, int $warehouseId, Carbon $fromDate): array
    {
        $opening = DB::table('opening_balance_inventory_lines as line')
            ->join('opening_balance_packages as package', 'line.package_id', '=', 'package.id')
            ->where('package.company_id', $companyId)
            ->where('package.status', 'confirmed')
            ->where('line.item_id', $itemId)
            ->where('line.warehouse_id', $warehouseId)
            ->whereDate('package.effective_date', '<=', $fromDate)
            ->selectRaw('COALESCE(SUM(line.quantity), 0) as qty, COALESCE(SUM(line.total_value), 0) as amt')
            ->first();
        $receipts = InventoryReceiptLine::query()
            ->where('item_id', $itemId)
            ->whereHas('receipt', function ($query) use ($companyId, $warehouseId, $fromDate): void {
                $query->where('company_id', $companyId)->where('is_posted', true)->where('voucher_date', '<', $fromDate)->where('warehouse_id', $warehouseId);
            })
            ->selectRaw('COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(amount), 0) as amt')
            ->first();
        $issues = InventoryIssueLine::query()
            ->where('item_id', $itemId)
            ->whereHas('issue', function ($query) use ($companyId, $warehouseId, $fromDate): void {
                $query->where('company_id', $companyId)->where('is_posted', true)->where('voucher_date', '<', $fromDate)->where('warehouse_id', $warehouseId);
            })
            ->selectRaw('COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(amount), 0) as amt')
            ->first();
        $transfers = $this->transferMovementTotals($companyId, $itemId, $warehouseId, null, $fromDate);

        $inQuantity = InventoryCostArithmetic::addQuantity((string) ($opening->qty ?? '0'), (string) ($receipts->qty ?? '0'));
        $inAmount = DecimalMoney::add((string) ($opening->amt ?? '0'), (string) ($receipts->amt ?? '0'));

        return [
            'quantity' => InventoryCostArithmetic::subtractQuantity(
                InventoryCostArithmetic::addQuantity($inQuantity, $transfers['in_quantity']),
                InventoryCostArithmetic::addQuantity((string) ($issues->qty ?? '0'), $transfers['out_quantity']),
            ),
            'amount' => DecimalMoney::subtract(
                DecimalMoney::add($inAmount, $transfers['in_amount']),
                DecimalMoney::add((string) ($issues->amt ?? '0'), $transfers['out_amount']),
            ),
        ];
    }

    /** @return array{in_quantity: string, out_quantity: string, in_amount: string, out_amount: string} */
    private function transferMovementTotals(int $companyId, int $itemId, ?int $warehouseId, ?Carbon $fromDate, Carbon $toDate): array
    {
        $query = InventoryMovementEvent::query()
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->whereIn('movement_type', [
                'transfer_out',
                'transfer_in',
                'transfer_out_reversal',
                'transfer_in_reversal',
            ])
            ->when($warehouseId, fn ($builder) => $builder->where('warehouse_id', $warehouseId));
        if ($fromDate === null) {
            $query->where('movement_date', '<', $toDate->toDateString());
        } else {
            $query->whereBetween('movement_date', [$fromDate->toDateString(), $toDate->toDateString()]);
        }
        $events = $query->get(['quantity_delta', 'amount_delta']);
        $inQuantity = InventoryCostArithmetic::ZERO_QUANTITY;
        $outQuantity = InventoryCostArithmetic::ZERO_QUANTITY;
        $inAmount = DecimalMoney::ZERO;
        $outAmount = DecimalMoney::ZERO;
        foreach ($events as $event) {
            if ($event->amount_delta === null) {
                throw ValidationException::withMessages([
                    'inventory_transfers' => 'Có phiếu điều chuyển của kỳ trước chưa được tính giá; hãy tính lại từ kỳ có phát sinh điều chuyển.',
                ]);
            }
            $quantity = (string) $event->quantity_delta;
            $amount = DecimalMoney::normalize((string) $event->amount_delta);
            if (str_starts_with($quantity, '-')) {
                $outQuantity = InventoryCostArithmetic::addQuantity($outQuantity, substr($quantity, 1));
            } else {
                $inQuantity = InventoryCostArithmetic::addQuantity($inQuantity, $quantity);
            }
            if (DecimalMoney::compare($amount, DecimalMoney::ZERO) < 0) {
                $outAmount = DecimalMoney::add($outAmount, DecimalMoney::abs($amount));
            } else {
                $inAmount = DecimalMoney::add($inAmount, $amount);
            }
        }

        return [
            'in_quantity' => $inQuantity,
            'out_quantity' => $outQuantity,
            'in_amount' => $inAmount,
            'out_amount' => $outAmount,
        ];
    }

    /**
     * Helper to synchronize InventoryIssue total_amount and its JournalEntry lines
     */
    protected function syncIssueAndJournalEntry(int $companyId, int $issueId): void
    {
        // Do not trust a raw linked ID.  Valuation is normally reached from
        // tenant-filtered issue lines, but this explicit lookup keeps the
        // private synchronization boundary safe for jobs and future callers.
        $issue = InventoryIssue::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->with('lines')
            ->lockForUpdate()
            ->find($issueId);
        if (! $issue) {
            throw ValidationException::withMessages([
                'issue_id' => 'Phiếu xuất kho không thuộc đơn vị đang tính giá.',
            ]);
        }

        $this->periodGuard->assertOpen($companyId, $issue->posting_date ?? $issue->voucher_date, 'cập nhật giá vốn phiếu xuất kho');

        $je = null;
        if ($issue->journal_entry_id) {
            $je = JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->with('lines')
                ->lockForUpdate()
                ->find($issue->journal_entry_id);

            if (! $je) {
                // A cross-tenant or stale raw journal id must not be silently
                // ignored: doing so would mutate the issue without its GL.
                throw ValidationException::withMessages([
                    'journal_entry_id' => 'Bút toán liên kết không thuộc đơn vị đang tính giá.',
                ]);
            }

            $this->periodGuard->assertOpen($companyId, $je->posting_date, 'cập nhật giá vốn bút toán liên kết');

            // A posted (or otherwise finalized) journal is accounting history,
            // not a recalculation cache.  Replacing its lines in production
            // would erase the original GL evidence without a reversal or a
            // new controlled posting.  The historical test/diagnostic harness
            // may still exercise the legacy rewrite, but the production
            // boundary must fail closed until an approved cost-adjustment
            // workflow exists.
            if (in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)
                && $je->status !== 'draft') {
                throw ValidationException::withMessages([
                    'journal_entry_id' => 'Không thể ghi đè dòng bút toán đã kết thúc trong production; cần luồng điều chỉnh/đảo giá vốn được kiểm soát.',
                ]);
            }
        }

        $newTotal = DecimalMoney::sum($issue->lines->map(fn (InventoryIssueLine $line) => (string) $line->amount));
        $issue->total_amount = $newTotal;
        $issue->save();

        if ($je) {
            // This remains a deliberately narrow legacy rewrite because the
            // canonical JournalEntryService refuses edits to posted entries.
            // Both source and JE are tenant-scoped, row-locked and guarded
            // above before any delete/create/save takes place.
            // Delete old lines and re-create updated lines
            $je->lines()->delete();

            $glLines = [];
            foreach ($issue->lines as $line) {
                if (DecimalMoney::compare((string) $line->amount, DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $line->debit_account,
                        'description' => $line->description ?? ($issue->description ?? 'Xuất kho hàng hóa'),
                        'debit_amount' => $line->amount,
                        'credit_amount' => 0,
                    ];
                    $glLines[] = [
                        'account_code' => $line->credit_account,
                        'description' => $line->description ?? ($issue->description ?? 'Xuất kho hàng hóa'),
                        'debit_amount' => 0,
                        'credit_amount' => $line->amount,
                    ];
                }
            }

            foreach ($glLines as $glLine) {
                $je->lines()->create($glLine);
            }

            $je->total_amount = $newTotal;
            $je->save();
        }
    }

    /**
     * Cost calculation rewrites source issue amounts and may rewrite a
     * linked journal. Keep both public algorithm entry points behind the same
     * tenant and production accounting boundary; callers must not bypass it
     * by invoking calculateFifo()/calculateMonthlyWeightedAverage() directly.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function normalizeCostCalculationParams(array $params): array
    {
        $params['company_id'] = InventoryTenantGuard::companyId($params);

        if (config('accounting.enforce_inventory_posting_account_mappings', true)) {
            throw ValidationException::withMessages([
                'account_mappings' => 'Không thể tính lại giá vốn trong production khi chưa có mapping tài khoản được phê duyệt cho phân hệ kho.',
            ]);
        }

        return $params;
    }
}
