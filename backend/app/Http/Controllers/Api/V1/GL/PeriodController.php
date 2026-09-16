<?php

namespace App\Http\Controllers\Api\V1\GL;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePeriodRequest;
use App\Services\PeriodService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    protected PeriodService $service;

    public function __construct(PeriodService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $periods = $this->service->getAllForCompany(TenantContext::companyId($request));

        return response()->json($periods);
    }

    public function store(StorePeriodRequest $request)
    {
        $period = $this->service->createForCompany(TenantContext::companyId($request), $request->validated());

        return response()->json($period, 201);
    }

    public function close(Request $request)
    {
        $validated = $request->validate([
            'period_id' => 'required|integer',
        ]);

        $this->service->rejectDirectClose(TenantContext::companyId($request), $validated['period_id']);
    }

    public function reopen(Request $request, int $periodId)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->service->reopenForCompany(
                TenantContext::companyId($request),
                $periodId,
                $validated['reason'],
            ),
        ]);
    }
}
