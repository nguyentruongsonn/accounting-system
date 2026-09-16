<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryStockCountRequest;
use App\Services\InventoryStockCountService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryStockCountController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private readonly InventoryStockCountService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
        ]);
        $filters = $request->except('company_id');
        $filters['company_id'] = $companyId;

        return response()->json(['data' => $this->service->getAll($filters)]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->getById($id, TenantContext::companyId($request))]);
    }

    public function variance(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->variance($id, TenantContext::companyId($request))]);
    }

    public function adjustmentDrafts(Request $request, int $id): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->service->createAdjustmentDrafts($id, TenantContext::companyId($request)),
                'message' => 'Đã tạo chứng từ điều chỉnh kiểm kê ở trạng thái nháp.',
            ], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function store(StoreInventoryStockCountRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $count = $this->service->create($data);

            return response()->json(['data' => $count, 'message' => 'Đã lưu biên bản kiểm kê nháp.'], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function update(StoreInventoryStockCountRequest $request, int $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $count = $this->service->update($id, $data, $data['company_id']);

            return response()->json(['data' => $count, 'message' => 'Đã cập nhật biên bản kiểm kê nháp.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->service->delete($id, TenantContext::companyId($request));

            return response()->json(['message' => 'Đã xóa biên bản kiểm kê nháp.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }
}
