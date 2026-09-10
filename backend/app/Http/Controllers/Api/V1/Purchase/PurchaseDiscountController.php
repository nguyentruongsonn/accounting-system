<?php

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Resources\Purchase\PurchaseDiscountResource;
use App\Services\PurchaseDiscountService;
use App\Support\ApiErrorResponder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseDiscountController extends Controller
{
    public function __construct(
        private readonly PurchaseDiscountService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $discounts = $this->service->getAll($request->all());

        return PurchaseDiscountResource::collection($discounts);
    }

    public function show(int|string $id): JsonResponse
    {
        $discount = $this->service->getById($id);
        $res = (new PurchaseDiscountResource($discount))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'company_id' => 'nullable|integer',
                'branch_id' => 'nullable|integer',
                'supplier_id' => 'required|integer',
                'supplier_name' => 'nullable|string',
                'supplier_address' => 'nullable|string',
                'tax_code' => 'nullable|string',
                'deliverer_name' => 'nullable|string',
                'receiver_name' => 'nullable|string',
                'employee_id' => 'nullable|integer',
                'voucher_type' => 'nullable|string',
                'payment_method' => 'nullable|string',
                'bank_account_id' => 'nullable|integer',
                'voucher_number' => 'nullable|string',
                'voucher_date' => 'nullable|date',
                'accounting_date' => 'nullable|date',
                'reason' => 'nullable|string',
                'description' => 'nullable|string',
                'attached_docs' => 'nullable|string',
                'sub_total' => 'nullable|numeric',
                'tax_amount' => 'nullable|numeric',
                'total_amount' => 'nullable|numeric',
                'grand_total' => 'nullable|numeric',
                'is_posted' => 'nullable|boolean',
                'is_decrease_debt' => 'nullable|boolean',
                'status' => 'nullable|string',
                'reference_invoice_id' => 'nullable|integer',
                'referenced_vouchers' => 'nullable|array',
                'lines' => 'required|array|min:1',
            ]);

            $discount = $this->service->create($data);
            $res = (new PurchaseDiscountResource($discount))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 422);
        }
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        try {
            $data = $request->validate([
                'company_id' => 'nullable|integer',
                'branch_id' => 'nullable|integer',
                'supplier_id' => 'nullable|integer',
                'supplier_name' => 'nullable|string',
                'supplier_address' => 'nullable|string',
                'tax_code' => 'nullable|string',
                'deliverer_name' => 'nullable|string',
                'receiver_name' => 'nullable|string',
                'employee_id' => 'nullable|integer',
                'voucher_type' => 'nullable|string',
                'payment_method' => 'nullable|string',
                'bank_account_id' => 'nullable|integer',
                'voucher_number' => 'nullable|string',
                'voucher_date' => 'nullable|date',
                'accounting_date' => 'nullable|date',
                'reason' => 'nullable|string',
                'description' => 'nullable|string',
                'attached_docs' => 'nullable|string',
                'sub_total' => 'nullable|numeric',
                'tax_amount' => 'nullable|numeric',
                'total_amount' => 'nullable|numeric',
                'grand_total' => 'nullable|numeric',
                'is_decrease_debt' => 'nullable|boolean',
                'status' => 'nullable|string',
                'reference_invoice_id' => 'nullable|integer',
                'referenced_vouchers' => 'nullable|array',
                'lines' => 'sometimes|array|min:1',
            ]);

            $discount = $this->service->update($id, $data);
            $res = (new PurchaseDiscountResource($discount))->resolve();

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
            $res = (new PurchaseDiscountResource($discount))->resolve();

            return response()->json(['message' => 'Posted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 400);
        }
    }

    public function unpost(int|string $id): JsonResponse
    {
        try {
            $discount = $this->service->unpost($id);
            $res = (new PurchaseDiscountResource($discount))->resolve();

            return response()->json(['message' => 'Unposted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 400);
        }
    }

    public function void(int|string $id): JsonResponse
    {
        try {
            $discount = $this->service->void($id);
            $res = (new PurchaseDiscountResource($discount))->resolve();

            return response()->json(['message' => 'Voided successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 400);
        }
    }

    public function duplicate(int|string $id): JsonResponse
    {
        try {
            $discount = $this->service->duplicate($id);
            $res = (new PurchaseDiscountResource($discount))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 500);
        }
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $prefix = $request->query('prefix', 'GGMH');
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
