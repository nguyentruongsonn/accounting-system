<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashPaymentRequest;
use App\Http\Resources\CashPaymentResource;
use App\Services\CashPaymentService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashPaymentController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly CashPaymentService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->except('company_id');
        $filters['company_id'] = TenantContext::companyId($request);
        $items = $this->service->getAll($filters);

        return CashPaymentResource::collection($items);
    }

    public function show(Request $request, int $id): CashPaymentResource
    {
        TenantContext::companyId($request);
        $item = $this->service->getById($id);

        return new CashPaymentResource($item);
    }

    public function store(StoreCashPaymentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $payment = $this->service->create($data);

            return (new CashPaymentResource($payment))
                ->response()
                ->setStatusCode(201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function update(StoreCashPaymentRequest $request, int $id): JsonResponse|CashPaymentResource
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $payment = $this->service->update($id, $data);

            return new CashPaymentResource($payment);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        TenantContext::companyId($request);
        try {
            $this->service->delete($id);

            return response()->json(['message' => 'Deleted successfully']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function post(Request $request, int $id): JsonResponse
    {
        TenantContext::companyId($request);
        try {
            $this->service->post($id);

            return response()->json(['message' => 'Posted successfully']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    /**
     * Hủy chứng từ (Void) — hủy hoàn toàn, không khôi phục
     */
    public function void(Request $request, int $id): JsonResponse
    {
        TenantContext::companyId($request);
        try {
            $this->service->void($id);

            return response()->json(['message' => 'Voided successfully']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    /**
     * Bỏ ghi sổ (Unpost) — hủy bút toán, đưa về nháp, có thể sửa
     */
    public function unpost(Request $request, int $id): JsonResponse
    {
        TenantContext::companyId($request);
        try {
            $this->service->unpost($id);

            return response()->json(['message' => 'Unposted successfully']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function duplicate(Request $request, int $id): JsonResponse|CashPaymentResource
    {
        TenantContext::companyId($request);
        try {
            $payment = $this->service->duplicate($id);

            return new CashPaymentResource($payment);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    /**
     * Lấy mã phiếu chi tiếp theo dạng PC00001
     */
    public function nextCode(Request $request): JsonResponse
    {
        $code = $this->service->getNextCode(TenantContext::companyId($request));

        return response()->json(['code' => $code, 'next_code' => $code, 'data' => ['code' => $code, 'next_code' => $code]]);
    }
}
