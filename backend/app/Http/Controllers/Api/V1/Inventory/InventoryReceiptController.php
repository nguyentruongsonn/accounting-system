<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryReceiptRequest;
use App\Http\Resources\InventoryReceiptResource;
use App\Services\InventoryReceiptService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InventoryReceiptController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly InventoryReceiptService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = TenantContext::companyId($request);
        $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);
        $filters = $request->except('company_id');
        $filters['company_id'] = $companyId;
        $items = $this->service->getAll($filters);

        return InventoryReceiptResource::collection($items);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $item = $this->service->getById($id, $companyId);
        $res = (new InventoryReceiptResource($item))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function store(StoreInventoryReceiptRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $receipt = $this->service->create($data);
            $res = (new InventoryReceiptResource($receipt))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function update(StoreInventoryReceiptRequest $request, int $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $receipt = $this->service->update($id, $data, $data['company_id']);
            $res = (new InventoryReceiptResource($receipt))->resolve();

            return response()->json(['data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        try {
            $this->service->delete($id, $companyId);

            return response()->json(['message' => 'Deleted successfully']);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        try {
            $receipt = $this->service->post($id, $companyId);
            $res = (new InventoryReceiptResource($receipt))->resolve();

            return response()->json(['message' => 'Posted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        try {
            $receipt = $this->service->void($id, $companyId);
            $res = (new InventoryReceiptResource($receipt))->resolve();

            return response()->json(['message' => 'Voided successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function unpost(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        try {
            $receipt = $this->service->unpost($id, $companyId);
            $res = (new InventoryReceiptResource($receipt))->resolve();

            return response()->json(['message' => 'Unposted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        try {
            $receipt = $this->service->duplicate($id, $companyId);

            return (new InventoryReceiptResource($receipt))
                ->response()
                ->setStatusCode(201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $prefix = $request->query('prefix', 'PNK');
        $code = $this->service->generateNextCode($companyId, $prefix);

        return response()->json(['data' => $code, 'code' => $code]);
    }
}
