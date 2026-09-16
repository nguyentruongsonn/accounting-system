<?php

namespace App\Services;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\Budget;
use App\Models\FiscalYear;
use App\Models\JournalEntryLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * Describe whether the currently modelled data can support a future
     * fiscal-context Budget-versus-Actual definition.
     *
     * This is intentionally an internal availability probe, not a reporting
     * endpoint and not an execution path. A fiscal year's `open`/`closed`
     * status is a period state; it is not evidence that a report definition,
     * budget scenario, or account/sign mapping has been approved and
     * published. Consequently this service must fail closed until those
     * contracts are modelled and approved.
     *
     * @return array{
     *     available: false,
     *     code: 'DEFINITION_UNAVAILABLE',
     *     fiscal_year_id: ?int,
     *     fiscal_year_context_found: bool,
     *     missing_contracts: list<string>
     * }
     */
    public function fiscalContextAvailability(int $companyId, int $fiscalYearId): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $fiscalYear = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->find($fiscalYearId);

        return [
            'available' => false,
            'code' => 'DEFINITION_UNAVAILABLE',
            'fiscal_year_id' => $fiscalYear?->id,
            'fiscal_year_context_found' => $fiscalYear !== null,
            'missing_contracts' => array_values(array_filter([
                $fiscalYear === null ? 'tenant_fiscal_year_context' : null,
                'approved_published_report_definition',
                'approved_budget_scenario_and_version',
                'approved_effective_dated_account_dimension_sign_mapping',
            ])),
        ];
    }

    /**
     * Set the budget for a specific account and year
     */
    public function setBudget(int $companyId, string $year, string $accountCode, array $monthlyAmounts)
    {
        return DB::transaction(function () use ($companyId, $year, $accountCode, $monthlyAmounts) {
            $companyId = $this->requireCompanyId($companyId);
            $existing = Budget::where('company_id', $companyId)
                ->where('year', $year)
                ->where('account_code', $accountCode)
                ->first();
            $before = $existing?->getAttributes() ?? [];
            $budget = Budget::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'year' => $year,
                    'account_code' => $accountCode,
                ],
                [
                    'jan' => $monthlyAmounts['jan'] ?? 0,
                    'feb' => $monthlyAmounts['feb'] ?? 0,
                    'mar' => $monthlyAmounts['mar'] ?? 0,
                    'apr' => $monthlyAmounts['apr'] ?? 0,
                    'may' => $monthlyAmounts['may'] ?? 0,
                    'jun' => $monthlyAmounts['jun'] ?? 0,
                    'jul' => $monthlyAmounts['jul'] ?? 0,
                    'aug' => $monthlyAmounts['aug'] ?? 0,
                    'sep' => $monthlyAmounts['sep'] ?? 0,
                    'oct' => $monthlyAmounts['oct'] ?? 0,
                    'nov' => $monthlyAmounts['nov'] ?? 0,
                    'dec' => $monthlyAmounts['dec'] ?? 0,
                ]
            );

            $this->auditService->record($budget, $existing ? 'budget.updated' : 'budget.created', $before, $budget->getAttributes(), null, [
                'domain' => 'budget',
                'operation' => $existing ? 'budget.updated' : 'budget.created',
                'year' => $year,
            ]);

            return $budget;
        });
    }

    /**
     * Get Budget vs Actual report for a specific year
     */
    public function getBudgetVsActualReport(int $companyId, string $year)
    {
        $companyId = $this->requireCompanyId($companyId);

        // The v1 endpoint is retained as an operational/diagnostic surface,
        // but its capability contract explicitly says production_ready=false:
        // no approved fiscal context, budget scenario/version or effective
        // account-sign mapping exists. Never emit a potentially misleading
        // financial result from a production deployment until that definition
        // is published. This does not choose or infer any account mapping.
        if (in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)) {
            throw new ReportDefinitionUnavailableException('budget_vs_actual.v1');
        }

        $budgets = Budget::where('company_id', $companyId)
            ->where('year', $year)
            ->get();

        $report = [];
        $months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

        foreach ($budgets as $budget) {
            $accountCode = $budget->account_code;

            // Lấy thực tế từ sổ cái
            $actuals = JournalEntryLine::whereHas('journalEntry', function ($q) use ($companyId, $year) {
                $q->where('company_id', $companyId)
                    ->where('status', 'posted')
                    ->whereYear('posting_date', $year);
            })
                ->where('account_code', 'like', $accountCode.'%')
                ->selectRaw('MONTH(journal_entries.posting_date) as month_num, SUM(debit_amount - credit_amount) as net_amount')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->groupBy('month_num')
                ->pluck('net_amount', 'month_num');

            $accountReport = [
                'account_code' => $accountCode,
                'monthly' => [],
                'total_budget' => 0,
                'total_actual' => 0,
                'total_variance' => 0,
            ];

            foreach ($months as $index => $monthName) {
                $monthNum = $index + 1;
                $budgetAmount = $budget->$monthName;

                // Chi phí thường là số dư Nợ (Dương), Doanh thu thường là số dư Có (Âm).
                // Nếu là tài khoản loại 5, 7 (Doanh thu, Thu nhập) thì cần đảo dấu thực tế.
                $actualAmount = $actuals->get($monthNum, 0);
                if (str_starts_with($accountCode, '5') || str_starts_with($accountCode, '7')) {
                    $actualAmount = -$actualAmount;
                }

                $variance = $budgetAmount - $actualAmount;

                $accountReport['monthly'][$monthName] = [
                    'budget' => $budgetAmount,
                    'actual' => $actualAmount,
                    'variance' => $variance,
                ];

                $accountReport['total_budget'] += $budgetAmount;
                $accountReport['total_actual'] += $actualAmount;
                $accountReport['total_variance'] += $variance;
            }

            $report[] = $accountReport;
        }

        return $report;
    }

    /**
     * Explicit company IDs remain supported for worker/CLI calls, but an
     * authenticated service caller cannot redirect a budget read/write to a
     * different tenant.
     */
    private function requireCompanyId(int $companyId): int
    {
        $actor = auth()->user();
        $actorCompanyId = $actor?->company_id;

        if ($companyId <= 0 || ($actor !== null && (int) $actorCompanyId <= 0)) {
            throw ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }

        if ($actor !== null && (int) $actorCompanyId !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }
}
