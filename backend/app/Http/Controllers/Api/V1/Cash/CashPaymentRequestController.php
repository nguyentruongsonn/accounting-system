<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashPaymentRequestRequest;
use App\Services\CashPaymentRequestService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashPaymentRequestController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private readonly CashPaymentRequestService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $request->validate(['from_date' => ['nullable', 'date'], 'to_date' => ['nullable', 'date'], 'status' => ['nullable', 'in:draft,submitted'], 'search' => ['nullable', 'string', 'max:100']]);
        $filters = $request->except('company_id');
        $filters['company_id'] = $companyId;

        return response()->json(['data' => $this->service->getAll($filters)]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->getById($id, TenantContext::companyId($request))]);
    }

    public function store(StoreCashPaymentRequestRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);

            return response()->json(['data' => $this->service->create($data), 'message' => 'Đã lưu đề nghị chi nháp.'], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function update(StoreCashPaymentRequestRequest $request, int $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $companyId = TenantContext::companyId($request);
            $data['company_id'] = $companyId;

            return response()->json(['data' => $this->service->update($id, $data, $companyId), 'message' => 'Đã cập nhật đề nghị chi nháp.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([]);
            $result = $this->service->submit($id, TenantContext::companyId($request));

            return response()->json(['data' => $result, 'message' => 'Đã gửi đề nghị chi để phê duyệt.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->service->delete($id, TenantContext::companyId($request));

            return response()->json(['message' => 'Đã xóa đề nghị chi nháp.']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }
}
