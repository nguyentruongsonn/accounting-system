<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ToolsEquipmentService;
use App\Support\ApiErrorResponder;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ToolEquipmentController extends Controller
{
    protected ToolsEquipmentService $service;

    public function __construct(ToolsEquipmentService $service)
    {
        $this->service = $service;
    }

    public function runAllocation(Request $request)
    {
        $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        try {
            // company_id is deliberately not validated or read from the
            // request.  The authenticated principal is the only company
            // authority for a posting run.
            $log = $this->service->runMonthlyAllocation(TenantContext::companyId($request), $request->month);

            return response()->json(['message' => 'Phân bổ CCDC thành công', 'data' => $log]);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, $request);
        }
    }
}
