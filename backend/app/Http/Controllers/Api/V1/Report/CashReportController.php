<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Services\CashReportService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CashReportController extends Controller
{
    public function __construct(private readonly CashReportService $service) {}

    public function show(Request $request, string $code): JsonResponse
    {
        abort_unless(in_array($code, CashReportService::CODES, true), 422, 'Unsupported cash report code.');

        $filters = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:posted,draft,voided,all'],
            'cash_account' => ['nullable', 'regex:/^111[0-9]*$/'],
        ]);

        $filters['status'] = $filters['status'] ?? 'posted';
        $filters['search'] = trim($filters['search'] ?? '');
        $filters['cash_account'] = $filters['cash_account'] ?? null;

        if (in_array($code, ['CA-01', 'CA-02', 'CA-03'], true) && $filters['status'] !== 'posted') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => 'Báo cáo số dư chỉ nhận chứng từ đã ghi sổ.',
            ]);
        }

        return response()->json(['data' => $this->service->generate(
            TenantContext::companyId($request),
            $code,
            $filters,
        )]);
    }
}
