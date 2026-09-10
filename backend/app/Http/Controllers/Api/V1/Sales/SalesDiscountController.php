<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesDiscountRequest;
use App\Http\Requests\Sales\UpdateSalesDiscountRequest;
use App\Http\Resources\Sales\SalesDiscountResource;
use App\Services\SalesDiscountService;
use App\Support\ApiErrorResponder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesDiscountController extends Controller
{
    public function __construct(
        private readonly SalesDiscountService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $discounts = $this->service->getAll($request->all());

        return SalesDiscountResource::collection($discounts);
    }

    public function show(int|string $id): JsonResponse
    {
        $discount = $this->service->getById($id);
        $res = (new SalesDiscountResource($discount))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function store(StoreSalesDiscountRequest $request): JsonResponse
    {
        try {
            $discount = $this->service->create($request->validated());
            $res = (new SalesDiscountResource($discount))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function update(UpdateSalesDiscountRequest $request, int|string $id): JsonResponse
    {
        try {
            $discount = $this->service->update($id, $request->validated());
            $res = (new SalesDiscountResource($discount))->resolve();

            return response()->json(['data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function destroy(int|string $id): JsonResponse
    {
        try {
            $this->service->delete($id);

            return response()->json(['message' => 'Deleted successfully']);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function post(int|string $id): JsonResponse
    {
        try {
            $discount = $this->service->post($id);
            $res = (new SalesDiscountResource($discount))->resolve();

            return response()->json(['message' => 'Posted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 400);
        }
    }

    public function unpost(int|string $id): JsonResponse
    {
        try {
            $discount = $this->service->unpost($id);
            $res = (new SalesDiscountResource($discount))->resolve();

            return response()->json(['message' => 'Unposted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 400);
        }
    }

    public function void(int|string $id): JsonResponse
    {
        try {
            $discount = $this->service->void($id);
            $res = (new SalesDiscountResource($discount))->resolve();

            return response()->json(['message' => 'Voided successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 400);
        }
    }

    public function duplicate(int|string $id): JsonResponse
    {
        try {
            $discount = $this->service->duplicate($id);
            $res = (new SalesDiscountResource($discount))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 500);
        }
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $prefix = $request->query('prefix', 'GGHB');
        $code = $this->service->generateNextCode($companyId, $prefix);

        return response()->json([
            'data' => ['code' => $code],
            'code' => $code,
        ]);
    }

    private function errorResponse(\Throwable $exception, int $legacyStatus): JsonResponse
    {
        $response = app(ApiErrorResponder::class)->toResponse($exception, request(), $legacyStatus);
        $payload = $response->getData(true);
        $payload['message'] = $payload['error'];

        return response()->json($payload, $response->getStatusCode(), $response->headers->all());
    }
}
