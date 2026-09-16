<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\AllocationAwareAgingV2Service;
use App\Services\InventoryMovementV2Adapter;
use App\Services\ManagementReportDefinitionRegistry;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Explicit v2 boundary for management reports.
 *
 * This controller deliberately never delegates to legacy report calculations.
 * Current legacy settlements lack a typed invoice target and complete
 * adjustment lineage. The v2 transport must therefore stay fail-closed even
 * when a definition is effective; it must never reuse v1 status-based logic.
 */
final class ManagementReportDraftController extends Controller
{
    public function __construct(
        private readonly ManagementReportDefinitionRegistry $definitions,
        private readonly InventoryMovementV2Adapter $inventoryMovements,
        private readonly AllocationAwareAgingV2Service $allocationAwareAging,
    ) {}

    public function accountsPayableAging(Request $request): JsonResponse
    {
        $input = $request->validate(['as_of_date' => ['required', 'date_format:Y-m-d']]);
        $companyId = TenantContext::companyId($request);
        $definition = $this->definitions->requireExecutable($companyId, 'accounts_payable_aging.v2');

        // The engine remains fail-closed until its typed adjustment contract is
        // complete. Wiring it here removes the risk of a future "enabled" API
        // silently falling back to legacy status-based aging logic.
        return response()->json([
            'schema' => 'accounts-payable-aging.v2',
            'report_key' => 'accounts_payable_aging.v2',
            'definition_version' => $definition->definition_version,
            'as_of_date' => $input['as_of_date'],
            'classification' => 'operational_accounts_payable_aging',
            'statutory_or_appendix_iv_certified' => false,
            'rows' => $this->allocationAwareAging->accountsPayable($definition, $companyId, $input['as_of_date']),
        ]);
    }

    public function accountsReceivableAging(Request $request): JsonResponse
    {
        $input = $request->validate(['as_of_date' => ['required', 'date_format:Y-m-d']]);
        $companyId = TenantContext::companyId($request);
        $definition = $this->definitions->requireExecutable($companyId, 'accounts_receivable_aging.v2');

        return response()->json([
            'schema' => 'accounts-receivable-aging.v2',
            'report_key' => 'accounts_receivable_aging.v2',
            'definition_version' => $definition->definition_version,
            'as_of_date' => $input['as_of_date'],
            'classification' => 'operational_accounts_receivable_aging',
            'statutory_or_appendix_iv_certified' => false,
            'rows' => $this->allocationAwareAging->accountsReceivable($definition, $companyId, $input['as_of_date']),
        ]);
    }

    public function stockMovement(Request $request): JsonResponse
    {
        $input = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'item_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->assertStrictIsoDate($input['from_date'], 'from_date');
        $this->assertStrictIsoDate($input['to_date'], 'to_date');
        $companyId = TenantContext::companyId($request);
        $definition = $this->definitions->requireExecutableWithExactAmountContract(
            $companyId,
            'stock_movement_source.v2',
            InventoryMovementV2Adapter::supportedAmountContract(),
        );
        if ($definition->source_contract !== InventoryMovementV2Adapter::supportedSourceContract()
            || $definition->calculation_contract !== InventoryMovementV2Adapter::supportedCalculationContract()) {
            throw new ReportDefinitionUnavailableException('stock_movement_source.v2');
        }

        $filters = [];
        foreach (['item_id', 'warehouse_id'] as $filter) {
            if (array_key_exists($filter, $input) && $input[$filter] !== null) {
                $filters[$filter] = (int) $input[$filter];
            }
        }

        return response()->json([
            'schema' => 'stock-movement-source.v2',
            'report_key' => 'stock_movement_source.v2',
            'definition_version' => $definition->definition_version,
            'from_date' => $input['from_date'],
            'to_date' => $input['to_date'],
            'classification' => 'operational_inventory_movement_source',
            'stock_balance_or_valuation' => false,
            'statutory_or_appendix_iv_certified' => false,
            'production_ready' => false,
            'movements' => $this->inventoryMovements->movements(
                $companyId,
                $input['from_date'],
                $input['to_date'],
                $definition->source_contract,
                $definition->amount_contract,
                $filters,
            ),
        ]);
    }

    public function budgetVsActual(Request $request)
    {
        $request->validate([
            'fiscal_year_id' => ['required', 'integer', 'min:1'],
            'scenario_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->definitions->requireExecutable(TenantContext::companyId($request), 'budget_vs_actual.v2');

        // A fiscal year row is not an approved planning scenario, account /
        // dimension map, sign convention, currency policy or variance rule.
        // Never reinterpret the calendar-year legacy endpoint as that policy.
        throw new ReportDefinitionUnavailableException('budget_vs_actual.v2');
    }

    private function assertStrictIsoDate(string $value, string $field): void
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                $field => ['The '.$field.' must be a valid ISO calendar date.'],
            ]);
        }
    }
}
