<?php

namespace App\Http\Controllers\Api\V1\Bank;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankPaymentRequest;
use App\Http\Resources\BankPaymentResource;
use App\Services\BankPaymentService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankPaymentController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly BankPaymentService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = TenantContext::companyId($request);
        $items = $this->service->getAll($companyId);

        return BankPaymentResource::collection($items);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        TenantContext::companyId($request);
        $item = $this->service->getById($id);
        $res = (new BankPaymentResource($item))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $code = $this->service->generateNextCode($companyId);

        return response()->json(['code' => $code, 'next_code' => $code, 'data' => ['code' => $code, 'next_code' => $code]]);
    }

    public function store(StoreBankPaymentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $payment = $this->service->create($data);
            $res = (new BankPaymentResource($payment))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function update(StoreBankPaymentRequest $request, int $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $payment = $this->service->update($id, $data);
            $res = (new BankPaymentResource($payment))->resolve();

            return response()->json(['data' => $res, ...$res]);
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

    public function duplicate(Request $request, int $id): JsonResponse
    {
        TenantContext::companyId($request);
        try {
            $payment = $this->service->duplicate($id);
            $res = (new BankPaymentResource($payment))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }
}
