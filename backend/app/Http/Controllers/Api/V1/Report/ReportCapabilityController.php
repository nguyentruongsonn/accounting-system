<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Services\FinancialReportContextResolver;
use App\Services\ReportCapabilityService;
use Illuminate\Http\Request;

class ReportCapabilityController extends Controller
{
    public function __construct(
        private readonly ReportCapabilityService $capabilities,
        private readonly FinancialReportContextResolver $contextResolver,
    ) {}

    public function index(Request $request)
    {
        $context = $this->contextResolver->resolve($request);

        return response()->json($this->capabilities->manifest($context));
    }
}
