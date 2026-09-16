<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashReceiptRequest;
use App\Http\Resources\CashReceiptResource;
use App\Services\CashReceiptService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashReceiptController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly CashReceiptService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->except('company_id');
        $filters['company_id'] = TenantContext::companyId($request);
        $items = $this->service->getAll($filters);

        return CashReceiptResource::collection($items);
    }

    public function show(Request $request, int $id): CashReceiptResource
    {
        TenantContext::companyId($request);
        $item = $this->service->getById($id);

        return new CashReceiptResource($item);
    }

    public function store(StoreCashReceiptRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $receipt = $this->service->create($data);

            return (new CashReceiptResource($receipt))
                ->response()
                ->setStatusCode(201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function update(StoreCashReceiptRequest $request, int $id): JsonResponse|CashReceiptResource
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $receipt = $this->service->update($id, $data);

            return new CashReceiptResource($receipt);
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
     * Hủy chứng từ (Void) — chứng từ bị hủy hoàn toàn, không khôi phục được
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
     * Bỏ ghi sổ (Unpost) — hủy bút toán, đưa về trạng thái nháp, có thể sửa lại
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

    public function duplicate(Request $request, int $id): JsonResponse|CashReceiptResource
    {
        TenantContext::companyId($request);
        try {
            $receipt = $this->service->duplicate($id);

            return new CashReceiptResource($receipt);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    /**
     * Lấy mã phiếu thu tiếp theo dạng PT00001
     */
    public function nextCode(Request $request): JsonResponse
    {
        $code = $this->service->getNextCode(TenantContext::companyId($request));

        return response()->json(['code' => $code, 'next_code' => $code, 'data' => ['code' => $code, 'next_code' => $code]]);
    }
}
