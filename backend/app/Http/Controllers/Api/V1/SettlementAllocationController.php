<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Services\SettlementAllocationReversalService;
use App\Services\SettlementAllocationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Explicit AP/AR settlement-evidence API.
 *
 * This does not reinterpret legacy invoice_id values. Clients must supply a
 * typed payment/receipt source line or a typed posted debt-reduction
 * adjustment, plus a typed purchase/sales invoice target after both documents
 * are posted.
 */
final class SettlementAllocationController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly SettlementAllocationService $allocations,
        private readonly SettlementAllocationReversalService $reversals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $input = $request->validate([
                'target_document_type' => ['required', 'in:purchase_invoice,sales_invoice'],
                'target_document_id' => ['required', 'integer', 'min:1'],
            ]);

            return response()->json([
                'data' => $this->allocations->forTarget(
                    TenantContext::companyId($request),
                    $input['target_document_type'],
                    (int) $input['target_document_id'],
                ),
            ]);
        } catch (\Throwable $exception) {
            return $this->apiError($request, $exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $input = $request->validate([
                'source_document_type' => ['required', 'in:cash_payment,bank_payment,cash_receipt,bank_receipt,purchase_return,purchase_discount,sales_return,sales_discount'],
                'source_document_id' => ['required', 'integer', 'min:1'],
                'source_line_type' => ['nullable', 'string', 'max:40'],
                'source_line_id' => ['nullable', 'integer', 'min:1'],
                'target_document_type' => ['required', 'in:purchase_invoice,sales_invoice'],
                'target_document_id' => ['required', 'integer', 'min:1'],
                'allocation_kind' => ['required', 'in:settlement,credit_note,return,discount,write_off'],
                'amount_raw' => ['required', 'string', 'max:80'],
                'amount_scale' => ['required', 'integer', 'min:0', 'max:12'],
                'currency_code' => ['nullable', 'string', 'size:3'],
                'functional_currency_code' => ['nullable', 'string', 'size:3'],
                'functional_amount_raw' => ['nullable', 'string', 'max:80'],
                'functional_amount_scale' => ['nullable', 'integer', 'min:0', 'max:12'],
                'original_currency_code' => ['nullable', 'string', 'size:3'],
                'original_amount_raw' => ['nullable', 'string', 'max:80'],
                'original_amount_scale' => ['nullable', 'integer', 'min:0', 'max:12'],
                'effective_date' => ['required', 'date_format:Y-m-d'],
            ]);

            $allocation = $this->allocations->createPosted($request->user(), $input);

            return response()->json(['data' => $allocation], 201);
        } catch (\Throwable $exception) {
            return $this->apiError($request, $exception);
        }
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        try {
            $input = $request->validate([
                'reason' => ['required', 'string', 'max:1000'],
                'posting_date' => ['required', 'date_format:Y-m-d'],
            ]);

            return response()->json(['data' => $this->reversals->reverse(
                $request->user(),
                $id,
                $input['reason'],
                $input['posting_date'],
            )], 201);
        } catch (\Throwable $exception) {
            return $this->apiError($request, $exception);
        }
    }
}
