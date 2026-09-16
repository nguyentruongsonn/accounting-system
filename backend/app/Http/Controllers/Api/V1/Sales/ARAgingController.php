<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Services\ARAgingService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ARAgingController extends Controller
{
    protected ARAgingService $service;

    public function __construct(ARAgingService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $input = $request->validate(['as_of_date' => ['nullable', 'date_format:Y-m-d']]);
        $report = array_key_exists('as_of_date', $input)
            ? $this->service->generateReport($companyId, $input['as_of_date'])
            : $this->service->generateReport($companyId);

        return response()->json($report);
    }
}
