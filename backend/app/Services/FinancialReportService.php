<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Support\DecimalMoney;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FinancialReportService
{
    public function __construct(private readonly AccountingRegimeService $accountingRegimeService) {}

    public function getReportMetadata(
        int $companyId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $fiscalYearId = null
    ): array {
        $companyId = $this->requireCompanyId($companyId);
        return $this->accountingRegimeService->reportMetadata($companyId, $fromDate, $toDate, $fiscalYearId);
    }

    /**
     * Get account balances summary with optional date filters and closing voucher exclusion.
     */
    public function getAccountBalances(int $companyId, ?string $fromDate = null, ?string $toDate = null, bool $excludeClosing = false): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $query = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.company_id', $companyId)
            ->where('e.status', 'posted')
            ->whereNull('e.deleted_at');

        if ($excludeClosing) {
            $query->where('e.voucher_type', '!=', 'period_closing');
        }

        if ($fromDate && $toDate) {
            $query->whereBetween('e.posting_date', [$fromDate, $toDate]);
        } elseif ($toDate) {
            $query->where('e.posting_date', '<=', $toDate);
        }

        $balances = $query->select('l.account_code', DB::raw('SUM(l.debit_amount) as total_debit'), DB::raw('SUM(l.credit_amount) as total_credit'))
            ->groupBy('l.account_code')
            ->get();

        $balanceMap = [];
        foreach ($balances as $b) {
            $balanceMap[$b->account_code] = [
                'debit' => DecimalMoney::normalize($b->total_debit),
                'credit' => DecimalMoney::normalize($b->total_credit),
                'balance' => DecimalMoney::ZERO,
            ];
        }

        // Cumulative/as-of reports include the confirmed opening package.
        // Period-turnover queries (fromDate present) deliberately do not.
        if ($fromDate === null) {
            $this->mergeConfirmedOpeningBalances($balanceMap, $companyId, $toDate);
        }

        return $balanceMap;
    }

    /**
     * Compute ending balance for account prefix.
     */
    public function getEndingBalance(array $balanceMap, string $accountCode, string $nature = 'debit'): string
    {
        $debit = DecimalMoney::ZERO;
        $credit = DecimalMoney::ZERO;

        foreach ($balanceMap as $code => $data) {
            if (str_starts_with($code, $accountCode)) {
                $debit = DecimalMoney::add($debit, $data['debit']);
                $credit = DecimalMoney::add($credit, $data['credit']);
            }
        }

        if ($nature === 'debit') {
            // Keep an opposite-side balance signed.  Clamping a credit
            // balance on a debit-nature account to zero hides overpayments,
            // reversals and reconciliation differences from internal reports.
            return DecimalMoney::subtract($debit, $credit);
        } elseif ($nature === 'credit') {
            return DecimalMoney::subtract($credit, $debit);
        }

        return DecimalMoney::subtract($debit, $credit);
    }

    /**
     * Compute net turnover for account prefix (Credit - Debit for revenue, Debit - Credit for expenses).
     */
    public function getTurnover(array $balanceMap, string $accountCode, string $type = 'credit_turnover'): string
    {
        $debit = DecimalMoney::ZERO;
        $credit = DecimalMoney::ZERO;

        foreach ($balanceMap as $code => $data) {
            if (str_starts_with($code, $accountCode)) {
                $debit = DecimalMoney::add($debit, $data['debit']);
                $credit = DecimalMoney::add($credit, $data['credit']);
            }
        }

        if ($type === 'credit_turnover') {
            return DecimalMoney::subtract($credit, $debit);
        } elseif ($type === 'debit_turnover') {
            return DecimalMoney::subtract($debit, $credit);
        }

        return DecimalMoney::ZERO;
    }

    /**
     * Bảng cân đối phát sinh tài khoản. Regime metadata is resolved separately
     * from the company fiscal-year profile; formulas are not versioned yet.
     */
    public function getTrialBalance(int $companyId, ?string $fromDate = null, ?string $toDate = null)
    {
        $companyId = $this->requireCompanyId($companyId);
        $accounts = ChartOfAccount::where('company_id', $companyId)->orderBy('code')->get();

        $openingMap = [];
        if ($fromDate) {
            // Opening balance before fromDate
            $openingBalances = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'l.journal_entry_id', '=', 'e.id')
                ->where('e.company_id', $companyId)
                ->where('e.status', 'posted')
                ->whereNull('e.deleted_at')
                ->where('e.posting_date', '<', $fromDate)
                ->select('l.account_code', DB::raw('SUM(l.debit_amount) as total_debit'), DB::raw('SUM(l.credit_amount) as total_credit'))
                ->groupBy('l.account_code')
                ->get();

            foreach ($openingBalances as $b) {
                $openingMap[$b->account_code] = [
                    'debit' => DecimalMoney::normalize($b->total_debit),
                    'credit' => DecimalMoney::normalize($b->total_credit),
                ];
            }
            $this->mergeConfirmedOpeningBalances($openingMap, $companyId, $fromDate);
        }

        // Period arising
        $arisingMap = $this->getAccountBalances($companyId, $fromDate, $toDate);

        // Step 1: Calculate leaf account amounts
        $accountData = [];
        foreach ($accounts as $acc) {
            $code = $acc->code;

            $opDebit = DecimalMoney::ZERO;
            $opCredit = DecimalMoney::ZERO;
            if (isset($openingMap[$code])) {
                $rawOpD = $openingMap[$code]['debit'];
                $rawOpC = $openingMap[$code]['credit'];
                if ($acc->nature === 'debit') {
                    $net = DecimalMoney::subtract($rawOpD, $rawOpC);
                    $opDebit = DecimalMoney::maxZero($net);
                    $opCredit = DecimalMoney::maxZero(DecimalMoney::negate($net));
                } elseif ($acc->nature === 'credit') {
                    $net = DecimalMoney::subtract($rawOpC, $rawOpD);
                    $opCredit = DecimalMoney::maxZero($net);
                    $opDebit = DecimalMoney::maxZero(DecimalMoney::negate($net));
                } else {
                    $net = DecimalMoney::subtract($rawOpD, $rawOpC);
                    $opDebit = DecimalMoney::maxZero($net);
                    $opCredit = DecimalMoney::maxZero(DecimalMoney::negate($net));
                }
            }

            $arDebit = $arisingMap[$code]['debit'] ?? DecimalMoney::ZERO;
            $arCredit = $arisingMap[$code]['credit'] ?? DecimalMoney::ZERO;

            // Ending balance
            $netMovement = DecimalMoney::add(
                DecimalMoney::subtract($opDebit, $opCredit),
                DecimalMoney::subtract($arDebit, $arCredit)
            );
            $endDebit = DecimalMoney::ZERO;
            $endCredit = DecimalMoney::ZERO;

            if ($acc->nature === 'debit') {
                if (DecimalMoney::compare($netMovement, DecimalMoney::ZERO) >= 0) {
                    $endDebit = $netMovement;
                } else {
                    $endCredit = DecimalMoney::abs($netMovement);
                }
            } elseif ($acc->nature === 'credit') {
                $netCred = DecimalMoney::add(
                    DecimalMoney::subtract($opCredit, $opDebit),
                    DecimalMoney::subtract($arCredit, $arDebit)
                );
                if (DecimalMoney::compare($netCred, DecimalMoney::ZERO) >= 0) {
                    $endCredit = $netCred;
                } else {
                    $endDebit = DecimalMoney::abs($netCred);
                }
            } else {
                if (DecimalMoney::compare($netMovement, DecimalMoney::ZERO) >= 0) {
                    $endDebit = $netMovement;
                } else {
                    $endCredit = DecimalMoney::abs($netMovement);
                }
            }

            $accountData[$code] = [
                'code' => $acc->code,
                'name' => $acc->name,
                'is_parent' => (bool) $acc->is_parent,
                'nature' => $acc->nature,
                'opening_debit' => $opDebit,
                'opening_credit' => $opCredit,
                'arising_debit' => $arDebit,
                'arising_credit' => $arCredit,
                'ending_debit' => $endDebit,
                'ending_credit' => $endCredit,
            ];
        }

        // Step 2: Aggregate child accounts into parent accounts
        foreach ($accounts as $acc) {
            if ($acc->is_parent) {
                $pCode = $acc->code;
                $childOpD = DecimalMoney::ZERO;
                $childOpC = DecimalMoney::ZERO;
                $childArD = DecimalMoney::ZERO;
                $childArC = DecimalMoney::ZERO;
                $childEndD = DecimalMoney::ZERO;
                $childEndC = DecimalMoney::ZERO;

                foreach ($accountData as $cCode => $cData) {
                    if ($cCode !== $pCode && str_starts_with($cCode, $pCode) && ! $cData['is_parent']) {
                        $childOpD = DecimalMoney::add($childOpD, $cData['opening_debit']);
                        $childOpC = DecimalMoney::add($childOpC, $cData['opening_credit']);
                        $childArD = DecimalMoney::add($childArD, $cData['arising_debit']);
                        $childArC = DecimalMoney::add($childArC, $cData['arising_credit']);
                        $childEndD = DecimalMoney::add($childEndD, $cData['ending_debit']);
                        $childEndC = DecimalMoney::add($childEndC, $cData['ending_credit']);
                    }
                }

                // If direct posting also happened on parent code, add it
                $accountData[$pCode]['opening_debit'] = DecimalMoney::add($accountData[$pCode]['opening_debit'], $childOpD);
                $accountData[$pCode]['opening_credit'] = DecimalMoney::add($accountData[$pCode]['opening_credit'], $childOpC);
                $accountData[$pCode]['arising_debit'] = DecimalMoney::add($accountData[$pCode]['arising_debit'], $childArD);
                $accountData[$pCode]['arising_credit'] = DecimalMoney::add($accountData[$pCode]['arising_credit'], $childArC);
                $accountData[$pCode]['ending_debit'] = DecimalMoney::add($accountData[$pCode]['ending_debit'], $childEndD);
                $accountData[$pCode]['ending_credit'] = DecimalMoney::add($accountData[$pCode]['ending_credit'], $childEndC);
            }
        }

        return collect(array_values($accountData));
    }

    /** @param array<string,array{debit:string,credit:string,balance?:string}> $target */
    private function mergeConfirmedOpeningBalances(array &$target, int $companyId, ?string $cutoff): void
    {
        if (! Schema::hasTable('opening_balance_packages') || ! Schema::hasTable('opening_balance_account_lines')) {
            return;
        }
        $query = DB::table('opening_balance_account_lines as o')
            ->join('opening_balance_packages as p', 'o.package_id', '=', 'p.id')
            ->where('p.company_id', $companyId)
            ->where('p.status', 'confirmed');
        if ($cutoff !== null) {
            $query->whereDate('p.effective_date', '<=', $cutoff);
        }
        $rows = $query->select(
            'o.account_code',
            DB::raw('SUM(o.debit_amount) as total_debit'),
            DB::raw('SUM(o.credit_amount) as total_credit'),
        )->groupBy('o.account_code')->get();

        foreach ($rows as $row) {
            $code = (string) $row->account_code;
            $target[$code] ??= [
                'debit' => DecimalMoney::ZERO,
                'credit' => DecimalMoney::ZERO,
                'balance' => DecimalMoney::ZERO,
            ];
            $target[$code]['debit'] = DecimalMoney::add($target[$code]['debit'], $row->total_debit);
            $target[$code]['credit'] = DecimalMoney::add($target[$code]['credit'], $row->total_credit);
        }
    }

    /**
     * Bảng cân đối kế toán. Regime metadata is resolved from fiscal-year context.
     */
    public function getBalanceSheet(int $companyId, ?string $toDate = null, ?string $fromDate = null): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $endMap = $this->getAccountBalances($companyId, null, $toDate);
        $startMap = $fromDate ? $this->getAccountBalances($companyId, null, date('Y-m-d', strtotime($fromDate.' -1 day'))) : [];

        return [
            'assets' => [
                ['id' => 1, 'code' => '111', 'name' => 'Tiền mặt', 'end_balance' => $this->getEndingBalance($endMap, '111', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '111', 'debit')],
                ['id' => 2, 'code' => '112', 'name' => 'Tiền gửi ngân hàng', 'end_balance' => $this->getEndingBalance($endMap, '112', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '112', 'debit')],
                ['id' => 3, 'code' => '128', 'name' => 'Đầu tư tài chính ngắn hạn / Tiền gửi có kỳ hạn', 'end_balance' => $this->getEndingBalance($endMap, '128', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '128', 'debit')],
                ['id' => 4, 'code' => '131', 'name' => 'Phải thu khách hàng', 'end_balance' => $this->getEndingBalance($endMap, '131', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '131', 'debit')],
                ['id' => 5, 'code' => '133', 'name' => 'Thuế GTGT được khấu trừ', 'end_balance' => $this->getEndingBalance($endMap, '133', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '133', 'debit')],
                ['id' => 6, 'code' => '141', 'name' => 'Tạm ứng', 'end_balance' => $this->getEndingBalance($endMap, '141', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '141', 'debit')],
                ['id' => 7, 'code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'end_balance' => $this->getEndingBalance($endMap, '152', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '152', 'debit')],
                ['id' => 8, 'code' => '154', 'name' => 'Chi phí SXKD dở dang', 'end_balance' => $this->getEndingBalance($endMap, '154', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '154', 'debit')],
                ['id' => 9, 'code' => '156', 'name' => 'Hàng hóa', 'end_balance' => $this->getEndingBalance($endMap, '156', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '156', 'debit')],
                ['id' => 10, 'code' => '211', 'name' => 'Tài sản cố định hữu hình', 'end_balance' => $this->getEndingBalance($endMap, '211', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '211', 'debit')],
                ['id' => 11, 'code' => '214', 'name' => 'Hao mòn tài sản cố định', 'end_balance' => DecimalMoney::negate($this->getEndingBalance($endMap, '214', 'credit')), 'start_balance' => DecimalMoney::negate($this->getEndingBalance($startMap, '214', 'credit'))],
                ['id' => 12, 'code' => '242', 'name' => 'Chi phí trả trước', 'end_balance' => $this->getEndingBalance($endMap, '242', 'debit'), 'start_balance' => $this->getEndingBalance($startMap, '242', 'debit')],
            ],
            'liabilities' => [
                ['id' => 13, 'code' => '331', 'name' => 'Phải trả người bán', 'end_balance' => $this->getEndingBalance($endMap, '331', 'credit'), 'start_balance' => $this->getEndingBalance($startMap, '331', 'credit')],
                ['id' => 14, 'code' => '333', 'name' => 'Thuế và các khoản phải nộp NN', 'end_balance' => $this->getEndingBalance($endMap, '333', 'credit'), 'start_balance' => $this->getEndingBalance($startMap, '333', 'credit')],
                ['id' => 15, 'code' => '334', 'name' => 'Phải trả người lao động', 'end_balance' => $this->getEndingBalance($endMap, '334', 'credit'), 'start_balance' => $this->getEndingBalance($startMap, '334', 'credit')],
                ['id' => 16, 'code' => '338', 'name' => 'Phải trả, phải nộp khác / BHXH', 'end_balance' => $this->getEndingBalance($endMap, '338', 'credit'), 'start_balance' => $this->getEndingBalance($startMap, '338', 'credit')],
                ['id' => 17, 'code' => '341', 'name' => 'Vay và nợ thuê tài chính', 'end_balance' => $this->getEndingBalance($endMap, '341', 'credit'), 'start_balance' => $this->getEndingBalance($startMap, '341', 'credit')],
            ],
            'equity' => [
                ['id' => 18, 'code' => '411', 'name' => 'Vốn góp của chủ sở hữu', 'end_balance' => $this->getEndingBalance($endMap, '411', 'credit'), 'start_balance' => $this->getEndingBalance($startMap, '411', 'credit')],
                ['id' => 19, 'code' => '421', 'name' => 'Lợi nhuận sau thuế chưa phân phối', 'end_balance' => $this->getEndingBalance($endMap, '421', 'credit'), 'start_balance' => $this->getEndingBalance($startMap, '421', 'credit')],
            ],
        ];
    }

    /**
     * Báo cáo kết quả hoạt động kinh doanh. Regime metadata is fiscal-year aware.
     * Calculated based on period arising operating turnover (excluding period_closing)
     * so metrics are accurate before AND after period closing.
     */
    public function getIncomeStatement(int $companyId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $companyId = $this->requireCompanyId($companyId);
        // Operating turnover excluding closing entries
        $turnoverMap = $this->getAccountBalances($companyId, $fromDate, $toDate, true);

        // Previous period turnover if date range provided
        $prevTurnoverMap = [];
        if ($fromDate && $toDate) {
            $days = (strtotime($toDate) - strtotime($fromDate)) / (60 * 60 * 24) + 1;
            $prevTo = date('Y-m-d', strtotime($fromDate.' -1 day'));
            $prevFrom = date('Y-m-d', strtotime($prevTo." -{$days} days"));
            $prevTurnoverMap = $this->getAccountBalances($companyId, $prevFrom, $prevTo, true);
        }

        $revenue = $this->getTurnover($turnoverMap, '511', 'credit_turnover');
        $deductions = $this->getTurnover($turnoverMap, '521', 'debit_turnover');
        $net_revenue = DecimalMoney::subtract($revenue, $deductions);
        $cogs = $this->getTurnover($turnoverMap, '632', 'debit_turnover');
        $gross_profit = DecimalMoney::subtract($net_revenue, $cogs);

        $fin_revenue = $this->getTurnover($turnoverMap, '515', 'credit_turnover');
        $fin_expense = $this->getTurnover($turnoverMap, '635', 'debit_turnover');
        $sales_expense = $this->getTurnover($turnoverMap, '641', 'debit_turnover');
        $admin_expense = $this->getTurnover($turnoverMap, '642', 'debit_turnover');

        $net_profit = DecimalMoney::subtract(
            DecimalMoney::add($gross_profit, $fin_revenue),
            DecimalMoney::sum([$fin_expense, $sales_expense, $admin_expense])
        );

        $other_income = $this->getTurnover($turnoverMap, '711', 'credit_turnover');
        $other_expense = $this->getTurnover($turnoverMap, '811', 'debit_turnover');
        $other_profit = DecimalMoney::subtract($other_income, $other_expense);

        $total_profit_before_tax = DecimalMoney::add($net_profit, $other_profit);
        $cit = $this->getTurnover($turnoverMap, '821', 'debit_turnover');
        $profit_after_tax = DecimalMoney::subtract($total_profit_before_tax, $cit);

        // Prev period metrics
        $prev_rev = $this->getTurnover($prevTurnoverMap, '511', 'credit_turnover');
        $prev_ded = $this->getTurnover($prevTurnoverMap, '521', 'debit_turnover');
        $prev_net_rev = DecimalMoney::subtract($prev_rev, $prev_ded);
        $prev_cogs = $this->getTurnover($prevTurnoverMap, '632', 'debit_turnover');
        $prev_gross = DecimalMoney::subtract($prev_net_rev, $prev_cogs);
        $prev_fin_rev = $this->getTurnover($prevTurnoverMap, '515', 'credit_turnover');
        $prev_fin_exp = $this->getTurnover($prevTurnoverMap, '635', 'debit_turnover');
        $prev_sales = $this->getTurnover($prevTurnoverMap, '641', 'debit_turnover');
        $prev_admin = $this->getTurnover($prevTurnoverMap, '642', 'debit_turnover');
        $prev_net_prof = DecimalMoney::subtract(
            DecimalMoney::add($prev_gross, $prev_fin_rev),
            DecimalMoney::sum([$prev_fin_exp, $prev_sales, $prev_admin])
        );
        $prev_oth_inc = $this->getTurnover($prevTurnoverMap, '711', 'credit_turnover');
        $prev_oth_exp = $this->getTurnover($prevTurnoverMap, '811', 'debit_turnover');
        $prev_other_profit = DecimalMoney::subtract($prev_oth_inc, $prev_oth_exp);
        $prev_total_b_tax = DecimalMoney::add($prev_net_prof, $prev_other_profit);
        $prev_cit = $this->getTurnover($prevTurnoverMap, '821', 'debit_turnover');
        $prev_pat = DecimalMoney::subtract($prev_total_b_tax, $prev_cit);

        return [
            ['id' => 1, 'code' => '01', 'name' => '1. Doanh thu bán hàng và cung cấp dịch vụ', 'this_period' => $revenue, 'prev_period' => $prev_rev],
            ['id' => 2, 'code' => '02', 'name' => '2. Các khoản giảm trừ doanh thu', 'this_period' => $deductions, 'prev_period' => $prev_ded],
            ['id' => 3, 'code' => '10', 'name' => '3. Doanh thu thuần (10 = 01 - 02)', 'this_period' => $net_revenue, 'prev_period' => $prev_net_rev],
            ['id' => 4, 'code' => '11', 'name' => '4. Giá vốn hàng bán', 'this_period' => $cogs, 'prev_period' => $prev_cogs],
            ['id' => 5, 'code' => '20', 'name' => '5. Lợi nhuận gộp (20 = 10 - 11)', 'this_period' => $gross_profit, 'prev_period' => $prev_gross],
            ['id' => 6, 'code' => '21', 'name' => '6. Doanh thu hoạt động tài chính', 'this_period' => $fin_revenue, 'prev_period' => $prev_fin_rev],
            ['id' => 7, 'code' => '22', 'name' => '7. Chi phí tài chính', 'this_period' => $fin_expense, 'prev_period' => $prev_fin_exp],
            ['id' => 8, 'code' => '25', 'name' => '8. Chi phí bán hàng', 'this_period' => $sales_expense, 'prev_period' => $prev_sales],
            ['id' => 9, 'code' => '26', 'name' => '9. Chi phí QLDN', 'this_period' => $admin_expense, 'prev_period' => $prev_admin],
            ['id' => 10, 'code' => '30', 'name' => '10. Lợi nhuận thuần (30 = 20+21-22-25-26)', 'this_period' => $net_profit, 'prev_period' => $prev_net_prof],
            ['id' => 11, 'code' => '31', 'name' => '11. Thu nhập khác', 'this_period' => $other_income, 'prev_period' => $prev_oth_inc],
            ['id' => 12, 'code' => '32', 'name' => '12. Chi phí khác', 'this_period' => $other_expense, 'prev_period' => $prev_oth_exp],
            ['id' => 13, 'code' => '40', 'name' => '13. Lợi nhuận khác (40 = 31 - 32)', 'this_period' => $other_profit, 'prev_period' => $prev_other_profit],
            ['id' => 14, 'code' => '50', 'name' => '14. Tổng lợi nhuận kế toán trước thuế (50 = 30 + 40)', 'this_period' => $total_profit_before_tax, 'prev_period' => $prev_total_b_tax],
            ['id' => 15, 'code' => '51', 'name' => '15. Chi phí thuế TNDN', 'this_period' => $cit, 'prev_period' => $prev_cit],
            ['id' => 16, 'code' => '60', 'name' => '16. Lợi nhuận sau thuế (60 = 50 - 51)', 'this_period' => $profit_after_tax, 'prev_period' => $prev_pat],
        ];
    }

    public function getGeneralJournal(int $companyId, string $fromDate, string $toDate): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $query = "
            SELECT
                e.id as journal_entry_id,
                e.source_document_type,
                e.source_document_id,
                e.posting_date, e.voucher_date, e.voucher_number, e.description as reason,
                l.description, l.account_code, l.debit_amount, l.credit_amount
            FROM journal_entry_lines l
            JOIN journal_entries e ON l.journal_entry_id = e.id
            WHERE e.company_id = ? AND e.status = 'posted' AND e.deleted_at IS NULL
            AND e.posting_date BETWEEN ? AND ?
            ORDER BY e.posting_date ASC, e.id ASC
        ";

        return array_map(static function (object $line): object {
            $line->journal_entry_id = (int) $line->journal_entry_id;
            $line->source_document_id = $line->source_document_id === null
                ? null
                : (int) $line->source_document_id;
            $line->debit_amount = DecimalMoney::normalize($line->debit_amount);
            $line->credit_amount = DecimalMoney::normalize($line->credit_amount);
            // Keep the canonical line account while exposing the directional
            // aliases consumed by the General Journal table.  Without these
            // aliases the report showed amounts but blank debit/credit codes.
            $line->debit_account = DecimalMoney::compare($line->debit_amount, DecimalMoney::ZERO) > 0
                ? $line->account_code
                : null;
            $line->credit_account = DecimalMoney::compare($line->credit_amount, DecimalMoney::ZERO) > 0
                ? $line->account_code
                : null;

            return $line;
        }, DB::select($query, [$companyId, $fromDate, $toDate]));
    }

    public function getGeneralLedger(int $companyId, string $accountCode, string $fromDate, string $toDate): array
    {
        $companyId = $this->requireCompanyId($companyId);
        // 1. Fetch matching entry lines in the date range
        $matchingLines = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.company_id', $companyId)
            ->where('e.status', 'posted')
            ->whereNull('e.deleted_at')
            ->whereBetween('e.posting_date', [$fromDate, $toDate])
            ->where('l.account_code', 'like', $accountCode.'%')
            ->select(
                'l.id as line_id',
                'l.journal_entry_id',
                'e.source_document_type',
                'e.source_document_id',
                'e.posting_date',
                'e.voucher_date',
                'e.voucher_number',
                'e.description as reason',
                'l.description',
                'l.account_code',
                'l.debit_amount as debit',
                'l.credit_amount as credit'
            )
            ->orderBy('e.posting_date', 'asc')
            ->orderBy('e.id', 'asc')
            ->get();

        if ($matchingLines->isEmpty()) {
            return [];
        }

        // 2. Fetch all lines for these journal entries to find counterpart accounts (TK Đối ứng)
        $entryIds = $matchingLines->pluck('journal_entry_id')->unique()->toArray();
        $allEntryLines = DB::table('journal_entry_lines as sibling_lines')
            ->join('journal_entries as sibling_entries', 'sibling_lines.journal_entry_id', '=', 'sibling_entries.id')
            ->whereIn('sibling_lines.journal_entry_id', $entryIds)
            ->where('sibling_entries.company_id', $companyId)
            ->where('sibling_entries.status', 'posted')
            ->whereNull('sibling_entries.deleted_at')
            ->select('sibling_lines.journal_entry_id', 'sibling_lines.account_code', 'sibling_lines.debit_amount', 'sibling_lines.credit_amount')
            ->get()
            ->groupBy('journal_entry_id');

        $result = [];
        foreach ($matchingLines as $line) {
            $jeId = $line->journal_entry_id;
            $siblingLines = $allEntryLines->get($jeId, collect());

            $correspondingAccounts = [];
            if (DecimalMoney::compare($line->debit, DecimalMoney::ZERO) > 0) {
                // Corresponding is credit lines
                foreach ($siblingLines as $sib) {
                    if (DecimalMoney::compare($sib->credit_amount, DecimalMoney::ZERO) > 0 && ! in_array($sib->account_code, $correspondingAccounts)) {
                        $correspondingAccounts[] = $sib->account_code;
                    }
                }
            } else {
                // Corresponding is debit lines
                foreach ($siblingLines as $sib) {
                    if (DecimalMoney::compare($sib->debit_amount, DecimalMoney::ZERO) > 0 && ! in_array($sib->account_code, $correspondingAccounts)) {
                        $correspondingAccounts[] = $sib->account_code;
                    }
                }
            }

            $corrStr = ! empty($correspondingAccounts) ? implode(', ', $correspondingAccounts) : '';

            $result[] = [
                'posting_date' => $line->posting_date,
                'voucher_date' => $line->voucher_date,
                'voucher_number' => $line->voucher_number,
                'journal_entry_id' => (int) $line->journal_entry_id,
                'source_document_type' => $line->source_document_type,
                'source_document_id' => $line->source_document_id === null ? null : (int) $line->source_document_id,
                'reason' => $line->reason,
                'description' => $line->description ?: $line->reason,
                'account_code' => $line->account_code,
                'corresponding_account' => $corrStr,
                'debit' => DecimalMoney::normalize($line->debit),
                'credit' => DecimalMoney::normalize($line->credit),
            ];
        }

        return $result;
    }

    /**
     * Return the signed opening balance for a general-ledger account prefix.
     *
     * The detail rows intentionally remain period-only for backwards
     * compatibility; consumers can render this value as the opening row and
     * then show the period movements returned by getGeneralLedger().
     *
     * @return array{as_of_date:string,debit:string,credit:string,balance:string}
     */
    public function getGeneralLedgerOpeningBalance(int $companyId, string $accountCode, string $fromDate): array
    {
        $companyId = $this->requireCompanyId($companyId);
        try {
            $from = Carbon::createFromFormat('!Y-m-d', $fromDate);
        } catch (\Throwable) {
            $from = false;
        }
        if ($from === false || $from->format('Y-m-d') !== $fromDate) {
            throw ValidationException::withMessages([
                'from_date' => 'Ngày bắt đầu phải đúng định dạng YYYY-MM-DD.',
            ]);
        }

        $asOfDate = $from->copy()->subDay()->toDateString();
        $balanceMap = $this->getAccountBalances($companyId, null, $asOfDate);
        $debit = DecimalMoney::ZERO;
        $credit = DecimalMoney::ZERO;

        foreach ($balanceMap as $code => $amounts) {
            if (! str_starts_with((string) $code, $accountCode)) {
                continue;
            }
            $debit = DecimalMoney::add($debit, $amounts['debit']);
            $credit = DecimalMoney::add($credit, $amounts['credit']);
        }

        $balance = DecimalMoney::subtract($debit, $credit);

        return [
            'as_of_date' => $asOfDate,
            'debit' => DecimalMoney::maxZero($balance),
            'credit' => DecimalMoney::maxZero(DecimalMoney::negate($balance)),
            'balance' => $balance,
        ];
    }

    /**
     * Reports are read-only, but their company boundary is still security
     * sensitive. Controllers provide TenantContext; direct callers may be
     * workers/commands, so only an authenticated actor's tenant is enforced
     * here while explicit unauthenticated worker IDs remain supported.
     */
    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }
}
