<?php

namespace App\Http\Controllers\Api\V1\GL;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Resources\JournalEntryResource;
use App\Services\JournalEntryService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class JournalEntryController extends Controller
{
    public function __construct(
        private readonly JournalEntryService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 20);
        $filters = $request->only(['status', 'voucher_type', 'from_date', 'to_date', 'search']);
        $filters['company_id'] = TenantContext::companyId($request);

        $entries = $this->service->getAll($perPage, $filters);

        if ($entries instanceof LengthAwarePaginator) {
            return response()->json([
                'data' => JournalEntryResource::collection($entries->items()),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ]);
        }

        return response()->json([
            'data' => JournalEntryResource::collection($entries),
            'total' => $entries->count(),
        ]);
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $nextCode = $this->service->generateNextCode($companyId);

        return response()->json(['data' => $nextCode, 'code' => $nextCode, 'voucher_number' => $nextCode]);
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['company_id'] = TenantContext::companyId($request);
        $data['status'] = 'draft';
        $entry = $this->service->create($data);
        $res = (new JournalEntryResource($entry))->resolve();

        return response()->json(['data' => $res, ...$res], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $entry = $this->service->getById($id, $companyId);
        $res = (new JournalEntryResource($entry))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function update(int $id, StoreJournalEntryRequest $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $entry = $this->service->update($id, $request->validated(), $companyId);
        $res = (new JournalEntryResource($entry))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $entry = $this->service->post($id, $companyId);
        $res = (new JournalEntryResource($entry))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function reverse(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'posting_date' => ['nullable', 'date'],
        ]);

        $entry = $this->service->reverse(
            $id,
            TenantContext::companyId($request),
            $data['reason'],
            $data['posting_date'] ?? null
        );
        $res = (new JournalEntryResource($entry))->resolve();

        return response()->json(['data' => $res, ...$res], 201);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $entry = $this->service->void($id, $companyId);
        $res = (new JournalEntryResource($entry))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function unpost(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $entry = $this->service->unpost($id, $companyId);
        $res = (new JournalEntryResource($entry))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $entry = $this->service->duplicate($id, $companyId);
        $res = (new JournalEntryResource($entry))->resolve();

        return response()->json(['data' => $res, ...$res], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $this->service->delete($id, $companyId);

        return response()->json(null, 204);
    }
}
