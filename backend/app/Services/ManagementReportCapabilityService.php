<?php

namespace App\Services;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\ManagementReportDefinition;

/**
 * Honest boundary for operational management reports.
 *
 * This describes existing operational endpoints without changing their response
 * contracts or implying statutory, tax, TT99, or Appendix IV status.
 */
final class ManagementReportCapabilityService
{
    public function __construct(
        private readonly ManagementReportDefinitionRegistry $definitions,
        private readonly ApArAgingV2ReadinessService $aparAgingReadiness,
    ) {}

    /** @return array<string, mixed> */
    public function manifest(int $companyId, callable $hasPermission): array
    {
        $capabilities = [
            [
                'key' => 'accounts_payable_aging',
                'label' => 'Phân tích tuổi nợ phải trả',
                'route' => 'purchase/ap-aging',
                'api_path' => '/api/v1/purchase/ap-aging',
                'http_method' => 'GET',
                'status' => 'available',
                'available' => true,
                'read_only' => true,
                'required_permission' => 'purchase.reports.view',
                'tenant_scope' => 'authenticated_tenant',
                'tenant_enforced_at_endpoint' => true,
                'date_semantics' => 'Optional as_of_date (YYYY-MM-DD); invoices and posted allocations after the cutoff are excluded. If omitted, the server date is used.',
                'accepted_filters' => ['as_of_date'],
                'source' => ['suppliers', 'purchase_invoices', 'settlement_allocations'],
                'observed_legacy_behavior' => 'Posted purchase invoices are reduced by posted settlement allocations effective on or before the cutoff and bucketed by due_date into current, 1-30, 31-60 and over-60 days.',
                'definition_version' => null,
                'statutory_or_appendix_iv_certified' => false,
                'tenant_boundary_ready' => true,
                'production_ready' => false,
                'reason' => 'Operational aging semantics are implemented, but no owner-approved aging/reconciliation policy or reporting definition version is present.',
            ],
            [
                'key' => 'accounts_receivable_aging',
                'label' => 'Phân tích tuổi nợ phải thu',
                'route' => 'sales/ar-aging',
                'api_path' => '/api/v1/sales/ar-aging',
                'http_method' => 'GET',
                'status' => 'available',
                'available' => true,
                'read_only' => true,
                'required_permission' => 'sales.reports.view',
                'tenant_scope' => 'authenticated_tenant',
                'tenant_enforced_at_endpoint' => true,
                'date_semantics' => 'Optional as_of_date (YYYY-MM-DD); invoices and posted allocations after the cutoff are excluded. If omitted, the server date is used.',
                'accepted_filters' => ['as_of_date'],
                'source' => ['customers', 'sales_invoices', 'settlement_allocations'],
                'observed_legacy_behavior' => 'Posted sales invoices are reduced by posted settlement allocations effective on or before the cutoff and bucketed by due_date into current, 1-30, 31-60 and over-60 days.',
                'definition_version' => null,
                'statutory_or_appendix_iv_certified' => false,
                'tenant_boundary_ready' => true,
                'production_ready' => false,
                'reason' => 'Operational aging semantics are implemented, but no owner-approved aging/reconciliation policy or reporting definition version is present.',
            ],
            [
                'key' => 'stock_movement_balance',
                'label' => 'Báo cáo tồn kho vận hành',
                'route' => 'inventory/stock-report',
                'api_path' => '/api/v1/inventory/stock-report',
                'http_method' => 'GET',
                'status' => 'available',
                'available' => true,
                'implementation_status' => 'operational_draft',
                'read_only' => true,
                'required_permission' => 'inventory.stock-report.view',
                'tenant_scope' => 'authenticated_tenant',
                'tenant_enforced_at_endpoint' => true,
                'date_semantics' => 'Optional from_date/to_date; opening movement is before from_date and current movement is constrained by the supplied date range.',
                'accepted_filters' => ['warehouse_id', 'item_id', 'from_date', 'to_date'],
                'source' => ['items', 'opening_balance_inventory_lines', 'inventory_receipt_lines', 'inventory_issue_lines', 'inventory_movement_events'],
                'observed_legacy_behavior' => 'Per item, sums confirmed opening balances, posted receipt/issue lines, and signed warehouse movement events, then derives opening plus inward minus outward ending values.',
                'definition_version' => null,
                'statutory_or_appendix_iv_certified' => false,
                'tenant_boundary_ready' => true,
                'production_ready' => false,
                'reason' => 'Tenant scope, physical movement source selection, and exact monetary arithmetic are enforced. The capability remains an operational draft until an owner-approved report definition and reconciliation sign-off are recorded.',
            ],
            [
                'key' => 'budget_vs_actual',
                'label' => 'Ngân sách so với thực tế',
                'route' => 'budgets/report',
                'api_path' => '/api/v1/budgets/report',
                'http_method' => 'GET',
                'status' => 'available',
                'available' => true,
                'read_only' => true,
                'required_permission' => 'budgets.view',
                'tenant_scope' => 'authenticated_tenant',
                'tenant_enforced_at_endpoint' => true,
                'date_semantics' => 'Requires calendar year; it does not resolve or validate a fiscal-year/reporting-regime context.',
                'accepted_filters' => ['year'],
                'tolerated_ignored_parameters' => ['company_id'],
                'source' => ['budgets', 'journal_entries', 'journal_entry_lines'],
                'observed_legacy_behavior' => 'Monthly budget amounts are compared with posted journal line debit-minus-credit net amounts by account-code prefix; code prefixes 5 and 7 invert the displayed actual sign.',
                'definition_version' => null,
                'statutory_or_appendix_iv_certified' => false,
                'tenant_boundary_ready' => true,
                'production_ready' => false,
                'reason' => 'Tenant scope is enforced, but the endpoint has no approved fiscal-context/definition version and its current output contract is an operational draft.',
            ],
        ];

        $capabilities[] = [
            'key' => 'inventory_valuation_reconciliation',
            'label' => 'Đối chiếu giá trị tồn kho',
            'route' => 'inventory/subledger-gl-reconciliations',
            'api_path' => '/api/v1/inventory/subledger-gl-reconciliations',
            'http_method' => 'GET',
            'status' => 'available',
            'available' => true,
            'implementation_status' => 'operational_draft',
            'read_only' => true,
            'required_permission' => 'inventory.reconciliations.view',
            'tenant_scope' => 'authenticated_tenant',
            'tenant_enforced_at_endpoint' => true,
            'date_semantics' => 'POST capture requires as_of_date (YYYY-MM-DD); GET lists immutable runs for the authenticated tenant.',
            'accepted_filters' => ['as_of_date', 'per_page'],
            'source' => ['opening_balance_inventory_lines', 'inventory_receipt_lines', 'inventory_issue_lines', 'inventory_movement_events', 'journal_entry_lines'],
            'observed_legacy_behavior' => 'The server-controlled endpoint computes confirmed opening stock plus posted receipt/issue/valued transfer movements and compares the result with the approved inventory control-account set.',
            'definition_version' => null,
            'statutory_or_appendix_iv_certified' => false,
            'tenant_boundary_ready' => true,
            'production_ready' => false,
            'reason' => 'The tenant-scoped endpoint is available for internal control evidence. It remains operational-only and requires an effective approved inventory reconciliation policy before calculating a controlled result.',
        ];

        $capabilities = array_map(
            function (array $capability) use ($companyId, $hasPermission): array {
                $normalized = array_replace($this->capabilityDefaults(), $capability);
                $normalized['v2_execution'] = $this->v2ExecutionBoundary($companyId, $normalized['key']);
                $normalized['authorized_for_current_actor'] = $normalized['required_permission'] !== null
                    && (bool) $hasPermission($normalized['required_permission']);

                return $normalized;
            },
            $capabilities,
        );

        return [
            'meta' => [
                'manifest_version' => 'management-report-capabilities.v1',
                'company_id' => $companyId,
                'classification' => 'operational_management_reporting',
                'read_only' => true,
                'statutory_or_appendix_iv_certified' => false,
                'disclaimer' => 'Báo cáo nội bộ, chỉ đọc; số liệu lấy trực tiếp từ máy chủ theo bộ lọc.',
            ],
            'capabilities' => $capabilities,
        ];
    }

    /** @return array<string, mixed> */
    private function capabilityDefaults(): array
    {
        return [
            'schema' => 'management-report-capability.v1',
            'key' => null,
            'label' => null,
            'route' => null,
            'api_path' => null,
            'http_method' => null,
            'status' => 'not_implemented',
            'available' => false,
            'implementation_status' => 'operational_draft',
            'read_only' => true,
            'required_permission' => null,
            'authorized_for_current_actor' => false,
            'tenant_scope' => 'not_applicable',
            'tenant_enforced_at_endpoint' => false,
            'date_semantics' => null,
            'accepted_filters' => [],
            'tolerated_ignored_parameters' => [],
            'source' => [],
            'observed_legacy_behavior' => null,
            'definition_version' => null,
            'statutory_or_appendix_iv_certified' => false,
            'tenant_boundary_ready' => false,
            'production_ready' => false,
            'reason' => null,
            'v2_execution' => [
                'available' => false,
                'route' => null,
                'api_path' => null,
                'http_method' => null,
                'status' => 'not_applicable',
                'definition_version' => null,
                'production_ready' => false,
                'reason' => 'No v2 execution boundary is implemented for this management-report capability.',
            ],
        ];
    }

    /**
     * Return only a v2 route that the definition registry can currently prove
     * is backed by an effective, signed, exact-contract definition. This is a
     * discovery contract: it must not advertise the physical fail-closed
     * transport route while the definition prerequisite is absent.
     *
     * @return array<string, bool|null|string>
     */
    private function v2ExecutionBoundary(int $companyId, ?string $capabilityKey): array
    {
        $v2 = match ($capabilityKey) {
            'accounts_payable_aging' => [
                'report_key' => 'accounts_payable_aging.v2',
                'route' => 'management-reports/ap-aging',
                'api_path' => '/api/v2/management-reports/ap-aging',
            ],
            'accounts_receivable_aging' => [
                'report_key' => 'accounts_receivable_aging.v2',
                'route' => 'management-reports/ar-aging',
                'api_path' => '/api/v2/management-reports/ar-aging',
            ],
            'stock_movement_balance' => [
                'report_key' => 'stock_movement_source.v2',
                'route' => 'management-reports/stock-movement',
                'api_path' => '/api/v2/management-reports/stock-movement',
            ],
            default => null,
        };

        if ($v2 === null) {
            return [
                'available' => false,
                'route' => null,
                'api_path' => null,
                'http_method' => null,
                'status' => 'not_applicable',
                'definition_version' => null,
                'production_ready' => false,
                'reason' => 'No v2 execution boundary is implemented for this management-report capability.',
            ];
        }

        try {
            $definition = $this->definitions->requireExecutable($companyId, $v2['report_key']);
        } catch (ReportDefinitionUnavailableException) {
            return [
                'available' => false,
                'route' => null,
                'api_path' => null,
                'http_method' => null,
                'status' => 'definition_unavailable',
                'definition_version' => null,
                'production_ready' => false,
                'reason' => 'No effective signed exact-contract v2 definition is available; the fail-closed v2 transport route is intentionally not advertised.',
            ];
        }

        return $this->effectiveV2ExecutionBoundary($definition, $v2);
    }

    /** @param array{report_key: string, route: string, api_path: string} $v2 */
    private function effectiveV2ExecutionBoundary(ManagementReportDefinition $definition, array $v2): array
    {
        if ($this->supportsStockMovementAdapter($definition)) {
            return [
                'available' => true,
                'route' => $v2['route'],
                'api_path' => $v2['api_path'],
                'http_method' => 'GET',
                'status' => 'operational_definition_effective',
                'definition_version' => $definition->definition_version,
                'completeness_ready' => true,
                'production_ready' => false,
                'reason' => 'Read-only posted inventory movement source is available. It is not a stock balance, valuation, reconciliation, report run, export, statutory report, tax result, or TT99/Appendix IV certification.',
            ];
        }

        $ledger = $definition->report_key === 'accounts_payable_aging.v2' ? 'ap' : 'ar';
        $readiness = $this->aparAgingReadiness->evaluate($ledger);

        return [
            'available' => false,
            'route' => $v2['route'],
            'api_path' => $v2['api_path'],
            'http_method' => 'GET',
            'status' => 'definition_effective_source_lineage_incomplete',
            'definition_version' => $definition->definition_version,
            'completeness_ready' => false,
            'incomplete_sources' => $this->incompleteV2Sources($definition),
            'missing_conditions' => $readiness['missing_conditions'],
            'production_ready' => false,
            'reason' => 'A definition exists, but typed settlement and adjustment lineage are incomplete. Execution remains fail-closed; this is not an approved report run, export, statutory report, tax result, or TT99/Appendix IV certification.',
        ];
    }

    /** @return list<string> */
    private function incompleteV2Sources(ManagementReportDefinition $definition): array
    {
        $adjustments = is_array($definition->source_contract)
            ? ($definition->source_contract['adjustment_completeness'] ?? null)
            : null;
        if (! is_array($adjustments) || ($adjustments['status'] ?? null) === 'available') {
            return [];
        }

        $required = $adjustments['required_sources'] ?? [];
        $available = $adjustments['available_sources'] ?? [];
        if (! is_array($required) || ! is_array($available)) {
            return [];
        }

        return array_values(array_filter(
            $required,
            static fn (mixed $source): bool => is_string($source) && ! in_array($source, $available, true),
        ));
    }

    private function supportsStockMovementAdapter(ManagementReportDefinition $definition): bool
    {
        return $definition->report_key === 'stock_movement_source.v2'
            && $definition->source_contract === InventoryMovementV2Adapter::supportedSourceContract()
            && $definition->calculation_contract === InventoryMovementV2Adapter::supportedCalculationContract()
            && $definition->amount_contract === InventoryMovementV2Adapter::supportedAmountContract();
    }
}
