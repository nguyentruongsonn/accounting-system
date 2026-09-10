<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AuditService;
use App\Services\JournalEntryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class JournalEntryManualDraftAuditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::findOrFail(1);
        $this->fiscalYear = FiscalYear::where('company_id', $this->company->id)->firstOrFail();
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->grantGlReportPermissions($user);
        Sanctum::actingAs($user);

        foreach ([
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '511', 'name' => 'Doanh thu', 'type' => 'revenue', 'nature' => 'credit'],
        ] as $account) {
            ChartOfAccount::updateOrCreate(
                ['company_id' => $this->company->id, 'code' => $account['code']],
                [...$account, 'level' => 1, 'is_parent' => false, 'is_active' => true]
            );
        }
    }

    public function test_manual_draft_update_records_before_and_after_evidence(): void
    {
        $entryId = $this->createDraft('MJE-AUD-UPDATE');
        $payload = $this->payload('MJE-AUD-UPDATE', 275000);
        $payload['description'] = 'Điều chỉnh diễn giải bút toán nháp';

        $this->putJson("/api/v1/gl/journal-entries/{$entryId}", $payload)->assertOk();

        $audit = AuditLog::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('model_type', JournalEntry::class)
            ->where('model_id', $entryId)
            ->where('action', 'journal.updated')
            ->sole();

        $this->assertSame('Test draft audit controls', $audit->old_values['description']);
        $this->assertSame('Điều chỉnh diễn giải bút toán nháp', $audit->new_values['description']);
        $this->assertCount(2, $audit->old_values['lines']);
        $this->assertCount(2, $audit->new_values['lines']);
    }

    public function test_duplicate_records_origin_linkage_without_copying_source_linkage(): void
    {
        $originalId = $this->createDraft('MJE-AUD-DUP');

        $response = $this->postJson("/api/v1/gl/journal-entries/{$originalId}/duplicate")
            ->assertCreated();
        $duplicateId = (int) $response->json('data.id');

        $audit = AuditLog::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('model_type', JournalEntry::class)
            ->where('model_id', $duplicateId)
            ->where('action', 'journal.duplicated')
            ->sole();

        $this->assertSame($originalId, $audit->metadata['duplicated_from_journal_entry_id']);
        $this->assertNull(JournalEntry::findOrFail($duplicateId)->source_document_type);
    }

    public function test_delete_records_audit_and_soft_deletes_only_draft(): void
    {
        $entryId = $this->createDraft('MJE-AUD-DELETE');

        $this->deleteJson("/api/v1/gl/journal-entries/{$entryId}")->assertNoContent();

        $this->assertSoftDeleted('journal_entries', ['id' => $entryId]);
        $audit = AuditLog::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('model_type', JournalEntry::class)
            ->where('model_id', $entryId)
            ->where('action', 'journal.deleted')
            ->sole();
        $this->assertSame('draft', $audit->old_values['status']);
        $this->assertNotNull($audit->new_values['deleted_at']);
    }

    public function test_audit_failure_rolls_back_manual_draft_update(): void
    {
        $entryId = $this->createDraft('MJE-AUD-ROLLBACK');
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
            $payload = $this->payload('MJE-AUD-ROLLBACK', 275000);
            $payload['description'] = 'Không được lưu nếu audit thất bại';
            $this->app->make(JournalEntryService::class)->update($entryId, $payload, $this->company->id);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable', $exception->getMessage());
        }

        $entry = JournalEntry::findOrFail($entryId)->load('lines');
        $this->assertSame('Test draft audit controls', $entry->description);
        $this->assertSame('125000.00', (string) $entry->lines->first()->debit_amount);
    }

    private function createDraft(string $voucherNumber): int
    {
        return (int) $this->postJson('/api/v1/gl/journal-entries', $this->payload($voucherNumber))
            ->assertCreated()
            ->json('data.id');
    }

    private function payload(string $voucherNumber, int $amount = 125000): array
    {
        return [
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Test draft audit controls',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        ];
    }
}
