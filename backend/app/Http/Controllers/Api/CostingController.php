<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CostingService;
use App\Support\ApiErrorResponder;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class CostingController extends Controller
{
    protected CostingService $service;

    public function __construct(CostingService $service)
    {
        $this->service = $service;
    }

    public function allocateCosts(Request $request)
    {
        $request->validate([
            'month' => 'required|date_format:Y-m',
            'wip_ending' => 'nullable|array',
            'wip_ending.*' => 'integer|min:0',
        ]);

        try {
            $allocations = $this->service->allocateCosts(
                TenantContext::companyId($request),
                $request->month,
                $request->wip_ending ?? []
            );

            return response()->json(['message' => 'Tính giá thành thành công', 'data' => $allocations]);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, $request);
        }
    }
}
