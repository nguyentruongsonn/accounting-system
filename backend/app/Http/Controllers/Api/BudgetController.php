<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BudgetService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    protected BudgetService $service;

    public function __construct(BudgetService $service)
    {
        $this->service = $service;
    }

    public function setBudget(Request $request)
    {
        $request->validate([
            // Retained as a tolerated legacy parameter, but never authority.
            'company_id' => 'nullable|integer',
            'year' => 'required|date_format:Y',
            'account_code' => 'required|string',
            'amounts' => 'required|array',
        ]);

        $budget = $this->service->setBudget(
            TenantContext::companyId($request),
            $request->year,
            $request->account_code,
            $request->amounts
        );

        return response()->json(['message' => 'Lập ngân sách thành công', 'data' => $budget]);
    }

    public function report(Request $request)
    {
        $request->validate([
            // Retained as a tolerated legacy parameter, but never authority.
            'company_id' => 'nullable|integer',
            'year' => 'required|date_format:Y',
        ]);

        $report = $this->service->getBudgetVsActualReport(TenantContext::companyId($request), $request->year);

        return response()->json(['data' => $report]);
    }
}
