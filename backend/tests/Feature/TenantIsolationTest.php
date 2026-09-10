<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private FiscalYear $fiscalYearA;

    private FiscalYear $fiscalYearB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create([
            'name' => 'Tenant A',
            'tax_code' => 'TENANT-A',
        ]);
        $this->companyB = Company::create([
            'name' => 'Tenant B',
            'tax_code' => 'TENANT-B',
        ]);
        $this->userA = User::factory()->create(['company_id' => $this->companyA->id]);
        $this->grantGlReportPermissions($this->userA);
        Sanctum::actingAs($this->userA);

        $this->fiscalYearA = $this->createFiscalYear($this->companyA, 2026);
        $this->fiscalYearB = $this->createFiscalYear($this->companyB, 2026);

        $this->createAccount($this->companyA, '1111', 'Cash A', 'asset', 'debit');
        $this->createAccount($this->companyA, '511', 'Revenue A', 'revenue', 'credit');
        $this->createAccount($this->companyB, '1111', 'Cash B', 'asset', 'debit');
        $this->createAccount($this->companyB, '511', 'Revenue B', 'revenue', 'credit');
    }

    public function test_financial_reports_ignore_client_company_and_use_authenticated_company(): void
    {
        $reports = $this->mock(FinancialReportService::class);
        $reports->shouldReceive('getBalanceSheet')
            ->once()->with($this->companyA->id, '2026-08-31', '2026-08-01')->andReturn([]);
        $reports->shouldReceive('getIncomeStatement')
            ->once()->with($this->companyA->id, '2026-08-01', '2026-08-31')->andReturn([]);
        $reports->shouldReceive('getTrialBalance')
            ->once()->with($this->companyA->id, '2026-08-01', '2026-08-31')->andReturn(collect());
        $reports->shouldReceive('getGeneralJournal')
            ->once()->with($this->companyA->id, '2026-08-01', '2026-08-31')->andReturn([]);
        $reports->shouldReceive('getGeneralLedger')
            ->once()->with($this->companyA->id, '1111', '2026-08-01', '2026-08-31')->andReturn([]);
        $reports->shouldReceive('getGeneralLedgerOpeningBalance')
            ->once()->with($this->companyA->id, '1111', '2026-08-01')->andReturn([
                'as_of_date' => '2026-07-31',
                'debit' => '0.00',
                'credit' => '0.00',
                'balance' => '0.00',
            ]);

        $query = http_build_query([
            'company_id' => $this->companyB->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
        ]);

        $this->getJson("/api/v1/reports/balance-sheet?{$query}")->assertOk();
        $this->getJson("/api/v1/reports/income-statement?{$query}")->assertOk();
        $this->getJson("/api/v1/reports/trial-balance?{$query}")->assertOk();
        $this->getJson("/api/v1/reports/general-journal?{$query}")
            ->assertOk()
            ->assertJsonPath('meta.company_id', $this->companyA->id);
        $this->getJson("/api/v1/reports/general-ledger?{$query}&account_code=1111")
            ->assertOk()
            ->assertJsonPath('meta.company_id', $this->companyA->id);
    }

    public function test_user_without_company_cannot_use_tenant_endpoints(): void
    {
        $unassignedUser = User::factory()->create(['company_id' => null]);
        $this->grantGlReportPermissions($unassignedUser);
        Sanctum::actingAs($unassignedUser);

        $this->mock(FinancialReportService::class)
            ->shouldNotReceive('getBalanceSheet');

        $this->getJson("/api/v1/reports/balance-sheet?company_id={$this->companyB->id}")
            ->assertForbidden();
        $this->getJson("/api/v1/gl/journal-entries?company_id={$this->companyB->id}")
            ->assertForbidden();
        $this->postJson('/api/v1/gl/journal-entries', $this->validJournalPayload())
            ->assertForbidden();
    }

    public function test_journal_list_and_next_code_cannot_be_switched_to_another_company(): void
    {
        $year = now()->format('Y');
        $this->createJournalEntry($this->companyA, $this->fiscalYearA, "PKT-{$year}-0003");
        $this->createJournalEntry($this->companyB, $this->fiscalYearB, "PKT-{$year}-0099");

        $this->getJson("/api/v1/gl/journal-entries?company_id={$this->companyB->id}")
            ->assertOk()
            ->assertJsonPath('data.0.company_id', $this->companyA->id)
            ->assertJsonMissing(['voucher_number' => "PKT-{$year}-0099"]);

        $this->getJson("/api/v1/gl/journal-entries/next-code?company_id={$this->companyB->id}")
            ->assertOk()
            ->assertJsonPath('data', "PKT-{$year}-0004");
    }

    public function test_journal_create_overwrites_client_company_with_authenticated_company(): void
    {
        $payload = $this->validJournalPayload();
        $payload['company_id'] = $this->companyB->id;
        $payload['fiscal_year_id'] = $this->fiscalYearA->id;

        $this->postJson('/api/v1/gl/journal-entries', $payload)
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->companyA->id);

        $this->assertDatabaseHas('journal_entries', [
            'company_id' => $this->companyA->id,
            'voucher_number' => 'TENANT-TEST-001',
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $this->companyB->id,
            'voucher_number' => 'TENANT-TEST-001',
        ]);
    }

    public function test_journal_create_rejects_foreign_fiscal_year_and_bank_account(): void
    {
        $foreignBank = BankAccount::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'account_number' => 'B-001',
            'bank_name' => 'Tenant B Bank',
        ]);

        $payload = $this->validJournalPayload();
        $payload['fiscal_year_id'] = $this->fiscalYearB->id;
        $payload['lines'][0]['bank_account_id'] = $foreignBank->id;

        $this->postJson('/api/v1/gl/journal-entries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_year_id', 'lines.0.bank_account_id']);

        $this->assertDatabaseMissing('journal_entries', [
            'voucher_number' => 'TENANT-TEST-001',
        ]);
    }

    private function validJournalPayload(): array
    {
        return [
            'voucher_number' => 'TENANT-TEST-001',
            'voucher_type' => 'general_journal',
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'description' => 'Tenant isolation test',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => 1000, 'credit_amount' => 0],
                ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 1000],
            ],
        ];
    }

    private function createFiscalYear(Company $company, int $year): FiscalYear
    {
        return FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'year' => $year,
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
            'status' => 'open',
        ]);
    }

    private function createAccount(
        Company $company,
        string $code,
        string $name,
        string $type,
        string $nature
    ): void {
        ChartOfAccount::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'nature' => $nature,
            'level' => 1,
            'is_parent' => false,
        ]);
    }

    private function createJournalEntry(
        Company $company,
        FiscalYear $fiscalYear,
        string $voucherNumber
    ): JournalEntry {
        return JournalEntry::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'description' => 'Tenant isolation fixture',
            'total_amount' => 1000,
            'status' => 'draft',
        ]);
    }
}
