<?php

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseInvoiceRequest;
use App\Http\Resources\CashPaymentResource;
use App\Http\Resources\PurchaseInvoiceResource;
use App\Services\AccountingDocumentDimensionAssignmentService;
use App\Services\PurchaseInvoiceDimensionReadinessService;
use App\Services\PurchaseInvoiceDimensionSelectionContextService;
use App\Services\PurchaseInvoicePaymentService;
use App\Services\PurchaseInvoiceService;
use App\Support\ApiErrorResponder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private readonly PurchaseInvoiceService $service,
        private readonly PurchaseInvoiceDimensionReadinessService $dimensionReadiness,
        private readonly PurchaseInvoiceDimensionSelectionContextService $dimensionSelectionContext,
        private readonly AccountingDocumentDimensionAssignmentService $dimensionAssignments,
        private readonly PurchaseInvoicePaymentService $payments,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $items = $this->service->getAll(TenantContext::companyId($request));

        return PurchaseInvoiceResource::collection($items);
    }

    public function show(int $id): JsonResponse
    {
        $item = $this->service->getById($id);
        $res = (new PurchaseInvoiceResource($item))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $code = $this->service->generateNextCode((int) $companyId);

        return response()->json(['code' => $code, 'data' => ['code' => $code]]);
    }

    /** Return posted purchase invoices with a positive AP open balance. */
    public function outstanding(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $input = $request->validate([
            'supplier_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $this->payments->outstanding(
                (int) $companyId,
                isset($input['supplier_id']) ? (int) $input['supplier_id'] : null,
                $input['as_of_date'] ?? null,
            ),
        ]);
    }

    /** Create/post a cash payment and its typed AP allocation atomically. */
    public function pay(Request $request, int $id): JsonResponse
    {
        try {
            $companyId = TenantContext::companyId($request);
            $input = $request->validate([
                'voucher_number' => ['required', 'string', 'max:50'],
                'voucher_date' => ['required', 'date_format:Y-m-d'],
                'posting_date' => ['required', 'date_format:Y-m-d'],
                'amount_raw' => ['required', 'string', 'max:80'],
                'amount_scale' => ['nullable', 'integer', 'in:0'],
                'debit_account' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::exists('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)),
                ],
                'credit_account' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::exists('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)),
                ],
                'currency' => ['nullable', 'string', 'size:3'],
                'exchange_rate' => ['nullable', 'numeric', 'min:0'],
                'receiver_name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:1000'],
            ]);
            $result = $this->payments->pay($request->user(), $id, $input);

            $payment = (new CashPaymentResource($result['payment']))->resolve();
            $invoice = (new PurchaseInvoiceResource($result['invoice']))->resolve();

            return response()->json([
                'data' => [
                    'payment' => $payment,
                    'allocation' => $result['allocation'],
                    'invoice' => $invoice,
                ],
            ], 201);
        } catch (\Throwable $exception) {
            return app(ApiErrorResponder::class)->toResponse($exception, $request, 400);
        }
    }

    public function dimensionReadiness(int $id): JsonResponse
    {
        // Route/model binding is intentionally avoided here: the tenant global
        // scope protects the lookup just as it does the normal show endpoint.
        $invoice = $this->service->getById($id);

        return response()->json(['data' => $this->dimensionReadiness->inspect($invoice)]);
    }

    public function dimensions(int $id): JsonResponse
    {
        $invoice = $this->service->getById($id);
        $assignments = $this->dimensionAssignments->currentForPurchaseInvoice($invoice);

        return response()->json(['data' => [
            'assignment_revision' => $assignments->first()?->revision,
            'assignments' => $assignments->map(fn ($assignment) => [
                'dimension_code' => $assignment->dimension_code,
                'dimension_definition_id' => $assignment->accounting_dimension_definition_id,
                'dimension_value_id' => $assignment->accounting_dimension_value_id,
                'value_code' => $assignment->value?->code,
                'value_name' => $assignment->value?->name,
                'policy_id' => $assignment->accounting_policy_version_id,
                'policy_contract_hash' => $assignment->policy_contract_hash,
            ])->values(),
        ]]);
    }

    public function dimensionSelectionContext(int $id): JsonResponse
    {
        $invoice = $this->service->getById($id);

        return response()->json(['data' => $this->dimensionSelectionContext->inspect($invoice)]);
    }

    public function saveDimensions(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'dimension_values' => ['required', 'array', 'min:1'],
            'dimension_values.*' => ['required', 'integer', 'min:1'],
        ]);
        $invoice = $this->service->getById($id);
        $assignments = $this->dimensionAssignments->savePurchaseInvoice($request->user(), $invoice->id, $data['dimension_values']);

        return response()->json(['data' => [
            'assignment_revision' => $assignments->first()?->revision,
            'assignments' => $assignments->map(fn ($assignment) => [
                'dimension_code' => $assignment->dimension_code,
                'dimension_value_id' => $assignment->accounting_dimension_value_id,
                'policy_id' => $assignment->accounting_policy_version_id,
                'policy_contract_hash' => $assignment->policy_contract_hash,
            ])->values(),
            'posting_gate_enabled_by_this_save' => false,
        ]], 201);
    }

    public function store(StorePurchaseInvoiceRequest $request): JsonResponse
    {
        try {
            $invoice = $this->service->create($request->validated());
            $res = (new PurchaseInvoiceResource($invoice))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $invoice = $this->service->update($id, $request->all());
            $res = (new PurchaseInvoiceResource($invoice))->resolve();

            return response()->json(['data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->service->delete($id);

            return response()->json(['message' => 'Deleted successfully']);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 500);
        }
    }

    public function post(int $id): JsonResponse
    {
        try {
            $this->service->post($id);

            return response()->json(['message' => 'Posted successfully']);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 400);
        }
    }

    public function void(int $id): JsonResponse
    {
        try {
            $this->service->void($id);

            return response()->json(['message' => 'Voided successfully']);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 400);
        }
    }

    public function unpost(int $id): JsonResponse
    {
        try {
            $this->service->unpost($id);

            return response()->json(['message' => 'Unposted successfully']);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 400);
        }
    }

    public function duplicate(int $id): JsonResponse
    {
        try {
            $invoice = $this->service->duplicate($id);
            $res = (new PurchaseInvoiceResource($invoice))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 500);
        }
    }
}
