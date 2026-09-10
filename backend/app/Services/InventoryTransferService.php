<?php

namespace App\Services;

use App\Models\InventoryMovementEvent;
use App\Models\InventoryTransfer;
use App\Support\InventoryTenantGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTransferService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly InventoryAvailabilityService $availabilityService,
        private readonly CoreDocumentPostingAuthorizer $postingAuthorizer,
        private readonly InventoryValuationRunInvalidator $valuationRunInvalidator,
    ) {}

    public function getAll(array $filters = [])
    {
        $companyId = InventoryTenantGuard::companyId($filters);
        $query = InventoryTransfer::with(['lines.item', 'fromWarehouse', 'toWarehouse'])
            ->where('company_id', $companyId)
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');

        if (! empty($filters['from_date'])) {
            $query->whereDate('transfer_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('transfer_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($builder) use ($search): void {
                $builder->where('transfer_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->get();
    }

    public function getById(int $id, ?int $companyId = null): InventoryTransfer
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return InventoryTransfer::with(['lines.item', 'fromWarehouse', 'toWarehouse'])
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function create(array $data): InventoryTransfer
    {
        $companyId = InventoryTenantGuard::companyId($data);
        $this->assertReferences($data, $companyId);
        $this->periodGuard->assertOpen($companyId, $data['transfer_date'], 'tạo phiếu điều chuyển kho');

        return DB::transaction(function () use ($data, $companyId): InventoryTransfer {
            $transfer = InventoryTransfer::create([
                'company_id' => $companyId,
                'transfer_number' => $data['transfer_number'],
                'transfer_date' => $data['transfer_date'],
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'is_posted' => false,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            foreach ($data['lines'] as $line) {
                $transfer->lines()->create([
                    'item_id' => $line['item_id'],
                    'unit' => $line['unit'] ?? null,
                    'quantity' => $line['quantity'],
                    'description' => $line['description'] ?? null,
                ]);
            }

            $this->auditService->record(
                $transfer,
                'inventory_transfer.created',
                [],
                $transfer->fresh('lines')->toArray(),
            );

            return $transfer->load(['lines.item', 'fromWarehouse', 'toWarehouse']);
        });
    }

    public function update(int $id, array $data, ?int $companyId = null): InventoryTransfer
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId ?? ($data['company_id'] ?? null)]);
        if (isset($data['company_id']) && (int) $data['company_id'] !== $companyId) {
            throw ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }

        return DB::transaction(function () use ($id, $data, $companyId): InventoryTransfer {
            $transfer = InventoryTransfer::with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodGuard->assertOpen($companyId, $transfer->transfer_date, 'sửa phiếu điều chuyển kho');
            if ($transfer->is_posted || $transfer->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Chỉ được sửa phiếu điều chuyển ở trạng thái nháp.']);
            }

            $merged = array_merge($transfer->toArray(), $data);
            $this->assertReferences($merged, $companyId);
            if (array_key_exists('transfer_date', $data)) {
                $this->periodGuard->assertOpen($companyId, $data['transfer_date'], 'sửa phiếu điều chuyển kho');
            }
            $before = $transfer->toArray();
            $transfer->fill([
                'transfer_number' => $data['transfer_number'] ?? $transfer->transfer_number,
                'transfer_date' => $data['transfer_date'] ?? $transfer->transfer_date,
                'from_warehouse_id' => $data['from_warehouse_id'] ?? $transfer->from_warehouse_id,
                'to_warehouse_id' => $data['to_warehouse_id'] ?? $transfer->to_warehouse_id,
                'description' => array_key_exists('description', $data) ? $data['description'] : $transfer->description,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);
            $transfer->save();

            if (array_key_exists('lines', $data)) {
                $transfer->lines()->delete();
                foreach ($data['lines'] as $line) {
                    $transfer->lines()->create([
                        'item_id' => $line['item_id'],
                        'unit' => $line['unit'] ?? null,
                        'quantity' => $line['quantity'],
                        'description' => $line['description'] ?? null,
                    ]);
                }
            }

            $this->auditService->record($transfer, 'inventory_transfer.updated', $before, $transfer->fresh('lines')->toArray());

            return $transfer->load(['lines.item', 'fromWarehouse', 'toWarehouse']);
        });
    }

    public function delete(int $id, ?int $companyId = null): void
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        DB::transaction(function () use ($id, $companyId): void {
            $transfer = InventoryTransfer::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodGuard->assertOpen($companyId, $transfer->transfer_date, 'xóa phiếu điều chuyển kho');
            if ($transfer->is_posted || $transfer->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Không thể xóa phiếu điều chuyển đã ghi sổ.']);
            }
            $before = $transfer->load('lines')->toArray();
            $transfer->delete();
            $this->auditService->record($transfer, 'inventory_transfer.deleted', $before, []);
        });
    }

    public function post(int $id, ?int $companyId = null): InventoryTransfer
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId): InventoryTransfer {
            $transfer = InventoryTransfer::with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodGuard->assertOpen($companyId, $transfer->transfer_date, 'ghi sổ phiếu điều chuyển kho');
            if ($transfer->is_posted || $transfer->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Chỉ được ghi sổ phiếu điều chuyển ở trạng thái nháp.']);
            }

            $authorization = $this->postingAuthorizer->authorize('inventory.transfers.post', $companyId);
            $this->assertSufficientStock($transfer);

            foreach ($transfer->lines as $line) {
                $postingCycle = $this->nextPostingCycle($companyId, $transfer->id, $line->id);
                InventoryMovementEvent::create([
                    'company_id' => $companyId,
                    'movement_date' => $transfer->transfer_date->toDateString(),
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'item_id' => $line->item_id,
                    'movement_type' => 'transfer_out',
                    'posting_cycle' => $postingCycle,
                    'quantity_delta' => BigDecimal::of((string) $line->quantity)->negated()->toScale(4)->__toString(),
                    'amount_delta' => null,
                    'source_type' => InventoryTransfer::class,
                    'source_id' => $transfer->id,
                    'source_line_id' => $line->id,
                ]);
                InventoryMovementEvent::create([
                    'company_id' => $companyId,
                    'movement_date' => $transfer->transfer_date->toDateString(),
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'item_id' => $line->item_id,
                    'movement_type' => 'transfer_in',
                    'posting_cycle' => $postingCycle,
                    'quantity_delta' => BigDecimal::of((string) $line->quantity)->toScale(4)->__toString(),
                    'amount_delta' => null,
                    'source_type' => InventoryTransfer::class,
                    'source_id' => $transfer->id,
                    'source_line_id' => $line->id,
                ]);
                $this->valuationRunInvalidator->invalidateForInventoryMovement(
                    $companyId,
                    $transfer->transfer_date->toDateString(),
                    'inventory_transfer_posted',
                    (int) $transfer->from_warehouse_id,
                    (int) $line->item_id,
                );
                $this->valuationRunInvalidator->invalidateForInventoryMovement(
                    $companyId,
                    $transfer->transfer_date->toDateString(),
                    'inventory_transfer_posted',
                    (int) $transfer->to_warehouse_id,
                    (int) $line->item_id,
                );
            }

            $before = $transfer->toArray();
            $transfer->update(['status' => 'posted', 'is_posted' => true, 'updated_by' => auth()->id()]);
            $this->auditService->record($transfer, 'inventory_transfer.posted', $before, $transfer->fresh()->toArray(), null, $authorization);

            return $transfer->load(['lines.item', 'fromWarehouse', 'toWarehouse']);
        });
    }

    public function unpost(int $id, ?int $companyId = null): InventoryTransfer
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId): InventoryTransfer {
            $transfer = InventoryTransfer::with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodGuard->assertOpen($companyId, $transfer->transfer_date, 'bỏ ghi sổ phiếu điều chuyển kho');
            if (! $transfer->is_posted || $transfer->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Chỉ được bỏ ghi sổ phiếu điều chuyển đã ghi sổ.']);
            }

            $authorization = $this->postingAuthorizer->authorize('inventory.transfers.unpost', $companyId);
            foreach ($transfer->lines as $line) {
                $postingCycle = $this->currentPostingCycle($companyId, $transfer->id, $line->id);
                InventoryMovementEvent::create([
                    'company_id' => $companyId,
                    'movement_date' => $transfer->transfer_date->toDateString(),
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'item_id' => $line->item_id,
                    'movement_type' => 'transfer_out_reversal',
                    'posting_cycle' => $postingCycle,
                    'quantity_delta' => BigDecimal::of((string) $line->quantity)->toScale(4)->__toString(),
                    'amount_delta' => null,
                    'source_type' => InventoryTransfer::class,
                    'source_id' => $transfer->id,
                    'source_line_id' => $line->id,
                ]);
                InventoryMovementEvent::create([
                    'company_id' => $companyId,
                    'movement_date' => $transfer->transfer_date->toDateString(),
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'item_id' => $line->item_id,
                    'movement_type' => 'transfer_in_reversal',
                    'posting_cycle' => $postingCycle,
                    'quantity_delta' => BigDecimal::of((string) $line->quantity)->negated()->toScale(4)->__toString(),
                    'amount_delta' => null,
                    'source_type' => InventoryTransfer::class,
                    'source_id' => $transfer->id,
                    'source_line_id' => $line->id,
                ]);
                $this->valuationRunInvalidator->invalidateForInventoryMovement(
                    $companyId,
                    $transfer->transfer_date->toDateString(),
                    'inventory_transfer_unposted',
                    (int) $transfer->from_warehouse_id,
                    (int) $line->item_id,
                );
                $this->valuationRunInvalidator->invalidateForInventoryMovement(
                    $companyId,
                    $transfer->transfer_date->toDateString(),
                    'inventory_transfer_unposted',
                    (int) $transfer->to_warehouse_id,
                    (int) $line->item_id,
                );
            }

            $before = $transfer->toArray();
            $transfer->update(['status' => 'draft', 'is_posted' => false, 'updated_by' => auth()->id()]);
            $this->auditService->record($transfer, 'inventory_transfer.unposted', $before, $transfer->fresh()->toArray(), null, $authorization);

            return $transfer->load(['lines.item', 'fromWarehouse', 'toWarehouse']);
        });
    }

    private function assertSufficientStock(InventoryTransfer $transfer): void
    {
        $requested = [];
        foreach ($transfer->lines as $index => $line) {
            $itemId = (int) $line->item_id;
            if (! isset($requested[$itemId])) {
                $requested[$itemId] = ['quantity' => BigDecimal::zero()->toScale(4), 'indexes' => []];
            }
            $requested[$itemId]['quantity'] = $requested[$itemId]['quantity']->plus(BigDecimal::of((string) $line->quantity));
            $requested[$itemId]['indexes'][] = $index;
        }

        $errors = [];
        foreach ($requested as $itemId => $requestedItem) {
            $available = BigDecimal::of($this->availabilityService->availableQuantity(
                (int) $transfer->company_id,
                $itemId,
                (int) $transfer->from_warehouse_id,
                $transfer->transfer_date->toDateString(),
            ));
            if ($requestedItem['quantity']->isGreaterThan($available)) {
                foreach ($requestedItem['indexes'] as $index) {
                    $errors["lines.{$index}.quantity"] = "Số lượng điều chuyển vượt tồn khả dụng {$available->toScale(4)} tại kho xuất.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function nextPostingCycle(int $companyId, int $transferId, int $lineId): int
    {
        $latest = InventoryMovementEvent::query()
            ->where('company_id', $companyId)
            ->where('source_type', InventoryTransfer::class)
            ->where('source_id', $transferId)
            ->where('source_line_id', $lineId)
            ->where('movement_type', 'transfer_out')
            ->max('posting_cycle');

        return $latest === null ? 0 : ((int) $latest + 1);
    }

    private function currentPostingCycle(int $companyId, int $transferId, int $lineId): int
    {
        $latest = InventoryMovementEvent::query()
            ->where('company_id', $companyId)
            ->where('source_type', InventoryTransfer::class)
            ->where('source_id', $transferId)
            ->where('source_line_id', $lineId)
            ->where('movement_type', 'transfer_out')
            ->max('posting_cycle');

        if ($latest === null) {
            throw ValidationException::withMessages([
                'movement_history' => 'Không tìm thấy biến động gốc của phiếu điều chuyển để bỏ ghi sổ.',
            ]);
        }

        return (int) $latest;
    }

    private function assertReferences(array $data, int $companyId): void
    {
        $from = (int) ($data['from_warehouse_id'] ?? 0);
        $to = (int) ($data['to_warehouse_id'] ?? 0);
        if ($from <= 0 || $to <= 0 || $from === $to) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'Kho xuất và kho nhận phải là hai kho khác nhau.']);
        }

        $warehouseCount = DB::table('warehouses')
            ->where('company_id', $companyId)
            ->whereIn('id', [$from, $to])
            ->where(function ($query): void {
                $query->whereNull('is_active')->orWhere('is_active', true);
            })
            ->count();
        if ($warehouseCount !== 2) {
            throw ValidationException::withMessages(['warehouse_id' => 'Kho điều chuyển không thuộc công ty đang đăng nhập.']);
        }

        $lines = $data['lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages(['lines' => 'Phiếu điều chuyển phải có ít nhất một dòng hàng hóa.']);
        }
        $itemIds = collect($lines)->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
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
    }
}
