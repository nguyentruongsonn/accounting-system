<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\PeriodClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeriodClosingExactDecimalTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_keeps_decimal_contracts_as_exact_strings_before_close_gate_approval(): void
    {
        $company = Company::create([
            'name' => 'Exact Decimal Closing Co.',
            'tax_code' => '0101234567',
            'address' => 'Ha Noi',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);

        FiscalYear::create([
            'company_id' => $company->id,
            'name' => 'FY 2026',
            'code' => 'FY2026-EXACT',
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_closed' => false,
        ]);

        foreach ([
            ['131', 'Phải thu', 'asset', 'amphibious'],
            ['1561', 'Hàng hóa', 'asset', 'debit'],
            ['5111', 'Doanh thu', 'revenue', 'credit'],
            ['632', 'Giá vốn', 'expense', 'debit'],
            ['911', 'Xác định kết quả', 'equity', 'amphibious'],
            ['4212', 'LN chưa phân phối', 'equity', 'amphibious'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $journal = app(JournalEntryService::class);
        $journal->create([
            'company_id' => $company->id,
            'voucher_number' => 'EXACT-REV',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'status' => 'posted',
            'lines' => [['debit_account' => '131', 'credit_account' => '5111', 'amount' => '0.10']],
        ]);
        $journal->create([
            'company_id' => $company->id,
            'voucher_number' => 'EXACT-REV-2',
            'voucher_date' => '2026-08-11',
            'posting_date' => '2026-08-11',
            'status' => 'posted',
            'lines' => [['debit_account' => '131', 'credit_account' => '5111', 'amount' => '0.20']],
        ]);
        $journal->create([
            'company_id' => $company->id,
            'voucher_number' => 'EXACT-EXP',
            'voucher_date' => '2026-08-12',
            'posting_date' => '2026-08-12',
            'status' => 'posted',
            'lines' => [['debit_account' => '632', 'credit_account' => '1561', 'amount' => '0.01']],
        ]);

        $closing = app(PeriodClosingService::class);
        $preview = $closing->preview($company->id, '2026-08-01', '2026-08-31');

        $this->assertSame('0.30', $preview['total_revenue']);
        $this->assertSame('0.01', $preview['total_expenses']);
        $this->assertSame('0.29', $preview['net_profit']);

        $retainedEarnings = collect($preview['suggested_lines'])->first(
            fn (array $line): bool => $line['debit_account'] === '911' && $line['credit_account'] === '4212',
        );
        $this->assertNotNull($retainedEarnings);
        $this->assertSame('0.29', $retainedEarnings['amount']);

        // Posting/period lock is deliberately asserted in
        // PeriodClosingReadinessGateTest.  A monetary calculation test must
        // not bypass that mandatory reconciliation/approval boundary.
    }
}
