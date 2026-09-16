<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ToolEquipment;
use App\Services\ToolsEquipmentService;
use App\Support\ApiErrorResponder;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ToolEquipmentController extends Controller
{
    protected ToolsEquipmentService $service;

    public function __construct(ToolsEquipmentService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        TenantContext::companyId($request);

        return response()->json(['data' => ToolEquipment::query()->orderBy('tool_code')->get()]);
    }

    public function store(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $data = $request->validate([
            'tool_code' => ['required', 'string', 'max:50', 'unique:tool_equipments,tool_code'],
            'tool_name' => ['required', 'string', 'max:255'],
            'purchase_date' => ['required', 'date_format:Y-m-d'],
            'original_cost' => ['required', 'integer', 'min:0'],
            'allocation_months' => ['required', 'integer', 'min:1', 'max:240'],
            'tool_account' => ['required', 'string', 'max:20'],
            'expense_account' => ['required', 'string', 'max:20'],
        ]);
        $data['company_id'] = $companyId;

        try {
            $tool = $this->service->createTool($data);

            return response()->json(['data' => $tool], 201);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, $request);
        }
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

    public function previewAllocation(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return response()->json(['data' => $this->service->previewMonthlyAllocation(
            TenantContext::companyId($request),
            $data['month'],
        )]);
    }

    public function update(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $data = $request->validate([
            'tool_code' => ['sometimes', 'string', 'max:50', Rule::unique('tool_equipments', 'tool_code')->ignore($id)],
            'tool_name' => ['sometimes', 'string', 'max:255'],
            'purchase_date' => ['sometimes', 'date_format:Y-m-d'],
            'original_cost' => ['sometimes', 'integer', 'min:0'],
            'allocation_months' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'tool_account' => ['sometimes', 'string', 'max:20'],
            'expense_account' => ['sometimes', 'string', 'max:20'],
        ]);

        try {
            return response()->json(['data' => $this->service->updateTool($companyId, $id, $data)]);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, $request);
        }
    }

    public function destroy(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'write_off' => ['sometimes', 'boolean'],
            'write_off_date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        try {
            if (filter_var($data['write_off'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return response()->json(['data' => $this->service->writeOffTool(
                    TenantContext::companyId($request),
                    $id,
                    $data['write_off_date'] ?? null,
                    $data['reason'] ?? null,
                )]);
            }

            return response()->json(['data' => $this->service->disableTool(
                TenantContext::companyId($request),
                $id,
                $data['reason'] ?? null,
            )]);
        } catch (\Throwable $e) {
            return app(ApiErrorResponder::class)->toResponse($e, $request);
        }
    }
}
