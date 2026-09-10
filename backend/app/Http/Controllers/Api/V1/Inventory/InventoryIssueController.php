<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryIssueRequest;
use App\Http\Resources\InventoryIssueResource;
use App\Services\InventoryIssueService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InventoryIssueController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly InventoryIssueService $service
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

        return InventoryIssueResource::collection($items);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $item = $this->service->getById($id, $companyId);
        $res = (new InventoryIssueResource($item))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function store(StoreInventoryIssueRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $issue = $this->service->create($data);
            $res = (new InventoryIssueResource($issue))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function update(StoreInventoryIssueRequest $request, int $id): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $issue = $this->service->update($id, $data, $data['company_id']);
            $res = (new InventoryIssueResource($issue))->resolve();

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
            $issue = $this->service->post($id, $companyId);
            $res = (new InventoryIssueResource($issue))->resolve();

            return response()->json(['message' => 'Posted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        try {
            $issue = $this->service->void($id, $companyId);
            $res = (new InventoryIssueResource($issue))->resolve();

            return response()->json(['message' => 'Voided successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function unpost(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        try {
            $issue = $this->service->unpost($id, $companyId);
            $res = (new InventoryIssueResource($issue))->resolve();

            return response()->json(['message' => 'Unposted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        try {
            $issue = $this->service->duplicate($id, $companyId);

            return (new InventoryIssueResource($issue))
                ->response()
                ->setStatusCode(201);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e);
        }
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $prefix = $request->query('prefix', 'PXK');
        $code = $this->service->generateNextCode($companyId, $prefix);

        return response()->json(['data' => $code, 'code' => $code]);
    }
}
