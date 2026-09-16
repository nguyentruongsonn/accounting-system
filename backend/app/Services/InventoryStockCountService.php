<?php

namespace App\Services;

use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\InventoryStockCount;
use App\Models\VoucherReference;
use App\Support\InventoryTenantGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryStockCountService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly InventoryAvailabilityService $availabilityService,
        private readonly CoreDocumentPostingAuthorizer $postingAuthorizer,
    ) {}

    public function getAll(array $filters = [])
    {
        $companyId = InventoryTenantGuard::companyId($filters);
        $query = InventoryStockCount::with(['lines.item', 'warehouse'])
            ->where('company_id', $companyId)
            ->orderByDesc('count_date')
            ->orderByDesc('id');

        if (! empty($filters['from_date'])) {
            $query->whereDate('count_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('count_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($builder) use ($search): void {
                $builder->where('count_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->get();
    }

    public function getById(int $id, ?int $companyId = null): InventoryStockCount
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return InventoryStockCount::with(['lines.item', 'warehouse'])
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public function variance(int $id, ?int $companyId = null): array
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);
        $count = InventoryStockCount::with(['lines.item', 'warehouse'])
            ->where('company_id', $companyId)
            ->findOrFail($id);
        $countDate = $count->count_date->toDateString();

        return [
            'count_id' => $count->id,
            'count_number' => $count->count_number,
            'count_date' => $countDate,
            'warehouse_id' => $count->warehouse_id,
            'lines' => $count->lines->map(function ($line) use ($companyId, $count, $countDate): array {
                $bookQuantity = $this->availabilityService->availableQuantity(
                    $companyId,
                    (int) $line->item_id,
                    (int) $count->warehouse_id,
                    $countDate,
                );
                $countedQuantity = BigDecimal::of((string) $line->counted_quantity)->toScale(6)->__toString();

                return [
                    'line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'item_code' => $line->item?->code,
                    'item_name' => $line->item?->name,
                    'unit' => $line->unit ?? $line->item?->unit,
                    'book_quantity' => $bookQuantity,
                    'counted_quantity' => $countedQuantity,
                    'variance_quantity' => BigDecimal::of($countedQuantity)->minus(BigDecimal::of($bookQuantity))->toScale(6)->__toString(),
                ];
            })->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function createAdjustmentDrafts(int $id, ?int $companyId = null): array
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId): array {
            $count = InventoryStockCount::with(['lines.item', 'warehouse'])
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($id);
            $this->periodGuard->assertOpen($companyId, $count->count_date, 'tạo chứng từ điều chỉnh kiểm kê kho');
            $authorization = $this->postingAuthorizer->authorize('inventory.stock-counts.create', $companyId);

            $documentReference = [
                'target_type' => InventoryStockCount::class,
                'target_id' => $count->id,
                'voucher_type' => 'Biên bản kiểm kê kho',
                'voucher_number' => $count->count_number,
                'voucher_date' => $count->count_date->toDateString(),
                'total_amount' => 0,
                'description' => 'Điều chỉnh theo biên bản kiểm kê '.$count->count_number,
            ];
            $referenceRow = [
                'target_type' => InventoryStockCount::class,
                'target_id' => $count->id,
                'target_voucher_type' => $documentReference['voucher_type'],
                'target_voucher_number' => $documentReference['voucher_number'],
                'target_voucher_date' => $documentReference['voucher_date'],
                'target_total_amount' => 0,
                'description' => $documentReference['description'],
            ];
            $existingReceipts = VoucherReference::query()
                ->where('target_type', InventoryStockCount::class)
                ->where('target_id', $count->id)
                ->where('source_type', InventoryReceipt::class)
                ->pluck('source_id')
                ->map(static fn ($value): int => (int) $value)
                ->all();
            $existingIssues = VoucherReference::query()
                ->where('target_type', InventoryStockCount::class)
                ->where('target_id', $count->id)
                ->where('source_type', InventoryIssue::class)
                ->pluck('source_id')
                ->map(static fn ($value): int => (int) $value)
                ->all();

            if ($existingReceipts !== [] || $existingIssues !== []) {
                return [
                    'count_id' => $count->id,
                    'issue_drafts' => InventoryIssue::whereIn('id', $existingIssues)->where('company_id', $companyId)->get()->values()->all(),
                    'receipt_drafts' => InventoryReceipt::whereIn('id', $existingReceipts)->where('company_id', $companyId)->get()->values()->all(),
                    'idempotent' => true,
                ];
            }

            $variance = $this->variance($count->id, $companyId);
            $receiptLines = [];
            $issueLines = [];
            foreach ($variance['lines'] as $line) {
                $difference = BigDecimal::of((string) $line['variance_quantity']);
                if ($difference->isZero()) {
                    continue;
                }
                $quantity = $difference->abs()->toScale(2)->__toString();
                $description = 'Điều chỉnh kiểm kê '.$count->count_number.' — '.($line['item_code'] ?? $line['item_name'] ?? 'Hàng hóa');
                $entry = [
                    'item_id' => $line['item_id'],
                    'unit' => $line['unit'],
                    'warehouse_id' => $count->warehouse_id,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => null,
                    'credit_account' => null,
                ];
                if ($difference->isPositive()) {
                    $receiptLines[] = $entry;
                } else {
                    $issueLines[] = $entry;
                }
            }

            $receiptDrafts = $receiptLines === []
                ? []
                : [$this->createReceiptAdjustmentDraft($count, $companyId, $receiptLines, $documentReference, $referenceRow)];
            $issueDrafts = $issueLines === []
                ? []
                : [$this->createIssueAdjustmentDraft($count, $companyId, $issueLines, $documentReference, $referenceRow)];

            $result = [
                'count_id' => $count->id,
                'issue_drafts' => $issueDrafts,
                'receipt_drafts' => $receiptDrafts,
                'idempotent' => false,
            ];
            $this->auditService->record($count, 'inventory_stock_count.adjustment_drafts_created', [], $result, null, $authorization);

            return $result;
        });
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function createReceiptAdjustmentDraft(
        InventoryStockCount $count,
        int $companyId,
        array $lines,
        array $documentReference,
        array $referenceRow,
    ): InventoryReceipt {
        $receipt = InventoryReceipt::create([
            'company_id' => $companyId,
            'voucher_type' => 'Kiểm kê điều chỉnh tăng',
            'warehouse_id' => $count->warehouse_id,
            'voucher_number' => 'KK-'.$count->count_number.'-T',
            'voucher_date' => $count->count_date,
            'posting_date' => $count->count_date,
            'description' => $documentReference['description'],
            'currency' => 'VND',
            'exchange_rate' => 1,
            'total_amount' => 0,
            'status' => 'draft',
            'is_posted' => false,
            'referenced_vouchers' => [$documentReference],
            'created_by' => auth()->id(),
        ]);
        $receipt->lines()->createMany($lines);
        VoucherReference::create([
            'source_type' => InventoryReceipt::class,
            'source_id' => $receipt->id,
            ...$referenceRow,
        ]);

        return $receipt->fresh('lines');
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function createIssueAdjustmentDraft(
        InventoryStockCount $count,
        int $companyId,
        array $lines,
        array $documentReference,
        array $referenceRow,
    ): InventoryIssue {
        $issue = InventoryIssue::create([
            'company_id' => $companyId,
            'voucher_type' => 'Kiểm kê điều chỉnh giảm',
            'warehouse_id' => $count->warehouse_id,
            'voucher_number' => 'KK-'.$count->count_number.'-G',
            'voucher_date' => $count->count_date,
            'posting_date' => $count->count_date,
            'description' => $documentReference['description'],
            'currency' => 'VND',
            'exchange_rate' => 1,
            'total_amount' => 0,
            'status' => 'draft',
            'is_posted' => false,
            'referenced_vouchers' => [$documentReference],
            'created_by' => auth()->id(),
        ]);
        $issue->lines()->createMany($lines);
        VoucherReference::create([
            'source_type' => InventoryIssue::class,
            'source_id' => $issue->id,
            ...$referenceRow,
        ]);

        return $issue->fresh('lines');
    }

    public function create(array $data): InventoryStockCount
    {
        $companyId = InventoryTenantGuard::companyId($data);
        $this->assertReferences($data, $companyId);
        $this->periodGuard->assertOpen($companyId, $data['count_date'], 'tạo biên bản kiểm kê kho');

        return DB::transaction(function () use ($data, $companyId): InventoryStockCount {
            $count = InventoryStockCount::create([
                'company_id' => $companyId,
                'count_number' => $data['count_number'],
                'count_date' => $data['count_date'],
                'warehouse_id' => $data['warehouse_id'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'is_posted' => false,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $this->replaceLines($count, $data['lines']);
            $this->auditService->record($count, 'inventory_stock_count.created', [], $count->fresh('lines')->toArray());

            return $count->load(['lines.item', 'warehouse']);
        });
    }

    public function update(int $id, array $data, ?int $companyId = null): InventoryStockCount
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId ?? ($data['company_id'] ?? null)]);
        if (isset($data['company_id']) && (int) $data['company_id'] !== $companyId) {
            throw ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }

        return DB::transaction(function () use ($id, $data, $companyId): InventoryStockCount {
            $count = InventoryStockCount::with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodGuard->assertOpen($companyId, $count->count_date, 'sửa biên bản kiểm kê kho');
            if ($count->is_posted || $count->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Chỉ được sửa biên bản kiểm kê ở trạng thái nháp.']);
            }

            $merged = array_merge($count->toArray(), $data);
            $this->assertReferences($merged, $companyId);
            if (array_key_exists('count_date', $data)) {
                $this->periodGuard->assertOpen($companyId, $data['count_date'], 'sửa biên bản kiểm kê kho');
            }
            $before = $count->toArray();
            $count->fill([
                'count_number' => $data['count_number'] ?? $count->count_number,
                'count_date' => $data['count_date'] ?? $count->count_date,
                'warehouse_id' => $data['warehouse_id'] ?? $count->warehouse_id,
                'description' => array_key_exists('description', $data) ? $data['description'] : $count->description,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);
            $count->save();

            if (array_key_exists('lines', $data)) {
                $this->replaceLines($count, $data['lines']);
            }
            $this->auditService->record($count, 'inventory_stock_count.updated', $before, $count->fresh('lines')->toArray());

            return $count->load(['lines.item', 'warehouse']);
        });
    }

    public function delete(int $id, ?int $companyId = null): void
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        DB::transaction(function () use ($id, $companyId): void {
            $count = InventoryStockCount::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodGuard->assertOpen($companyId, $count->count_date, 'xóa biên bản kiểm kê kho');
            if ($count->is_posted || $count->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Không thể xóa biên bản kiểm kê đã ghi sổ.']);
            }
            $before = $count->load('lines')->toArray();
            $count->delete();
            $this->auditService->record($count, 'inventory_stock_count.deleted', $before, []);
        });
    }

    private function replaceLines(InventoryStockCount $count, array $lines): void
    {
        $count->lines()->delete();
        foreach ($lines as $line) {
            $count->lines()->create([
                'item_id' => $line['item_id'],
                'unit' => $line['unit'] ?? null,
                'counted_quantity' => $line['counted_quantity'],
                'description' => $line['description'] ?? null,
            ]);
        }
    }

    private function assertReferences(array $data, int $companyId): void
    {
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        $warehouseExists = DB::table('warehouses')
            ->where('company_id', $companyId)
            ->where('id', $warehouseId)
            ->where(function ($query): void {
                $query->whereNull('is_active')->orWhere('is_active', true);
            })
            ->exists();
        if (! $warehouseExists) {
            throw ValidationException::withMessages(['warehouse_id' => 'Kho kiểm kê không thuộc công ty hoặc đã ngừng sử dụng.']);
        }

        $lines = $data['lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages(['lines' => 'Biên bản kiểm kê phải có ít nhất một dòng hàng hóa.']);
        }
        $itemIds = collect($lines)->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->values();
        if ($itemIds->count() !== count($lines)) {
            throw ValidationException::withMessages(['lines' => 'Mỗi dòng kiểm kê phải có hàng hóa từ catalogue máy chủ.']);
        }
        if ($itemIds->count() !== $itemIds->unique()->count()) {
            throw ValidationException::withMessages(['lines' => 'Mỗi hàng hóa chỉ được xuất hiện một lần trong một biên bản kiểm kê.']);
        }
        $ownedItems = DB::table('items')
            ->where('company_id', $companyId)
            ->whereIn('id', $itemIds)
            ->where(function ($query): void {
                $query->whereNull('is_active')->orWhere('is_active', true);
            })
            ->count();
        if ($ownedItems !== $itemIds->count()) {
            throw ValidationException::withMessages(['lines' => 'Có hàng hóa không thuộc công ty hoặc đã ngừng sử dụng.']);
        }

        foreach ($lines as $index => $line) {
            if (! array_key_exists('counted_quantity', $line) || ! is_numeric($line['counted_quantity']) || (float) $line['counted_quantity'] < 0) {
                throw ValidationException::withMessages(["lines.{$index}.counted_quantity" => 'Số lượng thực tế phải là số không âm.']);
            }
        }
    }
}
