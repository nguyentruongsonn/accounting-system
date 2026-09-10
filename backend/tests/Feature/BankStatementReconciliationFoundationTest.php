<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankReconciliationExceptionEvent;
use App\Models\BankStatementImport;
use App\Models\Company;
use App\Models\User;
use App\Services\BankStatementReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class BankStatementReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalized_import_is_tenant_bound_idempotent_and_append_only(): void
    {
        [$company, $actor, $account] = $this->fixture();
        $service = app(BankStatementReconciliationService::class);
        $first = $service->import($actor, 'bank-import-001', $this->payload($account->id));
        $this->assertFalse($first['replayed']);
        $this->assertSame(2, $first['import']->line_count);
        $this->assertDatabaseCount('bank_statement_lines', 2);
        $replay = $service->import($actor, 'bank-import-001', $this->payload($account->id));
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['import']->id, $replay['import']->id);

        $this->expectException(LogicException::class);
        $first['import']->update(['status' => 'rejected']);
    }

    public function test_idempotency_conflict_and_cross_tenant_account_are_rejected(): void
    {
        [, $actor, $account] = $this->fixture();
        $service = app(BankStatementReconciliationService::class);
        $service->import($actor, 'bank-import-conflict', $this->payload($account->id));
        $changed = $this->payload($account->id); $changed['statement_reference'] = 'STMT-CHANGED';
        try { $service->import($actor, 'bank-import-conflict', $changed); $this->fail('Expected conflict.'); } catch (ConflictHttpException) { $this->assertDatabaseCount('bank_statement_imports', 1); }

        $other = Company::create(['name' => 'Bank Tenant B', 'tax_code' => 'BANK-B', 'address' => 'B']);
        $otherActor = User::factory()->create(['company_id' => $other->id]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->import($otherActor, 'bank-import-foreign', $this->payload($account->id));
    }

    public function test_match_requires_exact_posted_voucher_and_independent_confirmation(): void
    {
        [$company, $maker, $account] = $this->fixture();
        $import = app(BankStatementReconciliationService::class)->import($maker, 'bank-import-match', $this->payload($account->id));
        $line = $import['import']->lines->firstWhere('direction', 'credit');
        $receiptId = DB::table('bank_receipts')->insertGetId(['company_id' => $company->id, 'bank_account_id' => $account->id, 'voucher_number' => 'BR-'.uniqid(), 'voucher_date' => '2026-08-10', 'posting_date' => '2026-08-10', 'amount' => '125000.00', 'currency' => 'VND', 'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now()]);
        $service = app(BankStatementReconciliationService::class);
        $proposal = $service->proposeMatch($maker, $line->uuid, ['candidate_type' => 'bank_receipt', 'candidate_id' => $receiptId, 'reason' => 'Đúng số tiền và chiều giao dịch']);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $confirmed = $service->decideMatch($checker, $proposal->id, 'confirmed', 'Đã đối chiếu chứng từ ngân hàng');
        $this->assertSame('confirmed', $confirmed->decision);
        $this->assertDatabaseCount('bank_reconciliation_match_events', 2);
        $this->assertDatabaseHas('bank_receipts', ['id' => $receiptId, 'is_posted' => 1]);
    }

    public function test_exception_lifecycle_requires_second_actor_and_is_event_sourced(): void
    {
        [, $maker, $account] = $this->fixture();
        $line = app(BankStatementReconciliationService::class)->import($maker, 'bank-import-exception', $this->payload($account->id))['import']->lines->first();
        $service = app(BankStatementReconciliationService::class);
        $opened = $service->recordException($maker, 'bank-line-'.$line->uuid, 'opened', ['line_uuid' => $line->uuid, 'exception_code' => 'UNMATCHED_BANK_LINE', 'severity' => 'blocking', 'reason' => 'Chưa tìm thấy chứng từ', 'evidence' => ['line_uuid' => $line->uuid]]);
        $this->assertSame('opened', $opened->event_type);
        $checker = User::factory()->create(['company_id' => $maker->company_id]);
        $resolved = $service->recordException($checker, $opened->exception_key, 'resolved', ['exception_code' => 'UNMATCHED_BANK_LINE', 'severity' => 'blocking', 'reason' => 'Đã bổ sung chứng từ']);
        $this->assertSame('resolved', $resolved->event_type);
        $this->assertSame(2, BankReconciliationExceptionEvent::count());
    }

    private function fixture(): array
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $account = BankAccount::withoutGlobalScopes()->create(['company_id' => $company->id, 'account_number' => '9704'.uniqid(), 'bank_name' => 'Test Bank', 'currency' => 'VND', 'is_active' => true]);
        return [$company, $actor, $account];
    }

    private function payload(int $accountId): array
    {
        return ['bank_account_id' => $accountId, 'source_format' => 'csv', 'statement_reference' => 'STMT-202608', 'currency_code' => 'VND', 'lines' => [
            ['line_reference' => 'BANK-001', 'booked_on' => '2026-08-10', 'direction' => 'credit', 'amount_raw' => '125000', 'amount_scale' => 0, 'bank_reference' => 'REF-001'],
            ['line_reference' => 'BANK-002', 'booked_on' => '2026-08-11', 'direction' => 'debit', 'amount_raw' => '50000', 'amount_scale' => 0, 'bank_reference' => 'REF-002'],
        ]];
    }
}
