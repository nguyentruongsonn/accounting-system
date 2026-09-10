<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Services\FinancialReportContextResolver;
use App\Services\StatutoryFinancialStatementReadinessService;
use Illuminate\Http\Request;

final class StatutoryFinancialStatementReadinessController extends Controller
{
    public function __construct(
        private readonly FinancialReportContextResolver $contextResolver,
        private readonly StatutoryFinancialStatementReadinessService $readiness,
    ) {}

    public function show(Request $request)
    {
        $validated = $request->validate([
            'form_key' => ['required', 'string', 'max:120'],
        ]);
        $context = $this->contextResolver->resolve($request, 'financial_statement');

        return response()->json($this->readiness->assess($context, $validated['form_key']));
    }
}
