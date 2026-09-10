<?php

namespace Tests\Feature;

use App\Exports\GeneralJournalExport;
use App\Exports\GeneralLedgerExport;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GeneralReportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FiscalYear $fiscalYear;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user = User::factory()->create(['company_id' => 1]);
        $this->user->givePermissionTo('reports.view');
        Sanctum::actingAs($this->user);
        $this->fiscalYear = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $this->user->company_id)
            ->firstOrFail();
    }

    public function test_general_journal_export_uses_the_same_authenticated_report_context(): void
    {
        Excel::fake();

        $response = $this->get('/api/v1/reports/general-journal?'.http_build_query([
            'fiscal_year_id' => $this->fiscalYear->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'export' => 'excel',
        ]));

        $response->assertOk();
        Excel::assertDownloaded('general_journal.xlsx', static fn (mixed $export): bool => $export instanceof GeneralJournalExport);
    }

    public function test_general_journal_pdf_export_returns_a_report_artifact(): void
    {
        $response = $this->get('/api/v1/reports/general-journal?'.http_build_query([
            'fiscal_year_id' => $this->fiscalYear->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'export' => 'pdf',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', strtolower((string) $response->headers->get('content-type')));
        $this->assertStringContainsString('general_journal.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_general_ledger_pdf_export_returns_a_report_artifact(): void
    {
        $response = $this->get('/api/v1/reports/general-ledger?'.http_build_query([
            'account_code' => '111',
            'fiscal_year_id' => $this->fiscalYear->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'export' => 'pdf',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', strtolower((string) $response->headers->get('content-type')));
        $this->assertStringContainsString('general_ledger.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_general_ledger_export_keeps_opening_balance_and_period_rows_in_the_workbook_contract(): void
    {
        $export = new GeneralLedgerExport(
            [
                [
                    'posting_date' => '2026-01-05',
                    'voucher_date' => '2026-01-04',
                    'voucher_number' => 'PT-001',
                    'description' => 'Thu tiền khách hàng',
                    'account_code' => '111',
                    'corresponding_account' => '131',
                    'debit' => '100000.00',
                    'credit' => '0.00',
                ],
            ],
            [
                'as_of_date' => '2025-12-31',
                'debit' => '250.00',
                'credit' => '0.00',
                'balance' => '250.00',
            ],
            '111',
        );

        $this->assertSame(
            [
                ['Số dư đầu kỳ đến 2025-12-31', '', '', '250.00', '0.00', '', '', ''],
                ['2026-01-05', 'PT-001', 'Thu tiền khách hàng', '131', '100000.00', '0.00', '2026-01-04', '111'],
            ],
            $export->array(),
        );
        $this->assertSame(
            ['SỔ CÁI', '', '', '', '', '', '', ''],
            $export->headings()[0],
        );
    }

    public function test_general_reports_expose_tenant_scoped_source_identity_for_drilldown(): void
    {
        $entryId = DB::table('journal_entries')->insertGetId([
            'company_id' => $this->user->company_id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'cash_receipt',
            'voucher_number' => 'PT-DRILL-001',
            'voucher_date' => '2026-01-05',
            'posting_date' => '2026-01-05',
            'description' => 'Thu tiền để truy vết',
            'total_amount' => '100.00',
            'status' => 'posted',
            'source_document_type' => \App\Models\CashReceipt::class,
            'source_document_id' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_entry_lines')->insert([
            'journal_entry_id' => $entryId,
            'account_code' => '111',
            'description' => 'Thu tiền để truy vết',
            'debit_amount' => '100.00',
            'credit_amount' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $journal = $this->getJson('/api/v1/reports/general-journal?'.http_build_query([
            'fiscal_year_id' => $this->fiscalYear->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
        ]))->assertOk()->json('data');
        $journalRow = collect($journal)->firstWhere('voucher_number', 'PT-DRILL-001');

        $this->assertSame($entryId, $journalRow['journal_entry_id']);
        $this->assertSame(\App\Models\CashReceipt::class, $journalRow['source_document_type']);
        $this->assertSame(7, (int) $journalRow['source_document_id']);

        $ledger = $this->getJson('/api/v1/reports/general-ledger?'.http_build_query([
            'account_code' => '111',
            'fiscal_year_id' => $this->fiscalYear->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
        ]))->assertOk()->json('data');
        $ledgerRow = collect($ledger)->firstWhere('voucher_number', 'PT-DRILL-001');

        $this->assertSame($entryId, $ledgerRow['journal_entry_id']);
        $this->assertSame(\App\Models\CashReceipt::class, $ledgerRow['source_document_type']);
        $this->assertSame(7, (int) $ledgerRow['source_document_id']);
    }
}
