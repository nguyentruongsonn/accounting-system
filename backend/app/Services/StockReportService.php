<?php

namespace App\Services;

use App\Models\Item;
use App\Support\DecimalMoney;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StockReportService
{
    public function generateReport(int $companyId, array $filters = []): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $fromDate = ! empty($filters['from_date']) ? Carbon::parse($filters['from_date'])->toDateString() : null;
        $toDate = ! empty($filters['to_date']) ? Carbon::parse($filters['to_date'])->toDateString() : null;
        $warehouseId = isset($filters['warehouse_id']) && $filters['warehouse_id'] !== '' ? (int) $filters['warehouse_id'] : null;
        $items = Item::query()->where('company_id', $companyId)
            ->when(! empty($filters['item_id']), fn ($query) => $query->whereKey((int) $filters['item_id']))->get();

        $report = [];
        foreach ($items as $item) {
            $opening = $this->openingInventory($companyId, $item->id, $warehouseId, $fromDate, $toDate);
            $openingReceipts = $fromDate ? $this->movement('inventory_receipt_lines', 'inventory_receipts', 'inventory_receipt_id', $companyId, $item->id, $warehouseId, null, $fromDate, true) : $this->zeroMovement();
            $openingIssues = $fromDate ? $this->movement('inventory_issue_lines', 'inventory_issues', 'inventory_issue_id', $companyId, $item->id, $warehouseId, null, $fromDate, true) : $this->zeroMovement();
            $openingEvents = $fromDate ? $this->eventMovement($companyId, $item->id, $warehouseId, null, $fromDate, true) : $this->zeroMovement();
            $receipts = $this->movement('inventory_receipt_lines', 'inventory_receipts', 'inventory_receipt_id', $companyId, $item->id, $warehouseId, $fromDate, $toDate);
            $issues = $this->movement('inventory_issue_lines', 'inventory_issues', 'inventory_issue_id', $companyId, $item->id, $warehouseId, $fromDate, $toDate);
            $events = $this->eventMovement($companyId, $item->id, $warehouseId, $fromDate, $toDate);

            $inQty = $receipts['quantity']->plus($events['in_quantity']);
            $outQty = $issues['quantity']->plus($events['out_quantity']);
            $inAmount = DecimalMoney::add($receipts['amount'], $events['in_amount']);
            $outAmount = DecimalMoney::add($issues['amount'], $events['out_amount']);

            $openingQty = $opening['quantity']->plus($openingReceipts['quantity'])->minus($openingIssues['quantity'])->plus($openingEvents['quantity']);
            $openingAmount = DecimalMoney::add(
                DecimalMoney::subtract(DecimalMoney::add($opening['amount'], $openingReceipts['amount']), $openingIssues['amount']),
                $openingEvents['amount'],
            );
            $endQty = $openingQty->plus($inQty)->minus($outQty);
            $endAmount = DecimalMoney::add(
                DecimalMoney::add(DecimalMoney::subtract($openingAmount, $issues['amount']), $receipts['amount']),
                $events['amount'],
            );

            if ($this->hasMovement($openingQty, $openingAmount, ['quantity' => $inQty, 'amount' => $receipts['amount']], ['quantity' => $outQty, 'amount' => $issues['amount']], $endQty, $endAmount)) {
                $report[] = [
                    'item_id' => $item->id, 'item_code' => $item->code, 'item_name' => $item->name, 'unit' => $item->unit ?? 'Cái',
                    'opening_qty' => $this->quantity($openingQty), 'opening_amt' => $openingAmount,
                    'in_qty' => $this->quantity($inQty), 'in_amt' => $inAmount,
                    'out_qty' => $this->quantity($outQty), 'out_amt' => $outAmount,
                    'end_qty' => $this->quantity($endQty), 'end_amt' => $endAmount,
                ];
            }
        }

        return $report;
    }

    /** @return array{quantity: BigDecimal, amount: string} */
    private function movement(string $lineTable, string $headerTable, string $foreignKey, int $companyId, int $itemId, ?int $warehouseId, ?string $fromDate, ?string $toDate, bool $beforeTo = false): array
    {
        $query = DB::table("{$lineTable} as line")->join("{$headerTable} as header", "line.{$foreignKey}", '=', 'header.id')
            ->where('header.company_id', $companyId)->where('header.is_posted', true)->where('line.item_id', $itemId);
        // Inventory reports use the accounting/posting date as their period
        // basis. Older rows may not have one, so retain the voucher date only
        // as an explicit compatibility fallback.
        $dateColumn = Schema::hasColumn($headerTable, 'posting_date')
            ? DB::raw('COALESCE(header.posting_date, header.voucher_date)')
            : 'header.voucher_date';
        if ($beforeTo && $toDate) {
            $query->whereDate($dateColumn, '<', $toDate);
        } else {
            if ($fromDate) {
                $query->whereDate($dateColumn, '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate($dateColumn, '<=', $toDate);
            }
        }
        if ($warehouseId !== null) {
            $query->where(function ($q) use ($warehouseId): void {
                $q->where('line.warehouse_id', $warehouseId)->orWhere(function ($legacy) use ($warehouseId): void {
                    $legacy->whereNull('line.warehouse_id')->where('header.warehouse_id', $warehouseId);
                });
            });
        }
        $totals = $query->selectRaw('COALESCE(SUM(line.quantity), 0) as quantity, COALESCE(SUM(line.amount), 0) as amount')->first();

        return ['quantity' => BigDecimal::of((string) ($totals->quantity ?? 0))->toScale(4), 'amount' => DecimalMoney::normalize((string) ($totals->amount ?? 0))];
    }

    /** @return array{quantity: BigDecimal, amount: string} */
    private function openingInventory(int $companyId, int $itemId, ?int $warehouseId, ?string $fromDate, ?string $toDate): array
    {
        if (! Schema::hasTable('opening_balance_packages') || ! Schema::hasTable('opening_balance_inventory_lines')) {
            return $this->zeroMovement();
        }
        $query = DB::table('opening_balance_inventory_lines as line')->join('opening_balance_packages as package', 'line.package_id', '=', 'package.id')
            ->where('package.company_id', $companyId)->where('package.status', 'confirmed')->where('line.item_id', $itemId);
        if ($warehouseId !== null) {
            $query->where('line.warehouse_id', $warehouseId);
        }
        if ($fromDate) {
            $query->whereDate('package.effective_date', '<=', $fromDate);
        } elseif ($toDate) {
            $query->whereDate('package.effective_date', '<=', $toDate);
        }
        $totals = $query->selectRaw('COALESCE(SUM(line.quantity), 0) as quantity, COALESCE(SUM(line.total_value), 0) as amount')->first();

        return ['quantity' => BigDecimal::of((string) ($totals->quantity ?? 0))->toScale(4), 'amount' => DecimalMoney::normalize((string) ($totals->amount ?? 0))];
    }

    /** @return array{quantity: BigDecimal, amount: string, in_quantity: BigDecimal, out_quantity: BigDecimal, in_amount: string, out_amount: string} */
    private function eventMovement(int $companyId, int $itemId, ?int $warehouseId, ?string $fromDate, ?string $toDate, bool $beforeTo = false): array
    {
        if (! Schema::hasTable('inventory_movement_events')) {
            return $this->zeroMovement();
        }

        $query = DB::table('inventory_movement_events')
            ->where('company_id', $companyId)
            ->where('item_id', $itemId);
        if ($beforeTo && $toDate) {
            $query->whereDate('movement_date', '<', $toDate);
        } else {
            if ($fromDate) {
                $query->whereDate('movement_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('movement_date', '<=', $toDate);
            }
        }
        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }
        $totals = $query->selectRaw(
            'COALESCE(SUM(quantity_delta), 0) as quantity, '
            .'COALESCE(SUM(amount_delta), 0) as amount, '
            .'COALESCE(SUM(CASE WHEN quantity_delta > 0 THEN quantity_delta ELSE 0 END), 0) as in_quantity, '
            .'COALESCE(SUM(CASE WHEN quantity_delta < 0 THEN -quantity_delta ELSE 0 END), 0) as out_quantity, '
            .'COALESCE(SUM(CASE WHEN amount_delta > 0 THEN amount_delta ELSE 0 END), 0) as in_amount, '
            .'COALESCE(SUM(CASE WHEN amount_delta < 0 THEN -amount_delta ELSE 0 END), 0) as out_amount'
        )->first();

        return [
            'quantity' => BigDecimal::of((string) ($totals->quantity ?? 0))->toScale(4),
            'amount' => DecimalMoney::normalize((string) ($totals->amount ?? 0)),
            'in_quantity' => BigDecimal::of((string) ($totals->in_quantity ?? 0))->toScale(4),
            'out_quantity' => BigDecimal::of((string) ($totals->out_quantity ?? 0))->toScale(4),
            'in_amount' => DecimalMoney::normalize((string) ($totals->in_amount ?? 0)),
            'out_amount' => DecimalMoney::normalize((string) ($totals->out_amount ?? 0)),
        ];
    }

    /** @return array{quantity: BigDecimal, amount: string, in_quantity: BigDecimal, out_quantity: BigDecimal, in_amount: string, out_amount: string} */
    private function zeroMovement(): array
    {
        return [
            'quantity' => BigDecimal::zero()->toScale(4),
            'amount' => DecimalMoney::ZERO,
            'in_quantity' => BigDecimal::zero()->toScale(4),
            'out_quantity' => BigDecimal::zero()->toScale(4),
            'in_amount' => DecimalMoney::ZERO,
            'out_amount' => DecimalMoney::ZERO,
        ];
    }

    /** @param array{quantity: BigDecimal, amount: string} $receipts @param array{quantity: BigDecimal, amount: string} $issues */
    private function hasMovement(BigDecimal $openingQty, string $openingAmount, array $receipts, array $issues, BigDecimal $endQty, string $endAmount): bool
    {
        return ! $openingQty->isZero() || DecimalMoney::compare($openingAmount, DecimalMoney::ZERO) !== 0 || ! $receipts['quantity']->isZero() || DecimalMoney::compare($receipts['amount'], DecimalMoney::ZERO) !== 0 || ! $issues['quantity']->isZero() || DecimalMoney::compare($issues['amount'], DecimalMoney::ZERO) !== 0 || ! $endQty->isZero() || DecimalMoney::compare($endAmount, DecimalMoney::ZERO) !== 0;
    }

    private function quantity(BigDecimal $value): string
    {
        return $value->toScale(4)->__toString();
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }

        return $companyId;
    }
}
