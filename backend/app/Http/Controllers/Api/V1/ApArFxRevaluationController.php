<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApArFxRevaluation;
use App\Services\ApArFxRevaluationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApArFxRevaluationController extends Controller
{
    public function __construct(private readonly ApArFxRevaluationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        return response()->json(
            ApArFxRevaluation::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->latest('id')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ledger' => ['required', 'in:ap,ar'], 'reference_document_id' => ['required', 'integer', 'min:1'],
            'voucher_number' => ['nullable', 'string', 'max:80'], 'voucher_date' => ['required', 'date_format:Y-m-d'], 'accounting_date' => ['required', 'date_format:Y-m-d'],
            'original_currency' => ['required', 'string', 'size:3'], 'foreign_open_amount_raw' => ['required', 'string', 'max:80'], 'foreign_open_amount_scale' => ['required', 'integer', 'min:0', 'max:12'],
            'closing_exchange_rate_raw' => ['required', 'string', 'max:80'], 'closing_exchange_rate_scale' => ['required', 'integer', 'min:0', 'max:12'],
            'carrying_functional_amount' => ['required', 'string', 'max:80'], 'revalued_functional_amount' => ['required', 'string', 'max:80'], 'adjustment_functional_amount' => ['required', 'string', 'max:80'],
            'debit_account' => ['required', 'string', 'max:20'], 'credit_account' => ['required', 'string', 'max:20'], 'reason' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->service->create($request->user(), $data)], 201);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->post($request->user(), $id)]);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['voucher_number' => ['required', 'string', 'max:80'], 'accounting_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $this->service->reverse($request->user(), $id, $data['voucher_number'], $data['accounting_date'], $data['reason'])], 201);
    }
}
