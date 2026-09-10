<?php

namespace App\Http\Controllers\Api\V1\Bank;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankReceiptRequest;
use App\Http\Resources\BankReceiptResource;
use App\Services\BankReceiptService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankReceiptController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly BankReceiptService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = TenantContext::companyId($request);
        $items = $this->service->getAll($companyId);

        return BankReceiptResource::collection($items);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        TenantContext::companyId($request);
        $item = $this->service->getById($id);
        $res = (new BankReceiptResource($item))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $code = $this->service->generateNextCode($companyId);

        return response()->json(['code' => $code, 'next_code' => $code, 'data' => ['code' => $code, 'next_code' => $code]]);
    }

    public function store(StoreBankReceiptRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $receipt = $this->service->create($data);
            $res = (new BankReceiptResource($receipt))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function update(StoreBankReceiptRequest $request, int $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $receipt = $this->service->update($id, $data);
            $res = (new BankReceiptResource($receipt))->resolve();

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
            $receipt = $this->service->duplicate($id);
            $res = (new BankReceiptResource($receipt))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }
}
