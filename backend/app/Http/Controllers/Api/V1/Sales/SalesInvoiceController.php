<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Resources\CashReceiptResource;
use App\Http\Resources\SalesInvoiceResource;
use App\Services\AccountingDocumentDimensionAssignmentService;
use App\Services\SalesInvoiceCollectionService;
use App\Services\SalesInvoiceDimensionReadinessService;
use App\Services\SalesInvoiceDimensionSelectionContextService;
use App\Services\SalesInvoiceService;
use App\Support\ApiErrorResponder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SalesInvoiceController extends Controller
{
    public function __construct(
        private readonly SalesInvoiceService $service,
        private readonly SalesInvoiceDimensionReadinessService $dimensionReadiness,
        private readonly SalesInvoiceDimensionSelectionContextService $dimensionSelectionContext,
        private readonly AccountingDocumentDimensionAssignmentService $dimensionAssignments,
        private readonly SalesInvoiceCollectionService $collections,
    ) {}

    public function dimensionReadiness(int $id): JsonResponse
    {
        $invoice = $this->service->getById($id);

        return response()->json(['data' => $this->dimensionReadiness->inspect($invoice)]);
    }

    public function dimensions(int $id): JsonResponse
    {
        $invoice = $this->service->getById($id);
        $assignments = $this->dimensionAssignments->currentForSalesInvoice($invoice);

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
        return response()->json(['data' => $this->dimensionSelectionContext->inspect($this->service->getById($id))]);
    }

    public function saveDimensions(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['dimension_values' => ['required', 'array', 'min:1'], 'dimension_values.*' => ['required', 'integer', 'min:1']]);
        $invoice = $this->service->getById($id);
        $assignments = $this->dimensionAssignments->saveSalesInvoice($request->user(), $invoice->id, $data['dimension_values']);

        return response()->json(['data' => [
            'assignment_revision' => $assignments->first()?->revision,
            'assignments' => $assignments->map(fn ($assignment) => ['dimension_code' => $assignment->dimension_code, 'dimension_value_id' => $assignment->accounting_dimension_value_id, 'policy_id' => $assignment->accounting_policy_version_id, 'policy_contract_hash' => $assignment->policy_contract_hash])->values(),
        ]], 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = $this->service->getAll($request->all());

        return SalesInvoiceResource::collection($invoices);
    }

    public function show(int $id): JsonResponse
    {
        $invoice = $this->service->getById($id);
        $res = (new SalesInvoiceResource($invoice))->resolve();

        return response()->json(['data' => $res, ...$res]);
    }

    /** Return posted sales invoices with a positive AR open balance. */
    public function outstanding(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $input = $request->validate([
            'customer_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => $this->collections->outstanding(
            (int) $companyId,
            isset($input['customer_id']) ? (int) $input['customer_id'] : null,
            $input['as_of_date'] ?? null,
        )]);
    }

    /** Create/post a cash receipt and its typed AR allocation atomically. */
    public function collect(Request $request, int $id): JsonResponse
    {
        try {
            $companyId = TenantContext::companyId($request);
            $input = $request->validate([
                'voucher_number' => ['required', 'string', 'max:50'],
                'voucher_date' => ['required', 'date_format:Y-m-d'],
                'posting_date' => ['required', 'date_format:Y-m-d'],
                'amount_raw' => ['required', 'string', 'max:80'],
                'amount_scale' => ['nullable', 'integer', 'in:0'],
                'debit_account' => ['required', 'string', 'max:20', Rule::exists('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
                'credit_account' => ['required', 'string', 'max:20', Rule::exists('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
                'currency' => ['nullable', 'string', 'size:3'],
                'exchange_rate' => ['nullable', 'numeric', 'min:0'],
                'payer_name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:1000'],
            ]);
            $result = $this->collections->collect($request->user(), $id, $input);
            $receipt = (new CashReceiptResource($result['receipt']))->resolve();
            $invoice = (new SalesInvoiceResource($result['invoice']))->resolve();

            return response()->json(['data' => ['receipt' => $receipt, 'allocation' => $result['allocation'], 'invoice' => $invoice]], 201);
        } catch (\Throwable $exception) {
            return app(ApiErrorResponder::class)->toResponse($exception, $request, 400);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'customer_id' => 'required',
            'customer_name' => 'nullable|string',
            'customer_address' => 'nullable|string',
            'receiver_name' => 'nullable|string',
            'employee_id' => 'nullable',
            'employee_name' => 'nullable|string',
            'voucher_type' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'invoice_number' => 'nullable|string',
            'invoice_symbol' => 'nullable|string',
            'invoice_code' => 'nullable|string',
            'delivery_voucher_number' => 'nullable|string',
            'invoice_date' => 'nullable|string',
            'accounting_date' => 'nullable|string',
            'due_date' => 'nullable|string',
            'description' => 'nullable|string',
            'attached_docs' => 'nullable',
            'currency' => 'nullable|string',
            'exchange_rate' => 'nullable|numeric',
            'functional_currency_code' => 'nullable|string|size:3',
            'functional_total_amount_raw' => 'nullable|string|max:80',
            'functional_total_amount_scale' => 'nullable|integer|min:0|max:12',
            'original_total_amount_raw' => 'nullable|string|max:80',
            'original_total_amount_scale' => 'nullable|integer|min:0|max:12',
            'is_export_slip' => 'nullable|boolean',
            'is_include_delivery' => 'nullable|boolean',
            'is_include_invoice' => 'nullable|boolean',
            'status' => 'nullable|string',
            'payment_status' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'nullable',
            'lines.*.description' => 'nullable|string',
            'lines.*.unit' => 'nullable|string',
            'lines.*.warehouse_id' => 'nullable',
            'lines.*.warehouse_code' => 'nullable|string',
            'lines.*.debit_account' => 'nullable|string',
            'lines.*.credit_account' => 'nullable|string',
            'lines.*.inventory_account' => 'nullable|string',
            'lines.*.cogs_account' => 'nullable|string',
            'lines.*.cogs_debit_account' => 'nullable|string',
            'lines.*.cogs_credit_account' => 'nullable|string',
            'lines.*.cogs_price' => 'nullable|numeric',
            'lines.*.cogs_unit_price' => 'nullable|numeric',
            'lines.*.cogs_amount' => 'nullable|numeric',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric',
            'lines.*.discount_rate' => 'nullable|numeric',
            'lines.*.discount_amount' => 'nullable|numeric',
            'lines.*.tax_rate' => 'nullable|numeric',
            'lines.*.tax_amount' => 'nullable|numeric',
            'lines.*.tax_account' => 'nullable|string',
            'referenced_vouchers' => 'nullable|array',
        ]);

        try {
            $invoice = $this->service->create($data);
            $res = (new SalesInvoiceResource($invoice))->resolve();

            return response()->json(['data' => $res, ...$res], 201);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $invoice = $this->service->update($id, $request->all());
            $res = (new SalesInvoiceResource($invoice))->resolve();

            return response()->json(['data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 422);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->service->delete($id);

            return response()->json(['message' => 'Deleted successfully']);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 422);
        }
    }

    public function post(int $id): JsonResponse
    {
        try {
            $invoice = $this->service->post($id);
            $res = (new SalesInvoiceResource($invoice))->resolve();

            return response()->json(['message' => 'Posted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 400);
        }
    }

    public function void(int $id): JsonResponse
    {
        try {
            $invoice = $this->service->void($id);
            $res = (new SalesInvoiceResource($invoice))->resolve();

            return response()->json(['message' => 'Voided successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 400);
        }
    }

    public function unpost(int $id): JsonResponse
    {
        try {
            $invoice = $this->service->unpost($id);
            $res = (new SalesInvoiceResource($invoice))->resolve();

            return response()->json(['message' => 'Unposted successfully', 'data' => $res, ...$res]);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 400);
        }
    }

    public function duplicate(int $id): JsonResponse
    {
        try {
            $invoice = $this->service->duplicate($id);

            return (new SalesInvoiceResource($invoice))
                ->response()
                ->setStatusCode(201);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, request(), 500);
        }
    }

    public function nextCode(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $prefix = $request->query('prefix', 'HDBH');
        $code = $this->service->generateNextCode($companyId, $prefix);

        return response()->json(['data' => $code, 'code' => $code]);
    }
}
