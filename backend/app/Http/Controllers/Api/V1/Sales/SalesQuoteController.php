<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Services\SalesQuoteService;
use App\Support\ApiErrorResponder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesQuoteController extends Controller
{
    public function __construct(
        private readonly SalesQuoteService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $quotes = $this->service->getAll($request->all());

        return response()->json(['data' => $quotes, 'success' => true]);
    }

    public function show(int $id): JsonResponse
    {
        $quote = $this->service->getById($id);

        return response()->json(['data' => $quote, 'success' => true, ...$quote->toArray()]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $quote = $this->service->create($request->all());

            return response()->json(['data' => $quote, 'success' => true, ...$quote->toArray()], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $quote = $this->service->update($id, $request->all());

            return response()->json(['data' => $quote, 'success' => true, ...$quote->toArray()]);
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
            $quote = $this->service->duplicate($id);

            return response()->json(['data' => $quote, 'success' => true, ...$quote->toArray()], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 500);
        }
    }

    public function unpost(int $id): JsonResponse
    {
        try {
            $quote = $this->service->unpost($id);

            return response()->json(['data' => $quote, 'message' => 'Unposted successfully', 'success' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 400);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $status = $request->input('status', 'draft');
            $quote = $this->service->updateStatus($id, $status);

            return response()->json(['data' => $quote, 'success' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $prefix = $request->query('prefix', 'BG');
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
