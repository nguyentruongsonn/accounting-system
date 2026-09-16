<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Services\StockReportService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockReportController extends Controller
{
    protected StockReportService $service;

    public function __construct(StockReportService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        // Keep the legacy report calculation untouched, but pass only the
        // explicitly validated filter contract into it. In particular, do not
        // allow an arbitrary query parameter to become an implicit reporting
        // input later if the service evolves.
        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            // The service accepts date-only business periods. Do not silently
            // parse ambiguous locale/timestamp strings at the API boundary.
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);
        $report = $this->service->generateReport($companyId, $filters);

        return response()->json($report);
    }
}
