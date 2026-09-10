<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\User;
use App\Services\AuditService;
use App\Services\JournalEntryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AccountingControlWaveTwoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fiscalYear;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::findOrFail(1);
        $this->fiscalYear = FiscalYear::where('company_id', $this->company->id)->firstOrFail();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->grantGlReportPermissions($this->user);
        Sanctum::actingAs($this->user);

        foreach ([
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '511', 'name' => 'Doanh thu', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '911', 'name' => 'Xác định kết quả', 'type' => 'equity', 'nature' => 'amphibious'],
            ['code' => '4212', 'name' => 'Lợi nhuận năm nay', 'type' => 'equity', 'nature' => 'amphibious'],
        ] as $account) {
            ChartOfAccount::updateOrCreate(
                ['company_id' => $this->company->id, 'code' => $account['code']],
                [...$account, 'level' => 1, 'is_parent' => false, 'is_active' => true]
            );
        }
    }

    public function test_direct_api_cannot_create_a_posted_journal_entry(): void
    {
        $payload = $this->journalPayload('DIRECT-POSTED');
        $payload['status'] = 'posted';

        $this->postJson('/api/v1/gl/journal-entries', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'DIRECT-POSTED']);
    }

    public function test_reverse_creates_opposite_posted_entry_two_way_links_and_audit(): void
    {
        $entry = $this->createAndPost('REVERSE-001', 725000);
        $originalLines = $entry->lines->map->only(['account_code', 'debit_amount', 'credit_amount'])->values()->all();

        $response = $this->postJson("/api/v1/gl/journal-entries/{$entry->id}/reverse", [
            'reason' => 'Điều chỉnh chứng từ ghi nhận sai nghiệp vụ',
            'posting_date' => '2026-08-20',
        ])->assertCreated();

        $original = JournalEntry::with('lines')->findOrFail($entry->id);
        $reversal = JournalEntry::with('lines')->findOrFail($response->json('data.id'));

        $this->assertSame('posted', $original->status);
        $this->assertSame($originalLines, $original->lines->map->only(['account_code', 'debit_amount', 'credit_amount'])->values()->all());
        $this->assertSame($reversal->id, $original->reversed_by_entry_id);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('posted', $reversal->status);
        $this->assertSame($this->user->id, $reversal->reversed_by);
        $this->assertNotNull($reversal->reversed_at);
        $this->assertSame(725000.0, (float) $reversal->lines->sum('debit_amount'));
        $this->assertSame(725000.0, (float) $reversal->lines->sum('credit_amount'));

        foreach ($original->lines as $line) {
            $opposite = $reversal->lines->firstWhere('account_code', $line->account_code);
            $this->assertNotNull($opposite);
            $this->assertSame((float) $line->debit_amount, (float) $opposite->credit_amount);
            $this->assertSame((float) $line->credit_amount, (float) $opposite->debit_amount);
        }

        $this->assertDatabaseHas('audit_logs', ['model_id' => $original->id, 'action' => 'journal.created']);
        $this->assertDatabaseHas('audit_logs', ['model_id' => $original->id, 'action' => 'journal.posted']);
        $this->assertDatabaseHas('audit_logs', ['model_id' => $original->id, 'action' => 'journal.reversed']);
    }

    public function test_second_reversal_is_rejected(): void
    {
        $entry = $this->createAndPost('REVERSE-ONCE');
        $payload = ['reason' => 'Đảo một lần duy nhất', 'posting_date' => '2026-08-20'];

        $this->postJson("/api/v1/gl/journal-entries/{$entry->id}/reverse", $payload)->assertCreated();
        $this->postJson("/api/v1/gl/journal-entries/{$entry->id}/reverse", $payload)->assertStatus(409);

        $this->assertSame(1, JournalEntry::where('reversal_of_id', $entry->id)->count());
    }

    public function test_reversal_cannot_cross_tenant_boundary(): void
    {
        $otherCompany = Company::create(['name' => 'Other Company', 'tax_code' => 'OTHER-001']);
        $otherYear = FiscalYear::create([
            'company_id' => $otherCompany->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $foreignEntry = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $otherCompany->id,
            'fiscal_year_id' => $otherYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'FOREIGN-001',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Foreign tenant entry',
            'total_amount' => 100,
            'status' => 'posted',
        ]);
        $foreignEntry->lines()->createMany([
            ['account_code' => '1111', 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 100],
        ]);

        $this->postJson("/api/v1/gl/journal-entries/{$foreignEntry->id}/reverse", [
            'reason' => 'Attempt cross tenant',
            'posting_date' => '2026-08-20',
        ])->assertNotFound();

        $this->assertDatabaseMissing('journal_entries', ['reversal_of_id' => $foreignEntry->id]);
    }

    public function test_direct_service_reversal_rejects_a_caller_selected_foreign_tenant(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign direct-service company', 'tax_code' => 'OTHER-DIRECT-REV']);
        $foreignYear = FiscalYear::create([
            'company_id' => $foreignCompany->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        foreach ([
            ['code' => '1111', 'name' => 'Foreign cash', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '511', 'name' => 'Foreign revenue', 'type' => 'revenue', 'nature' => 'credit'],
        ] as $account) {
            ChartOfAccount::create([
                'company_id' => $foreignCompany->id,
                ...$account,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $foreignEntry = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $foreignCompany->id,
            'fiscal_year_id' => $foreignYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'FOREIGN-DIRECT-REV',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Foreign direct-service entry',
            'total_amount' => 100,
            'status' => 'posted',
        ]);
        $foreignEntry->lines()->createMany([
            ['account_code' => '1111', 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 100],
        ]);

        try {
            app(JournalEntryService::class)->reverse(
                $foreignEntry->id,
                $foreignCompany->id,
                'Cross-tenant direct-service attempt',
                '2026-08-20'
            );
            $this->fail('A direct service caller must not select a foreign tenant.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }

        $this->assertNull($foreignEntry->fresh()->reversed_by_entry_id);
        $this->assertDatabaseMissing('journal_entries', ['reversal_of_id' => $foreignEntry->id]);
    }

    public function test_closed_period_rejects_reversal(): void
    {
        $entry = $this->createAndPost('REVERSE-LOCKED');
        Period::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'Tháng 08/2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'closed',
            'is_closed' => true,
        ]);

        $this->postJson("/api/v1/gl/journal-entries/{$entry->id}/reverse", [
            'reason' => 'Không được đảo trong kỳ khóa',
            'posting_date' => '2026-08-20',
        ])->assertStatus(409);

        $this->assertDatabaseMissing('journal_entries', ['reversal_of_id' => $entry->id]);
    }

    public function test_audit_failure_rolls_back_journal_creation(): void
    {
        $failingAudit = new class extends AuditService
        {
            public function record(
                Model $model,
                string $action,
                array $before = [],
                array $after = [],
                ?string $correlationId = null,
                array $metadata = []
            ): AuditLog {
                throw new RuntimeException('Audit storage unavailable');
            }
        };
        $this->app->instance(AuditService::class, $failingAudit);

        try {
            $this->app->make(JournalEntryService::class)->create($this->journalPayload('AUDIT-ROLLBACK'));
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable', $exception->getMessage());
        }

        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'AUDIT-ROLLBACK']);
    }

    public function test_audit_failure_rolls_back_reversal_and_its_links(): void
    {
        $entry = $this->createAndPost('REVERSE-AUDIT-ROLLBACK');
        $failingAudit = new class extends AuditService
        {
            public function record(
                Model $model,
                string $action,
                array $before = [],
                array $after = [],
                ?string $correlationId = null,
                array $metadata = []
            ): AuditLog {
                throw new RuntimeException('Audit storage unavailable');
            }
        };
        $this->app->instance(AuditService::class, $failingAudit);

        try {
            $this->app->make(JournalEntryService::class)->reverse(
                $entry->id,
                $this->company->id,
                'Rollback đảo khi audit lỗi',
                '2026-08-20'
            );
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable', $exception->getMessage());
        }

        $this->assertNull(JournalEntry::findOrFail($entry->id)->reversed_by_entry_id);
        $this->assertDatabaseMissing('journal_entries', ['reversal_of_id' => $entry->id]);
    }

    public function test_period_close_refusal_records_readiness_evidence_without_creating_a_close_audit(): void
    {
        Period::firstOrCreate([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_number' => 8,
        ], [
            'period' => 8,
            'name' => 'Tháng 08/2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
        $this->createAndPost('CLOSE-AUDIT');

        $this->postJson('/api/v1/gl/closing-entries/execute', [
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'close_reason' => 'Đối chiếu cuối kỳ',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('period_close_readiness');

        $this->assertDatabaseHas('period_close_readiness_snapshots', [
            'company_id' => $this->company->id,
            'eligible_to_close' => false,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'company_id' => $this->company->id,
            'action' => 'period.closed',
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $this->company->id,
            'voucher_type' => 'period_closing',
        ]);
    }

    private function createAndPost(string $voucherNumber, int $amount = 500000): JournalEntry
    {
        $response = $this->postJson('/api/v1/gl/journal-entries', $this->journalPayload($voucherNumber, $amount));
        $response->assertCreated();
        $this->postJson("/api/v1/gl/journal-entries/{$response->json('data.id')}/post")->assertOk();

        return JournalEntry::with('lines')->findOrFail($response->json('data.id'));
    }

    private function journalPayload(string $voucherNumber, int $amount = 500000): array
    {
        return [
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Wave 2 accounting control test',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        ];
    }
}
