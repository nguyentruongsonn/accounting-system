<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DebtAdjustment;
use App\Services\DebtAdjustmentService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DebtAdjustmentController extends Controller
{
    public function __construct(private readonly DebtAdjustmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        return response()->json(
            DebtAdjustment::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->latest('id')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ledger' => ['required', 'in:ap,ar'],
            'adjustment_kind' => ['required', 'in:credit_note,write_off'],
            'voucher_number' => ['nullable', 'string', 'max:80'],
            'voucher_date' => ['required', 'date_format:Y-m-d'],
            'accounting_date' => ['required', 'date_format:Y-m-d'],
            'reference_document_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'debit_account' => ['required', 'string', 'max:20'],
            'credit_account' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
        ]);

        return response()->json($this->service->create($request->user(), $data), 201);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->post($request->user(), $id)]);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'voucher_number' => ['required', 'string', 'max:80'],
            'accounting_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->service->reverse(
            $request->user(),
            $id,
            $data['voucher_number'],
            $data['accounting_date'],
            $data['description'] ?? null,
        )], 201);
    }
}
