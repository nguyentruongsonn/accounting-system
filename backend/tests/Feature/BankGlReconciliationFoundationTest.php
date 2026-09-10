<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankGlReconciliationException;
use App\Models\BankGlReconciliationRun;
use App\Models\Company;
use App\Models\User;
use App\Services\BankGlReconciliationService;
use App\Services\BankStatementReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class BankGlReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! \Schema::hasTable('bank_gl_reconciliation_runs')) {
            (require database_path('migrations/2026_08_22_161230_create_bank_gl_reconciliation_foundation.php'))->up();
            (require database_path('migrations/2026_08_22_161231_enforce_bank_gl_reconciliation_append_only.php'))->up();
        }
    }

    public function test_capture_is_append_only_same_cutoff_and_never_invents_balances(): void
    {
        [$company, $actor, $account] = $this->fixture();
        $run = app(BankGlReconciliationService::class)->capture($actor, $account->id, '2026-08-31');

        $this->assertSame('not_available', $run->status);
        $this->assertSame('2026-08-31', $run->as_of_date->toDateString());
        $this->assertNull($run->snapshot['amounts']);
        $this->assertSame($company->id, $run->company_id);
        $this->assertSame($account->id, $run->bank_account_id);
        $this->assertSame(64, strlen($run->contract_hash));
        $this->assertSame(64, strlen($run->snapshot_hash));
        $this->assertGreaterThan(0, BankGlReconciliationException::where('reconciliation_run_id', $run->id)->count());
        $this->expectException(LogicException::class);
        $run->update(['status' => 'available']);
    }

    public function test_it_records_required_owner_contract_blockers_even_when_match_lineage_is_provable(): void
    {
        [, $actor, $account] = $this->fixture();
        $line = app(BankStatementReconciliationService::class)->import($actor, 'bank-gl-foundation-import', [
            'bank_account_id' => $account->id, 'source_format' => 'csv', 'statement_reference' => 'BANK-GL-202608', 'currency_code' => 'VND',
            'lines' => [['line_reference' => 'BANK-GL-1', 'booked_on' => '2026-08-10', 'direction' => 'credit', 'amount_raw' => '125000', 'amount_scale' => 0]],
        ])['import']->lines->first();
        $receiptId = DB::table('bank_receipts')->insertGetId(['company_id' => $actor->company_id, 'bank_account_id' => $account->id, 'voucher_number' => 'BR-GL-'.uniqid(), 'voucher_date' => '2026-08-10', 'posting_date' => '2026-08-10', 'amount' => '125000.00', 'currency' => 'VND', 'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now()]);
        $proposal = app(BankStatementReconciliationService::class)->proposeMatch($actor, $line->uuid, ['candidate_type' => 'bank_receipt', 'candidate_id' => $receiptId, 'reason' => 'exact']);
        $checker = User::factory()->create(['company_id' => $actor->company_id]);
        app(BankStatementReconciliationService::class)->decideMatch($checker, $proposal->id, 'confirmed', 'checked');
        $journalId = DB::table('journal_entries')->insertGetId(['company_id' => $actor->company_id, 'fiscal_year_id' => 1, 'voucher_type' => 'bank_receipt', 'voucher_number' => 'JE-GL-'.uniqid(), 'voucher_date' => '2026-08-10', 'posting_date' => '2026-08-10', 'description' => 'lineage', 'total_amount' => 125000, 'status' => 'posted', 'source_document_type' => \App\Models\BankReceipt::class, 'source_document_id' => $receiptId, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bank_receipts')->where('id', $receiptId)->update(['journal_entry_id' => $journalId]);

        $run = app(BankGlReconciliationService::class)->capture($actor, $account->id, '2026-08-31');
        $codes = $run->exceptions->pluck('exception_code')->all();
        $this->assertSame(0, $run->source_completeness['confirmed_match_journal_lineage']['observed']['invalid_count']);
        $this->assertContains('bank_statement_coverage_not_owner_approved', $codes);
        $this->assertContains('bank_control_account_mapping_not_owner_approved', $codes);
        $this->assertContains('bank_gl_balance_exception_policy_not_owner_approved', $codes);
    }

    private function fixture(): array
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $account = BankAccount::withoutGlobalScopes()->create(['company_id' => $company->id, 'account_number' => 'BG'.uniqid(), 'bank_name' => 'Foundation Bank', 'currency' => 'VND', 'is_active' => true]);
        return [$company, $actor, $account];
    }
}
