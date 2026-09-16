<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Models\InventoryValuationRun;
use App\Services\InventoryValuationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CostCalculationController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly InventoryValuationService $valuationService
    ) {}

    public function run(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        try {
            $data = $request->validate([
                'company_id' => 'nullable|integer',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
                'method' => 'nullable|string|in:weighted_average,fifo',
                'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
                'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            ]);

            $data['company_id'] = $companyId;

            $result = $this->valuationService->runCostCalculation($data);

            return response()->json($result);
        } catch (\Throwable $e) {
            return $this->apiError($request, $e, 422);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'method' => 'nullable|string|in:weighted_average,fifo',
            'status' => 'nullable|string|in:completed,invalidated',
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
        ]);

        $runs = InventoryValuationRun::query()
            ->where('company_id', $companyId)
            ->when($data['from_date'] ?? null, fn ($query, $fromDate) => $query->whereDate('to_date', '>=', $fromDate))
            ->when($data['to_date'] ?? null, fn ($query, $toDate) => $query->whereDate('from_date', '<=', $toDate))
            ->when($data['method'] ?? null, fn ($query, $method) => $query->where('method', $method))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null, fn ($query) => $query->where('warehouse_id', $data['warehouse_id']))
            ->when(array_key_exists('item_id', $data) && $data['item_id'] !== null, fn ($query) => $query->where('item_id', $data['item_id']))
            ->latest('completed_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $runs->map(fn (InventoryValuationRun $run) => [
                'id' => $run->id,
                'from_date' => $run->from_date?->toDateString(),
                'to_date' => $run->to_date?->toDateString(),
                'method' => $run->method,
                'status' => $run->status,
                'has_unverified_cost' => (bool) $run->has_unverified_cost,
                'warehouse_id' => $run->warehouse_id,
                'item_id' => $run->item_id,
                'updated_issues_count' => $run->updated_issues_count,
                'total_cost_amount' => $run->total_cost_amount,
                'completed_at' => $run->completed_at?->toISOString(),
                'invalidated_at' => $run->invalidated_at?->toISOString(),
                'invalidation_reason' => $run->invalidation_reason,
            ]),
        ]);
    }
}
