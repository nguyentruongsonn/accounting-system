<?php

namespace App\Http\Controllers;

use App\Models\CashForecast;
use App\Services\AuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashForecastController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $forecasts = CashForecast::query()
            ->where('company_id', $companyId)
            ->with('items')
            ->orderByDesc('id')
            ->get();

        return response()->json($forecasts);
    }

    public function store(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate([
            'period_name' => 'required|string',
            'from_date' => 'required|date',
            'to_date' => 'required|date',
        ]);

        return DB::transaction(function () use ($companyId, $request) {
            $forecast = CashForecast::create([
                'company_id' => $companyId,
                'period_name' => $request->input('period_name'),
                'from_date' => $request->input('from_date'),
                'to_date' => $request->input('to_date'),
                // Use a role label when a caller does not provide explicit
                // creator evidence; never manufacture a person's name.
                'creator' => $request->input('creator', 'ACCOUNTANT'),
                'created_date' => $request->input('created_date', now()->toDateString()),
                'opening_balance' => $request->input('opening_balance', 0),
                'expected_inflow' => $request->input('expected_inflow', 0),
                'expected_outflow' => $request->input('expected_outflow', 0),
                'closing_balance' => $request->input('closing_balance', 0),
            ]);

            $items = $request->input('items', []);
            foreach ($items as $index => $item) {
                $forecast->items()->create([
                    'code' => $item['code'],
                    'name' => $item['name'],
                    'amount' => $item['amount'] ?? 0,
                    'is_parent' => $item['isParent'] ?? $item['is_parent'] ?? false,
                    'is_removable' => $item['isRemovable'] ?? $item['is_removable'] ?? false,
                    'sort_order' => $index,
                ]);
            }

            $this->auditService->record($forecast, 'cash_forecast.created', [], $forecast->getAttributes(), null, [
                'domain' => 'cash_forecast',
                'operation' => 'cash_forecast.created',
                'item_count' => count($items),
            ]);

            return response()->json($forecast->load('items'), 201);
        });
    }

    public function show(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $forecast = CashForecast::query()
            ->where('company_id', $companyId)
            ->with('items')
            ->findOrFail($id);

        return response()->json($forecast);
    }
}
