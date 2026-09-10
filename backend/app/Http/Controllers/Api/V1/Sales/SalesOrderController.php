<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Services\SalesOrderService;
use App\Support\ApiErrorResponder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesOrderController extends Controller
{
    public function __construct(
        private readonly SalesOrderService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orders = $this->service->getAll($request->all());

        return response()->json(['data' => $orders, 'success' => true]);
    }

    public function show(int $id): JsonResponse
    {
        $order = $this->service->getById($id);

        return response()->json(['data' => $order, 'success' => true, ...$order->toArray()]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $order = $this->service->create($request->all());

            return response()->json(['data' => $order, 'success' => true, ...$order->toArray()], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->service->update($id, $request->all());

            return response()->json(['data' => $order, 'success' => true, ...$order->toArray()]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->service->delete($id);

            return response()->json(['message' => 'Deleted successfully', 'success' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function duplicate(int $id): JsonResponse
    {
        try {
            $order = $this->service->duplicate($id);

            return response()->json(['data' => $order, 'success' => true, ...$order->toArray()], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 500);
        }
    }

    public function unpost(int $id): JsonResponse
    {
        try {
            $order = $this->service->unpost($id);

            return response()->json(['data' => $order, 'message' => 'Unposted successfully', 'success' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 400);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $status = $request->input('status', 'pending');
            $order = $this->service->updateStatus($id, $status);

            return response()->json(['data' => $order, 'success' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $prefix = $request->query('prefix', 'DDH');
        $code = $this->service->generateNextCode($companyId, $prefix);

        return response()->json(['data' => $code, 'code' => $code]);
    }

    private function errorResponse(\Throwable $exception, int $legacyStatus): JsonResponse
    {
        $response = app(ApiErrorResponder::class)->toResponse($exception, request(), $legacyStatus);
        $payload = $response->getData(true);
        $payload['success'] = false;

        return response()->json($payload, $response->getStatusCode(), $response->headers->all());
    }
}
