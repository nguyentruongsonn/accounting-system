<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryTransferRequest;
use App\Services\InventoryTransferService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryTransferController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private readonly InventoryTransferService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'from_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
        ]);
        $filters = $request->except('company_id');
        $filters['company_id'] = $companyId;
        if (! empty($filters['from_warehouse_id'])) {
            $warehouseId = (int) $filters['from_warehouse_id'];
            $items = collect($this->service->getAll($filters))->filter(fn ($transfer) => (int) $transfer->from_warehouse_id === $warehouseId)->values();
        } else {
            $items = $this->service->getAll($filters);
        }

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $transfer = $this->service->getById($id, TenantContext::companyId($request));

        return response()->json(['data' => $transfer]);
    }

    public function store(StoreInventoryTransferRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $transfer = $this->service->create($data);

            return response()->json(['data' => $transfer, 'message' => 'Đã lưu phiếu điều chuyển nháp.'], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function update(StoreInventoryTransferRequest $request, int $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $transfer = $this->service->update($id, $data, $data['company_id']);

            return response()->json(['data' => $transfer, 'message' => 'Đã cập nhật phiếu điều chuyển nháp.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->service->delete($id, TenantContext::companyId($request));

            return response()->json(['message' => 'Đã xóa phiếu điều chuyển nháp.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function post(Request $request, int $id): JsonResponse
    {
        try {
            $transfer = $this->service->post($id, TenantContext::companyId($request));

            return response()->json(['data' => $transfer, 'message' => 'Đã ghi sổ phiếu điều chuyển kho.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function unpost(Request $request, int $id): JsonResponse
    {
        try {
            $transfer = $this->service->unpost($id, TenantContext::companyId($request));

            return response()->json(['data' => $transfer, 'message' => 'Đã bỏ ghi sổ phiếu điều chuyển kho.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }
}
