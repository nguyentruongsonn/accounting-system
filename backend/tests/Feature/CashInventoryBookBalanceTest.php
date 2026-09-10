<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashInventoryBookBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::findOrFail(1);
        $this->fiscalYear = FiscalYear::firstOrCreate([
            'company_id' => $this->company->id,
            'year' => 2026,
        ], [
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
        ChartOfAccount::create([
            'company_id' => $this->company->id,
            'code' => '1111',
            'name' => 'Cash VND (test)',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 2,
            'is_parent' => false,
            'is_active' => true,
        ]);

        $this->actingAsCompany();
    }

    public function test_book_balance_is_derived_from_posted_journal_lines(): void
    {
        $this->postedEntry('CASH-IN', '2026-01-10', [['1111', 1250000, 0], ['4111', 0, 1250000]]);
        $this->postedEntry('CASH-OUT', '2026-01-12', [['6421', 250000, 0], ['1111', 0, 250000]]);

        $this->getJson('/api/v1/cash/book-balance?account_code=1111&as_of_date=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.account_code', '1111')
            ->assertJsonPath('data.balance', '1000000')
            ->assertJsonPath('data.line_count', 2)
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.certifying', false);
    }

    public function test_empty_posted_evidence_is_unavailable_not_zero(): void
    {
        $this->getJson('/api/v1/cash/book-balance?account_code=1111&as_of_date=2026-01-31')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CASH_BOOK_BALANCE_UNAVAILABLE')
            ->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.line_count', 0);
    }

    public function test_parent_or_inactive_account_is_rejected(): void
    {
        ChartOfAccount::create([
            'company_id' => $this->company->id,
            'code' => '111',
            'name' => 'Cash parent (test)',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => true,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/cash/book-balance?account_code=111&as_of_date=2026-01-31')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CASH_BOOK_ACCOUNT_UNAVAILABLE');
    }

    private function actingAsCompany(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->grantGlReportPermissions($user);
        Sanctum::actingAs($user);
    }

    private function postedEntry(string $number, string $date, array $lines): void
    {
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $number,
            'voucher_date' => $date,
            'posting_date' => $date,
            'description' => 'Cash balance test',
            'total_amount' => collect($lines)->sum(fn (array $line): int => (int) $line[1]),
            'status' => 'posted',
        ]);

        foreach ($lines as [$account, $debit, $credit]) {
            $entry->lines()->create([
                'account_code' => $account,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
            ]);
        }
    }
}
