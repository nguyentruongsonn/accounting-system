<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashAdvanceSettlementRequest;
use App\Services\CashAdvanceSettlementService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashAdvanceSettlementController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private readonly CashAdvanceSettlementService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $request->validate(['status' => ['nullable', 'in:draft,submitted'], 'search' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->service->getAll(array_merge($request->except('company_id'), ['company_id' => $companyId]))]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->getById($id, TenantContext::companyId($request))]);
    }

    public function store(StoreCashAdvanceSettlementRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);

            return response()->json(['data' => $this->service->create($data), 'message' => 'Đã lưu quyết toán tạm ứng nháp.'], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function update(StoreCashAdvanceSettlementRequest $request, int $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $companyId = TenantContext::companyId($request);
            $data['company_id'] = $companyId;

            return response()->json(['data' => $this->service->update($id, $data, $companyId), 'message' => 'Đã cập nhật quyết toán tạm ứng nháp.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->service->submit($id, TenantContext::companyId($request));

            return response()->json(['data' => $result, 'message' => 'Đã gửi quyết toán tạm ứng để phê duyệt.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->service->delete($id, TenantContext::companyId($request));

            return response()->json(['message' => 'Đã xóa quyết toán tạm ứng nháp.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }
}
