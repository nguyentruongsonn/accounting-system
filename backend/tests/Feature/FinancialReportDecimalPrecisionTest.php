<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialReportDecimalPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fiscalYear;

    private FinancialReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Decimal report company']);
        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2026',
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        foreach ([
            ['111', 'Cash', 'asset', 'debit', true],
            ['1111', 'Cash VND', 'asset', 'debit', false],
            ['131', 'Receivables', 'asset', 'debit', false],
            ['156', 'Inventory', 'asset', 'debit', false],
            ['411', 'Equity', 'equity', 'credit', false],
            ['511', 'Revenue', 'revenue', 'credit', false],
            ['521', 'Revenue deductions', 'revenue_deduction', 'debit', false],
            ['632', 'Cost of sales', 'expense', 'debit', false],
        ] as [$code, $name, $type, $nature, $isParent]) {
            ChartOfAccount::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'level' => strlen($code) === 3 ? 1 : 2,
                'is_parent' => $isParent,
                'is_active' => true,
            ]);
        }

        $this->insertPostedEntry('OPEN-001', '2026-01-15', [
            ['1111', '0.10', '0.00'],
            ['411', '0.00', '0.10'],
        ]);
        $this->insertPostedEntry('MOVE-001', '2026-02-01', [
            ['1111', '0.20', '0.00'],
            ['411', '0.00', '0.20'],
        ]);
        $this->insertPostedEntry('SALE-001', '2026-02-02', [
            ['131', '100.20', '0.00'],
            ['511', '0.00', '100.20'],
        ]);
        $this->insertPostedEntry('DEDUCT-001', '2026-02-03', [
            ['521', '0.10', '0.00'],
            ['131', '0.00', '0.10'],
        ]);
        $this->insertPostedEntry('COGS-001', '2026-02-04', [
            ['632', '40.10', '0.00'],
            ['156', '0.00', '40.10'],
        ]);

        $this->reports = app(FinancialReportService::class);
    }

    public function test_trial_balance_and_parent_rollup_keep_cents_as_decimal_strings(): void
    {
        $trial = $this->reports->getTrialBalance($this->company->id, '2026-02-01', '2026-02-28')->keyBy('code');

        foreach (['111', '1111'] as $code) {
            $this->assertSame('0.10', $trial[$code]['opening_debit']);
            $this->assertSame('0.20', $trial[$code]['arising_debit']);
            $this->assertSame('0.30', $trial[$code]['ending_debit']);
            $this->assertIsString($trial[$code]['ending_debit']);
        }
    }

    public function test_general_journal_and_ledger_emit_canonical_decimal_strings(): void
    {
        $journal = $this->reports->getGeneralJournal($this->company->id, '2026-02-01', '2026-02-28');
        $movement = collect($journal)->firstWhere('voucher_number', 'MOVE-001');
        $this->assertSame('0.20', $movement->debit_amount);
        $this->assertSame('0.00', $movement->credit_amount);

        $ledger = $this->reports->getGeneralLedger($this->company->id, '111', '2026-02-01', '2026-02-28');
        $this->assertSame('0.20', $ledger[0]['debit']);
        $this->assertSame('0.00', $ledger[0]['credit']);
        $this->assertSame('411', $ledger[0]['corresponding_account']);
    }

    public function test_general_ledger_exposes_net_opening_balance_for_selected_period(): void
    {
        $opening = $this->reports->getGeneralLedgerOpeningBalance(
            $this->company->id,
            '111',
            '2026-02-01',
        );

        $this->assertSame('0.10', $opening['debit']);
        $this->assertSame('0.00', $opening['credit']);
        $this->assertSame('0.10', $opening['balance']);
        $this->assertSame('2026-01-31', $opening['as_of_date']);
    }

    public function test_balance_and_income_calculations_keep_exact_cent_formulas(): void
    {
        $balanceSheet = $this->reports->getBalanceSheet($this->company->id, '2026-02-28');
        $cash = collect($balanceSheet['assets'])->firstWhere('code', '111');
        $this->assertSame('0.30', $cash['end_balance']);

        $income = collect($this->reports->getIncomeStatement(
            $this->company->id,
            '2026-02-01',
            '2026-02-28'
        ))->keyBy('code');
        $this->assertSame('100.20', $income['01']['this_period']);
        $this->assertSame('0.10', $income['02']['this_period']);
        $this->assertSame('100.10', $income['10']['this_period']);
        $this->assertSame('60.00', $income['20']['this_period']);
        $this->assertSame('60.00', $income['60']['this_period']);
    }

    public function test_prefix_balances_and_turnover_support_maximum_decimal_18_2_values(): void
    {
        $map = [
            '5111' => ['debit' => '0.01', 'credit' => '9999999999999999.99'],
            '5112' => ['debit' => '0.00', 'credit' => '0.01'],
        ];

        $this->assertSame('9999999999999999.99', $this->reports->getTurnover($map, '511'));
        $this->assertSame('-9999999999999999.99', $this->reports->getEndingBalance($map, '511', 'net'));
    }

    public function test_prefix_balances_and_turnover_preserve_signed_opposite_side_values(): void
    {
        $map = [
            '1311' => ['debit' => '0.00', 'credit' => '125.50'],
            '5111' => ['debit' => '80.25', 'credit' => '0.00'],
            '6321' => ['debit' => '0.00', 'credit' => '14.75'],
        ];

        // A debit-nature account with a credit balance and a credit-nature
        // account with a debit balance are real opposite-side positions, not
        // zero. Reports must preserve their sign for reconciliation.
        $this->assertSame('-125.50', $this->reports->getEndingBalance($map, '131', 'debit'));
        $this->assertSame('-80.25', $this->reports->getTurnover($map, '511', 'credit_turnover'));
        $this->assertSame('-14.75', $this->reports->getTurnover($map, '632', 'debit_turnover'));
    }

    /** @param list<array{string, string, string}> $lines */
    private function insertPostedEntry(string $voucherNumber, string $date, array $lines): void
    {
        $entryId = DB::table('journal_entries')->insertGetId([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => $date,
            'posting_date' => $date,
            'description' => $voucherNumber,
            'total_amount' => $lines[0][1] !== '0.00' ? $lines[0][1] : $lines[0][2],
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as [$accountCode, $debit, $credit]) {
            DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $entryId,
                'account_code' => $accountCode,
                'description' => $voucherNumber,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
