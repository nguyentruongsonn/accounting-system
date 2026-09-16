<?php

namespace App\Services;

use App\Models\PurchaseExpenseAllocation;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Support\DecimalMoney;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseExpenseAllocationService
{
    public function __construct(
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly AuditService $auditService,
    ) {}

    public function candidates(
        int $companyId,
        ?int $sourceInvoiceId = null,
        ?int $supplierId = null,
        ?string $asOfDate = null,
        ?string $search = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        if ($sourceInvoiceId !== null) {
            $source = PurchaseInvoice::query()
                ->where('company_id', $companyId)
                ->findOrFail($sourceInvoiceId);

            if (! $this->isExpenseInvoice($source)) {
                throw ValidationException::withMessages([
                    'source_invoice_id' => 'Chứng từ nguồn phải là chứng từ mua dịch vụ hoặc chi phí mua hàng.',
                ]);
            }
        }

        $query = PurchaseInvoice::query()
            ->where('company_id', $companyId)
            ->whereNotIn(DB::raw('LOWER(status)'), ['posted', 'voided', 'void', 'cancelled', 'canceled'])
            ->where(function (Builder $scope): void {
                $scope->where('is_purchase_expense', false)
                    ->orWhereNull('is_purchase_expense');
            })
            ->whereHas('lines', fn (Builder $lines): Builder => $lines->whereNotNull('item_id'))
            ->with(['supplier', 'lines.item'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        if ($sourceInvoiceId !== null) {
            $query->where('id', '<>', $sourceInvoiceId);
        }

        if ($supplierId !== null) {
            $query->where('supplier_id', $supplierId);
        }

        if ($asOfDate !== null) {
            $query->whereDate('accounting_date', '<=', Carbon::parse($asOfDate)->toDateString());
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $scope) use ($term): void {
                $scope->where('invoice_number', 'like', "%{$term}%")
                    ->orWhere('supplier_name', 'like', "%{$term}%")
                    ->orWhereHas('supplier', fn (Builder $supplier): Builder => $supplier->where('name', 'like', "%{$term}%"));
            });
        }

        $paginator = $query->paginate(max(1, min($perPage, 100)));
        $lineIds = $paginator->getCollection()
            ->flatMap(fn (PurchaseInvoice $invoice) => $invoice->lines->pluck('id'))
            ->values();
        $allocatedByLine = $lineIds->isEmpty()
            ? collect()
            : tap(PurchaseExpenseAllocation::query()
                ->where('company_id', $companyId)
                ->whereIn('target_purchase_invoice_line_id', $lineIds), function ($allocations) use ($sourceInvoiceId): void {
                    if ($sourceInvoiceId !== null) {
                        $allocations->where('source_purchase_invoice_id', '<>', $sourceInvoiceId);
                    }
                })
                ->select('target_purchase_invoice_line_id')
                ->selectRaw('SUM(allocated_amount) AS allocated_amount')
                ->groupBy('target_purchase_invoice_line_id')
                ->pluck('allocated_amount', 'target_purchase_invoice_line_id');

        $paginator->setCollection($paginator->getCollection()->map(function (PurchaseInvoice $invoice) use ($allocatedByLine): array {
            $lines = $invoice->lines
                ->filter(fn ($line): bool => $line->item_id !== null)
                ->map(function ($line) use ($allocatedByLine): array {
                    $allocated = DecimalMoney::normalize($allocatedByLine->get($line->id, DecimalMoney::ZERO));
                    $stockValue = DecimalMoney::normalize($line->getRawOriginal('stock_value') ?? DecimalMoney::ZERO);

                    return [
                        'id' => $line->id,
                        'item_id' => $line->item_id,
                        'item_code' => $line->item?->code,
                        'item_name' => $line->item?->name ?? $line->description,
                        'quantity' => DecimalMoney::normalize($line->getRawOriginal('quantity') ?? DecimalMoney::ZERO),
                        'stock_value' => $stockValue,
                        'allocated_amount' => $allocated,
                        'remaining_allocatable_amount' => DecimalMoney::maxZero(DecimalMoney::subtract($stockValue, $allocated)),
                    ];
                })
                ->filter(fn (array $line): bool => DecimalMoney::compare($line['remaining_allocatable_amount'], DecimalMoney::ZERO) > 0)
                ->values();

            $remaining = DecimalMoney::sum($lines->pluck('remaining_allocatable_amount'));
            $allocated = DecimalMoney::sum($lines->pluck('allocated_amount'));

            return [
                'id' => $invoice->id,
                'voucher_number' => $invoice->invoice_number,
                'voucher_date' => $invoice->invoice_date?->toDateString(),
                'supplier_id' => $invoice->supplier_id,
                'supplier_name' => $invoice->supplier_name ?? $invoice->supplier?->name,
                'sub_total' => DecimalMoney::normalize($invoice->getRawOriginal('sub_total') ?? DecimalMoney::ZERO),
                'already_allocated_amount' => $allocated,
                'remaining_allocatable_amount' => $remaining,
                'lines' => $lines->all(),
            ];
        })->filter(fn (array $invoice): bool => $invoice['lines'] !== [])->values());

        return $paginator;
    }

    public function isExpenseInvoice(PurchaseInvoice $invoice): bool
    {
        return (bool) $invoice->is_purchase_expense
            || in_array((string) $invoice->voucher_type, ['service', '5. Mua dịch vụ'], true);
    }

    /**
     * Replace the source invoice's draft allocations and recalculate every
     * affected target line in the same transaction as the source invoice.
     * The caller must already be inside the purchase-invoice transaction.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function replaceForSource(PurchaseInvoice $source, array $rows): void
    {
        if (! $this->isExpenseInvoice($source)) {
            if ($rows !== []) {
                throw ValidationException::withMessages([
                    'expense_allocations' => 'Chỉ chứng từ mua dịch vụ hoặc chi phí mua hàng mới được phân bổ.',
                ]);
            }

            return;
        }

        $normalisedRows = array_values($rows);
        $total = DecimalMoney::sum(array_map(
            fn (array $row): string => DecimalMoney::normalize($row['allocated_amount'] ?? DecimalMoney::ZERO),
            $normalisedRows,
        ));
        $sourceExpense = DecimalMoney::normalize($source->getRawOriginal('purchase_expense') ?? DecimalMoney::ZERO);
        if (DecimalMoney::compare($total, $sourceExpense) > 0) {
            throw ValidationException::withMessages([
                'expense_allocations' => 'Tổng phân bổ không được lớn hơn chi phí mua hàng của chứng từ nguồn.',
            ]);
        }

        $beforeAudit = PurchaseExpenseAllocation::query()
            ->where('company_id', $source->company_id)
            ->where('source_purchase_invoice_id', $source->id)
            ->get()
            ->map(fn (PurchaseExpenseAllocation $allocation): array => [
                'target_purchase_invoice_id' => $allocation->target_purchase_invoice_id,
                'target_purchase_invoice_line_id' => $allocation->target_purchase_invoice_line_id,
                'allocated_amount' => (string) $allocation->getRawOriginal('allocated_amount'),
                'allocation_method' => $allocation->allocation_method,
            ])
            ->values()
            ->all();

        $seenLines = [];
        foreach ($normalisedRows as $index => $row) {
            $lineId = (int) ($row['target_purchase_invoice_line_id'] ?? 0);
            $targetId = (int) ($row['target_purchase_invoice_id'] ?? 0);
            $amount = DecimalMoney::normalize($row['allocated_amount'] ?? DecimalMoney::ZERO);
            $field = "expense_allocations.{$index}";

            if ($targetId < 1) {
                throw ValidationException::withMessages(["{$field}.target_purchase_invoice_id" => 'Chứng từ đích không hợp lệ.']);
            }
            if ($lineId < 1) {
                throw ValidationException::withMessages(["{$field}.target_purchase_invoice_line_id" => 'Phải chọn dòng hàng đích để phân bổ chi phí.']);
            }
            if (isset($seenLines[$lineId])) {
                throw ValidationException::withMessages(["{$field}.target_purchase_invoice_line_id" => 'Không được phân bổ trùng một dòng hàng.']);
            }
            $seenLines[$lineId] = true;

            $target = PurchaseInvoice::query()
                ->where('company_id', $source->company_id)
                ->whereKey($targetId)
                ->with('lines')
                ->lockForUpdate()
                ->first();
            if (! $target) {
                throw ValidationException::withMessages(["{$field}.target_purchase_invoice_id" => 'Chứng từ đích không thuộc doanh nghiệp hiện tại.']);
            }
            if ($target->id === $source->id) {
                throw ValidationException::withMessages(["{$field}.target_purchase_invoice_id" => 'Không được chọn chính chứng từ nguồn.']);
            }
            if ($this->isExpenseInvoice($target)) {
                throw ValidationException::withMessages(["{$field}.target_purchase_invoice_id" => 'Chứng từ đích phải là chứng từ nhập hàng tồn kho.']);
            }
            $status = strtolower(trim((string) $target->status));
            if ($target->is_posted || $status === 'posted') {
                throw ValidationException::withMessages(["{$field}.target_purchase_invoice_id" => 'Không thể phân bổ vào chứng từ đích đã ghi sổ.']);
            }
            if (in_array($status, ['voided', 'void', 'cancelled', 'canceled'], true)) {
                throw ValidationException::withMessages(["{$field}.target_purchase_invoice_id" => 'Không thể phân bổ vào chứng từ đích đã hủy.']);
            }

            $this->periodGuard->assertOpen(
                (int) $source->company_id,
                $target->accounting_date ?? $target->invoice_date,
                'phân bổ chi phí mua hàng',
            );
            $targetLine = $target->lines->firstWhere('id', $lineId);
            if (! $targetLine || $targetLine->item_id === null) {
                throw ValidationException::withMessages(["{$field}.target_purchase_invoice_line_id" => 'Dòng đích không thuộc chứng từ hoặc không phải dòng hàng tồn kho.']);
            }

            $allAllocated = DecimalMoney::normalize(DB::table('purchase_expense_allocations')
                ->where('company_id', $source->company_id)
                ->where('target_purchase_invoice_line_id', $lineId)
                ->sum('allocated_amount'));
            $baseStockValue = DecimalMoney::maxZero(DecimalMoney::subtract(
                $targetLine->getRawOriginal('stock_value') ?? DecimalMoney::ZERO,
                $allAllocated,
            ));
            $otherAllocated = DecimalMoney::normalize(DB::table('purchase_expense_allocations')
                ->where('company_id', $source->company_id)
                ->where('target_purchase_invoice_line_id', $lineId)
                ->where('source_purchase_invoice_id', '<>', $source->id)
                ->sum('allocated_amount'));
            $remaining = DecimalMoney::maxZero(DecimalMoney::subtract($baseStockValue, $otherAllocated));
            if (DecimalMoney::compare($amount, $remaining) > 0) {
                throw ValidationException::withMessages([
                    "{$field}.allocated_amount" => "Số tiền phân bổ vượt số còn lại của dòng đích ({$remaining}).",
                ]);
            }
        }

        $baselineByLine = [];
        $sourceLineIds = PurchaseExpenseAllocation::query()
            ->where('company_id', $source->company_id)
            ->where('source_purchase_invoice_id', $source->id)
            ->pluck('target_purchase_invoice_line_id')
            ->filter()
            ->unique()
            ->values();
        $affectedLineIds = $sourceLineIds->merge(array_keys($seenLines))->unique()->values();
        if ($affectedLineIds->isNotEmpty()) {
            $affectedLines = PurchaseInvoiceLine::query()
                ->whereIn('id', $affectedLineIds)
                ->whereHas('purchaseInvoice', fn (Builder $query): Builder => $query->where('company_id', $source->company_id))
                ->lockForUpdate()
                ->get();
            foreach ($affectedLines as $line) {
                $allocated = DecimalMoney::normalize(DB::table('purchase_expense_allocations')
                    ->where('company_id', $source->company_id)
                    ->where('target_purchase_invoice_line_id', $line->id)
                    ->sum('allocated_amount'));
                $baselineByLine[$line->id] = [
                    'invoice_id' => $line->purchase_invoice_id,
                    'net_amount' => DecimalMoney::subtract(
                        $line->getRawOriginal('amount') ?? DecimalMoney::ZERO,
                        $line->getRawOriginal('discount_amount') ?? DecimalMoney::ZERO,
                    ),
                    'base_expense' => DecimalMoney::maxZero(DecimalMoney::subtract(
                        $line->getRawOriginal('purchase_expense') ?? DecimalMoney::ZERO,
                        $allocated,
                    )),
                ];
            }
        }

        PurchaseExpenseAllocation::query()
            ->where('company_id', $source->company_id)
            ->where('source_purchase_invoice_id', $source->id)
            ->delete();

        foreach ($normalisedRows as $row) {
            PurchaseExpenseAllocation::create([
                'company_id' => $source->company_id,
                'source_purchase_invoice_id' => $source->id,
                'target_purchase_invoice_id' => (int) $row['target_purchase_invoice_id'],
                'target_purchase_invoice_line_id' => (int) $row['target_purchase_invoice_line_id'],
                'allocated_amount' => DecimalMoney::normalize($row['allocated_amount']),
                'allocation_method' => (string) ($row['allocation_method'] ?? 'value'),
                'effective_date' => $source->accounting_date ?? $source->invoice_date,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
        }

        $afterAudit = PurchaseExpenseAllocation::query()
            ->where('company_id', $source->company_id)
            ->where('source_purchase_invoice_id', $source->id)
            ->get()
            ->map(fn (PurchaseExpenseAllocation $allocation): array => [
                'target_purchase_invoice_id' => $allocation->target_purchase_invoice_id,
                'target_purchase_invoice_line_id' => $allocation->target_purchase_invoice_line_id,
                'allocated_amount' => (string) $allocation->getRawOriginal('allocated_amount'),
                'allocation_method' => $allocation->allocation_method,
            ])
            ->values()
            ->all();

        foreach ($baselineByLine as $lineId => $baseline) {
            $line = PurchaseInvoiceLine::query()->lockForUpdate()->find($lineId);
            if (! $line) {
                continue;
            }
            $allocated = DecimalMoney::normalize(DB::table('purchase_expense_allocations')
                ->where('company_id', $source->company_id)
                ->where('target_purchase_invoice_line_id', $lineId)
                ->sum('allocated_amount'));
            $lineExpense = DecimalMoney::add($baseline['base_expense'], $allocated);
            $line->purchase_expense = $lineExpense;
            $line->stock_value = DecimalMoney::add($baseline['net_amount'], $lineExpense);
            $line->save();
        }

        foreach (array_unique(array_column($baselineByLine, 'invoice_id')) as $targetId) {
            $target = PurchaseInvoice::query()
                ->where('company_id', $source->company_id)
                ->whereKey($targetId)
                ->with('lines')
                ->lockForUpdate()
                ->first();
            if (! $target) {
                continue;
            }
            $target->purchase_expense = DecimalMoney::sum($target->lines->map(fn ($line): string => DecimalMoney::normalize($line->getRawOriginal('purchase_expense') ?? DecimalMoney::ZERO)));
            $target->total_stock_value = DecimalMoney::sum($target->lines->map(fn ($line): string => DecimalMoney::normalize($line->getRawOriginal('stock_value') ?? DecimalMoney::ZERO)));
            $target->save();
        }

        $this->auditService->record(
            $source,
            'purchase_expense_allocation.replaced',
            ['allocations' => $beforeAudit],
            ['allocations' => $afterAudit],
            metadata: [
                'source_purchase_invoice_id' => $source->id,
                'allocation_count' => count($afterAudit),
            ],
        );
    }

    /**
     * Remove allocations when an expense source is unposted or voided. A
     * posted target is deliberately blocked: changing its stock value would
     * bypass the target's own reversal workflow.
     */
    public function removeForSource(PurchaseInvoice $source, string $lifecycleAction): void
    {
        $allocations = PurchaseExpenseAllocation::query()
            ->where('company_id', $source->company_id)
            ->where('source_purchase_invoice_id', $source->id)
            ->get();

        if ($allocations->isEmpty()) {
            return;
        }

        $beforeAudit = $allocations->map(fn (PurchaseExpenseAllocation $allocation): array => [
            'target_purchase_invoice_id' => $allocation->target_purchase_invoice_id,
            'target_purchase_invoice_line_id' => $allocation->target_purchase_invoice_line_id,
            'allocated_amount' => (string) $allocation->getRawOriginal('allocated_amount'),
            'allocation_method' => $allocation->allocation_method,
        ])->values()->all();

        $targetIds = $allocations->pluck('target_purchase_invoice_id')->unique()->values();
        $targets = PurchaseInvoice::query()
            ->where('company_id', $source->company_id)
            ->whereIn('id', $targetIds)
            ->with('lines')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($targetIds as $targetId) {
            $target = $targets->get($targetId);
            if (! $target) {
                throw new ValidationException([
                    'expense_allocations' => 'Không tìm thấy chứng từ đích cùng doanh nghiệp để hoàn tác phân bổ.',
                ]);
            }

            if ($target->is_posted || strtolower(trim((string) $target->status)) === 'posted') {
                throw new ConflictHttpException('Không thể hoàn tác phân bổ khi chứng từ đích đã ghi sổ. Hãy bỏ ghi sổ chứng từ đích trước.');
            }
        }

        $lineIds = $allocations->pluck('target_purchase_invoice_line_id')->filter()->unique()->values();
        $lines = PurchaseInvoiceLine::query()
            ->whereIn('id', $lineIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($allocations as $allocation) {
            $line = $lines->get($allocation->target_purchase_invoice_line_id);
            if (! $line) {
                throw new ValidationException([
                    'expense_allocations' => 'Không tìm thấy dòng hàng đích để hoàn tác phân bổ.',
                ]);
            }

            $lineAllocated = DecimalMoney::normalize(DB::table('purchase_expense_allocations')
                ->where('company_id', $source->company_id)
                ->where('target_purchase_invoice_line_id', $line->id)
                ->sum('allocated_amount'));
            $baseExpense = DecimalMoney::maxZero(DecimalMoney::subtract(
                $line->getRawOriginal('purchase_expense') ?? DecimalMoney::ZERO,
                $lineAllocated,
            ));
            $line->purchase_expense = $baseExpense;
            $line->stock_value = DecimalMoney::add(
                DecimalMoney::subtract(
                    $line->getRawOriginal('amount') ?? DecimalMoney::ZERO,
                    $line->getRawOriginal('discount_amount') ?? DecimalMoney::ZERO,
                ),
                $baseExpense,
            );
            $line->save();
        }

        PurchaseExpenseAllocation::query()
            ->where('company_id', $source->company_id)
            ->where('source_purchase_invoice_id', $source->id)
            ->delete();

        foreach ($targets as $target) {
            $target->load('lines');
            $target->purchase_expense = DecimalMoney::sum($target->lines->map(fn ($line): string => DecimalMoney::normalize($line->getRawOriginal('purchase_expense') ?? DecimalMoney::ZERO)));
            $target->total_stock_value = DecimalMoney::sum($target->lines->map(fn ($line): string => DecimalMoney::normalize($line->getRawOriginal('stock_value') ?? DecimalMoney::ZERO)));
            $target->save();
        }

        $this->auditService->record(
            $source,
            'purchase_expense_allocation.removed',
            ['allocations' => $beforeAudit],
            [],
            metadata: [
                'source_purchase_invoice_id' => $source->id,
                'lifecycle_action' => $lifecycleAction,
                'allocation_count' => count($beforeAudit),
            ],
        );
    }
}
