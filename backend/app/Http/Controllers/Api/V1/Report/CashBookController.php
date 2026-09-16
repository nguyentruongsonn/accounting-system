<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Services\CashBookReportService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

final class CashBookController extends Controller
{
    public function __construct(private readonly CashBookReportService $service) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:posted,draft,voided'],
            'source' => ['nullable', 'in:receipt,payment,both'], 'account' => ['nullable', 'string', 'max:20'],
            'exclude_bank_transfers' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->service->run(TenantContext::companyId($request), $filters));
    }
}
