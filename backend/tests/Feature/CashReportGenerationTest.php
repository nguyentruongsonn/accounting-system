<?php

namespace Tests\Feature;

use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\User;
use App\Services\CashReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use UnexpectedValueException;

class CashReportGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->company('Cash report tenant', '100000101');
        $this->otherCompany = $this->company('Other cash report tenant', '100000102');
        Permission::findOrCreate('cash.receipts.view', 'web');
        Permission::findOrCreate('cash.payments.view', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAsViewer($this->company);
    }

    #[DataProvider('reportCodes')]
    public function test_every_report_has_its_own_complete_contract(string $code): void
    {
        $this->seedAcceptanceFixture();

        $response = $this->report($code);

        $response->assertOk()
            ->assertJsonPath('data.report.code', $code)
            ->assertJsonPath('data.report.status', 'posted')
            ->assertJsonPath('data.report.search', '')
            ->assertJsonStructure(['data' => ['report', 'columns', 'summary', 'rows']]);

        $data = $response->json('data');
        $this->assertSame($this->expectedColumnKeys($code), array_column($data['columns'], 'key'));
        $this->assertSame($this->expectedColumnTypes($code), array_column($data['columns'], 'type'));
        foreach ($data['rows'] as $row) {
            $this->assertIsString($row['key']);
            $this->assertNotSame('', $row['key']);
            $this->assertArrayNotHasKey('search_text', $row);
        }
    }

    public static function reportCodes(): array
    {
        return [['S03a1-DNN'], ['S03a2-DNN'], ['CA-01'], ['CA-02'], ['CA-03']];
    }

    public function test_five_reducers_calculate_the_acceptance_fixture_from_persisted_vouchers(): void
    {
        $this->seedAcceptanceFixture();

        $this->report('S03a1-DNN')->assertOk()
            ->assertJsonPath('data.summary.row_count', 2)
            ->assertJsonPath('data.summary.total_receipts', 500)
            ->assertJsonPath('data.rows.0.amount', 300)
            ->assertJsonPath('data.rows.1.amount', 200);
        $this->report('S03a2-DNN')->assertOk()
            ->assertJsonPath('data.summary.row_count', 1)
            ->assertJsonPath('data.summary.total_payments', 300)
            ->assertJsonPath('data.rows.0.amount', 300);
        $this->report('CA-01')->assertOk()
            ->assertJsonPath('data.summary.opening_balance', 1000)
            ->assertJsonPath('data.summary.total_receipts', 500)
            ->assertJsonPath('data.summary.total_payments', 300)
            ->assertJsonPath('data.summary.closing_balance', 1200)
            ->assertJsonPath('data.rows.0', [
                'key' => 'CA-01:2026-08-01', 'posting_date' => '2026-08-01', 'opening_balance' => 1000,
                'total_receipts' => 500, 'total_payments' => 0, 'closing_balance' => 1500,
            ])
            ->assertJsonPath('data.rows.1', [
                'key' => 'CA-01:2026-08-02', 'posting_date' => '2026-08-02', 'opening_balance' => 1500,
                'total_receipts' => 0, 'total_payments' => 300, 'closing_balance' => 1200,
            ]);
        $this->report('CA-02')->assertOk()
            ->assertJsonPath('data.summary.total_receipts', 500)
            ->assertJsonPath('data.summary.total_payments', 300)
            ->assertJsonPath('data.summary.net_cash_flow', 200)
            ->assertJsonPath('data.rows.0', ['key' => 'CA-02:2026-08-01:receipt', 'posting_date' => '2026-08-01', 'direction' => 'receipt', 'transaction_count' => 1, 'amount' => 500])
            ->assertJsonPath('data.rows.1', ['key' => 'CA-02:2026-08-02:payment', 'posting_date' => '2026-08-02', 'direction' => 'payment', 'transaction_count' => 1, 'amount' => 300]);
        $this->report('CA-03')->assertOk()
            ->assertJsonPath('data.summary.opening_balance', 1000)
            ->assertJsonPath('data.summary.total_receipts', 500)
            ->assertJsonPath('data.summary.total_payments', 300)
            ->assertJsonPath('data.summary.closing_balance', 1200)
            ->assertJsonPath('data.rows.0.running_balance', 1300)
            ->assertJsonPath('data.rows.1.running_balance', 1500)
            ->assertJsonPath('data.rows.2.running_balance', 1200)
            ->assertJsonPath('data.rows.0.direction', 'receipt')
            ->assertJsonPath('data.rows.2.direction', 'payment');
    }

    public function test_balance_search_changes_visibility_without_changing_history_or_summaries(): void
    {
        $this->seedAcceptanceFixture();

        $this->report('CA-03', ['search' => '  PC-B  '])->assertOk()
            ->assertJsonPath('data.report.search', 'PC-B')
            ->assertJsonPath('data.summary.opening_balance', 1000)
            ->assertJsonPath('data.summary.total_receipts', 500)
            ->assertJsonPath('data.summary.total_payments', 300)
            ->assertJsonPath('data.summary.closing_balance', 1200)
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.voucher_number', 'PC-B')
            ->assertJsonPath('data.rows.0.running_balance', 1200);
        $this->report('CA-01', ['search' => 'PT-A'])->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.total_receipts', 500)
            ->assertJsonPath('data.summary.closing_balance', 1200);
    }

    public function test_reports_scope_to_the_authenticated_tenant_and_exclude_bank_or_mixed_lines(): void
    {
        $this->receipt($this->company, 'PT-CASH', '2026-08-10', [['1111', '131', 250, 'cash']]);
        $this->receipt($this->company, 'PT-BANK', '2026-08-10', [['1121', '131', 900, 'bank']]);
        $this->receipt($this->company, 'PT-MIXED', '2026-08-10', [['1111', '1121', 800, 'mixed']]);
        $this->receipt($this->otherCompany, 'PT-OTHER', '2026-08-10', [['1111', '131', 700, 'other']]);

        $this->report('S03a1-DNN')->assertOk()
            ->assertJsonPath('data.summary.row_count', 1)
            ->assertJsonPath('data.summary.total_receipts', 250)
            ->assertJsonPath('data.rows.0.voucher_number', 'PT-CASH');
    }

    public function test_journal_and_detailed_ledger_rows_expose_source_identity_for_safe_drilldown(): void
    {
        $receipt = $this->receipt($this->company, 'PT-SOURCE', '2026-08-10', [['1111', '131', 250, 'source receipt']]);
        $payment = $this->payment($this->company, 'PC-SOURCE', '2026-08-11', [['331', '1111', 150, 'source payment']]);

        $this->report('S03a1-DNN')->assertOk()
            ->assertJsonPath('data.rows.0.source_type', 'receipt')
            ->assertJsonPath('data.rows.0.source_id', $receipt->id);
        $this->report('S03a2-DNN')->assertOk()
            ->assertJsonPath('data.rows.0.source_type', 'payment')
            ->assertJsonPath('data.rows.0.source_id', $payment->id);
        $this->report('CA-03')->assertOk()
            ->assertJsonPath('data.rows.0.source_type', 'receipt')
            ->assertJsonPath('data.rows.0.source_id', $receipt->id)
            ->assertJsonPath('data.rows.1.source_type', 'payment')
            ->assertJsonPath('data.rows.1.source_id', $payment->id);
    }

    public function test_journals_filter_then_aggregate_and_honor_status_account_and_literal_search(): void
    {
        $this->receipt($this->company, 'PT%_A', '2026-08-10', [['1111', '131', 100, 'literal']]);
        $this->receipt($this->company, 'PT-1112', '2026-08-11', [['1112', '131', 200, 'other account']]);
        $this->receipt($this->company, 'PT-DRAFT', '2026-08-12', [['1111', '131', 300, 'draft']], 'draft', false);
        $this->payment($this->company, 'PC-VOID', '2026-08-13', [['331', '1111', 400, 'void']], 'voided', true);

        $this->report('S03a1-DNN', ['status' => 'all', 'cash_account' => '1111', 'search' => 'PT%_A'])->assertOk()
            ->assertJsonPath('data.summary.row_count', 1)
            ->assertJsonPath('data.summary.total_receipts', 100)
            ->assertJsonPath('data.rows.0.voucher_number', 'PT%_A');
        $this->report('S03a2-DNN', ['status' => 'all'])->assertOk()
            ->assertJsonPath('data.summary.row_count', 1)
            ->assertJsonPath('data.summary.total_payments', 400)
            ->assertJsonPath('data.rows.0.status', 'voided');
    }

    public function test_empty_period_keeps_opening_and_closing_balance_and_never_mutates_source_data(): void
    {
        $receipt = $this->receipt($this->company, 'PT-OPEN', '2026-07-31', [['1111', '131', 1000, 'opening']]);
        $lineId = $receipt->lines->first()->id;
        $before = DB::table('cash_receipt_lines')->where('id', $lineId)->value('amount');

        $this->report('CA-03')->assertOk()
            ->assertJsonCount(0, 'data.rows')
            ->assertJsonPath('data.summary.opening_balance', 1000)
            ->assertJsonPath('data.summary.closing_balance', 1000);

        $this->assertSame($before, DB::table('cash_receipt_lines')->where('id', $lineId)->value('amount'));
    }

    public function test_sum_overflow_and_history_source_failures_propagate_instead_of_returning_zero(): void
    {
        $this->receipt($this->company, 'PT-HUGE-1', '2026-07-30', [['1111', '131', 9007199254740991, 'huge']]);
        $this->receipt($this->company, 'PT-HUGE-2', '2026-07-31', [['1111', '131', 1, 'overflow']]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('safe integer VND');
        app(CashReportService::class)->generate($this->company->id, 'CA-03', $this->filters());
    }

    public function test_corrupt_history_source_data_is_propagated_instead_of_becoming_a_zero_opening_balance(): void
    {
        $receipt = $this->receipt($this->company, 'PT-CORRUPT-OPEN', '2026-07-31', [['1111', '131', 1000, 'opening']]);
        DB::table('cash_receipt_lines')->where('id', $receipt->lines->first()->id)->update(['amount' => '1000.5']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('safe integer VND');
        app(CashReportService::class)->generate($this->company->id, 'CA-03', $this->filters());
    }

    private function seedAcceptanceFixture(): void
    {
        $this->receipt($this->company, 'PT-OPEN', '2026-07-31', [['1111', '131', 1000, 'opening']]);
        $this->receipt($this->company, 'PT-A', '2026-08-01', [
            ['1111', '131', 300, 'first receipt line'], ['1111', '131', 200, 'second receipt line'],
        ]);
        $this->payment($this->company, 'PC-B', '2026-08-02', [['331', '1111', 300, 'payment']]);
        $this->receipt($this->company, 'PT-DRAFT', '2026-08-03', [['1111', '131', 8000, 'draft']], 'draft', false);
        $this->receipt($this->company, 'PT-VOID', '2026-08-03', [['1111', '131', 9000, 'void']], 'voided', true);
    }

    /** @param list<array{0:string,1:string,2:int,3:string}> $lines */
    private function receipt(Company $company, string $number, string $date, array $lines, string $status = 'posted', bool $isPosted = true): CashReceipt
    {
        $voucher = CashReceipt::create([
            'company_id' => $company->id, 'voucher_number' => $number, 'voucher_date' => $date, 'posting_date' => $date,
            'contact_name' => 'Receipt contact', 'reason' => $number . ' reason', 'total_amount' => array_sum(array_column($lines, 2)),
            'status' => $status, 'is_posted' => $isPosted,
        ]);
        foreach ($lines as [$debit, $credit, $amount, $description]) {
            $voucher->lines()->create([
                'debit_account' => $debit,
                'credit_account' => $credit,
                'amount' => $amount,
                'description' => $description,
            ]);
        }

        return $voucher->load('lines');
    }

    /** @param list<array{0:string,1:string,2:int,3:string}> $lines */
    private function payment(Company $company, string $number, string $date, array $lines, string $status = 'posted', bool $isPosted = true): CashPayment
    {
        $voucher = CashPayment::create([
            'company_id' => $company->id, 'voucher_number' => $number, 'voucher_date' => $date, 'posting_date' => $date,
            'contact_name' => 'Payment contact', 'receiver_name' => 'Payment contact', 'reason' => $number . ' reason',
            'total_amount' => array_sum(array_column($lines, 2)), 'status' => $status, 'is_posted' => $isPosted,
        ]);
        foreach ($lines as [$debit, $credit, $amount, $description]) {
            $voucher->lines()->create([
                'debit_account' => $debit,
                'credit_account' => $credit,
                'amount' => $amount,
                'description' => $description,
            ]);
        }

        return $voucher->load('lines');
    }

    /** @param array<string, string|null> $overrides */
    private function report(string $code, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/cash/reports/' . $code . '?' . http_build_query(array_filter(array_replace($this->filters(), $overrides), static fn ($value): bool => $value !== null)));
    }

    /** @return array<string, string|null> */
    private function filters(): array
    {
        return ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'status' => 'posted', 'search' => '', 'cash_account' => null];
    }

    private function actingAsViewer(Company $company): void
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo(['cash.receipts.view', 'cash.payments.view']);
        Sanctum::actingAs($user);
    }

    private function company(string $name, string $taxCode): Company
    {
        return Company::create(['name' => $name, 'tax_code' => $taxCode, 'address' => 'Test address']);
    }

    /** @return list<string> */
    private function expectedColumnKeys(string $code): array
    {
        return match ($code) {
            'S03a1-DNN', 'S03a2-DNN' => ['posting_date', 'voucher_date', 'voucher_number', 'contact_name', 'description', 'cash_account', 'counterpart_account', 'amount', 'status'],
            'CA-01' => ['posting_date', 'opening_balance', 'total_receipts', 'total_payments', 'closing_balance'],
            'CA-02' => ['posting_date', 'direction', 'transaction_count', 'amount'],
            'CA-03' => ['posting_date', 'voucher_date', 'voucher_number', 'direction', 'contact_name', 'description', 'cash_account', 'counterpart_account', 'receipt_amount', 'payment_amount', 'running_balance'],
        };
    }

    /** @return list<string> */
    private function expectedColumnTypes(string $code): array
    {
        return match ($code) {
            'S03a1-DNN', 'S03a2-DNN' => ['date', 'date', 'text', 'text', 'text', 'text', 'text', 'money', 'status'],
            'CA-01' => ['date', 'money', 'money', 'money', 'money'],
            'CA-02' => ['date', 'text', 'number', 'money'],
            'CA-03' => ['date', 'date', 'text', 'text', 'text', 'text', 'text', 'text', 'money', 'money', 'money'],
        };
    }
}
