<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class SourceJournalLineageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Lineage Company',
            'tax_code' => 'LINEAGE-001',
        ]);
        $this->configureAccountingTenant($this->user, $this->company);
        Sanctum::actingAs($this->user);

        foreach ([
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '131', 'name' => 'Phải thu', 'type' => 'asset', 'nature' => 'debit'],
        ] as $account) {
            ChartOfAccount::create(array_merge($account, [
                'company_id' => $this->company->id,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]));
        }
    }

    public function test_source_and_posted_journal_are_linked_in_both_directions_and_retry_is_idempotent(): void
    {
        $source = $this->source('PT-LINEAGE-01');
        $service = app(JournalEntryService::class);

        $first = $service->createPosted($this->payload($source, 'GL-PT-LINEAGE-01'));
        $retry = $service->createPosted($this->payload($source->fresh(), 'GL-PT-LINEAGE-01'));

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($first->id, (int) $source->fresh()->journal_entry_id);
        $this->assertSame(CashReceipt::class, $first->source_document_type);
        $this->assertSame($source->id, (int) $first->source_document_id);
        $this->assertTrue($first->fresh()->sourceDocument->is($source));
        $this->assertSame(1, JournalEntry::where('status', 'posted')
            ->where('source_document_type', CashReceipt::class)
            ->where('source_document_id', $source->id)
            ->count());
    }

    public function test_cross_tenant_source_is_rejected_before_any_journal_is_created(): void
    {
        $other = Company::create(['name' => 'Other Company', 'tax_code' => 'LINEAGE-002']);
        $source = CashReceipt::create([
            'company_id' => $other->id,
            'voucher_number' => 'PT-FOREIGN-01',
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
        ]);

        try {
            app(JournalEntryService::class)->createPosted($this->payload($source, 'GL-PT-FOREIGN-01'));
            $this->fail('Cross-tenant source posting should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_document_id', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'GL-PT-FOREIGN-01']);
        $this->assertNull($source->fresh()->journal_entry_id);
    }

    public function test_cross_tenant_existing_source_pointer_is_not_silently_overwritten(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign linked journal', 'tax_code' => 'LINEAGE-003']);
        $foreignYear = FiscalYear::create([
            'company_id' => $foreignCompany->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $foreignEntry = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $foreignCompany->id,
            'fiscal_year_id' => $foreignYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'FOREIGN-LINKED-JE',
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'description' => 'Foreign linked entry',
            'total_amount' => 100,
            'status' => 'draft',
        ]);
        $source = $this->source('PT-CROSS-TENANT-POINTER');
        $source->update(['journal_entry_id' => $foreignEntry->id]);

        try {
            app(JournalEntryService::class)->createPosted($this->payload($source->fresh(), 'GL-PT-CROSS-TENANT-POINTER'));
            $this->fail('A cross-tenant source pointer must fail closed.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('không thuộc doanh nghiệp', $exception->getMessage());
        }

        $this->assertSame($foreignEntry->id, (int) $source->fresh()->journal_entry_id);
        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'GL-PT-CROSS-TENANT-POINTER']);
    }

    public function test_unregistered_eloquent_model_cannot_be_used_as_a_posting_source(): void
    {
        $payload = $this->payload($this->source('PT-REGISTRY-01'), 'GL-PT-REGISTRY-01');
        $payload['source_document_type'] = Company::class;
        $payload['source_document_id'] = $this->company->id;

        try {
            app(JournalEntryService::class)->createPosted($payload);
            $this->fail('An unregistered Eloquent source should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_document_type', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'GL-PT-REGISTRY-01']);
    }

    public function test_soft_deleted_registered_source_cannot_be_posted(): void
    {
        $source = $this->source('PT-DELETED-01');
        $payload = $this->payload($source, 'GL-PT-DELETED-01');
        $source->delete();

        try {
            app(JournalEntryService::class)->createPosted($payload);
            $this->fail('A soft-deleted registered source should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_document_id', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'GL-PT-DELETED-01']);
    }

    public function test_repost_keeps_voided_history_and_moves_source_header_to_latest_entry(): void
    {
        $source = $this->source('PT-REPOST-01');
        $service = app(JournalEntryService::class);
        $first = $service->createPosted($this->payload($source, 'GL-PT-REPOST-01'));

        $service->void($first->id, $this->company->id);
        $second = $service->createPosted($this->payload($source->fresh(), 'GL-PT-REPOST-01'));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('voided', $first->fresh()->status);
        $this->assertSame('posted', $second->fresh()->status);
        $this->assertSame('GL-PT-REPOST-01-R1', $second->voucher_number);
        $this->assertSame($second->id, (int) $source->fresh()->journal_entry_id);
        $this->assertSame(2, JournalEntry::where('source_document_type', CashReceipt::class)
            ->where('source_document_id', $source->id)
            ->count());
        $this->assertSame(1, JournalEntry::where('source_document_type', CashReceipt::class)
            ->where('source_document_id', $source->id)
            ->where('status', 'posted')
            ->count());
    }

    public function test_general_trusted_create_cannot_bypass_one_active_posted_journal_per_source(): void
    {
        $source = $this->source('PT-ONE-ACTIVE-01');
        $service = app(JournalEntryService::class);
        $service->createPosted($this->payload($source, 'GL-PT-ONE-ACTIVE-01'));

        $duplicate = $this->payload($source->fresh(), 'GL-PT-ONE-ACTIVE-BYPASS');
        $duplicate['status'] = 'posted';

        $this->expectException(ConflictHttpException::class);
        $service->create($duplicate);
    }

    public function test_duplicate_of_sourced_entry_becomes_unlinked_manual_draft_before_posting(): void
    {
        $source = $this->source('PT-DUPLICATE-01');
        $service = app(JournalEntryService::class);
        $original = $service->createPosted($this->payload($source, 'GL-PT-DUPLICATE-01'));

        $duplicate = $service->duplicate($original->id);

        $this->assertNull($duplicate->source_document_type);
        $this->assertNull($duplicate->source_document_id);
        $this->assertNull($duplicate->reversal_of_id);
        $this->assertNull($duplicate->reversed_by_entry_id);

        $postedDuplicate = $service->post($duplicate->id);
        $this->assertSame('posted', $postedDuplicate->status);
        $this->assertSame(1, JournalEntry::where('source_document_type', CashReceipt::class)
            ->where('source_document_id', $source->id)
            ->where('status', 'posted')
            ->count());
        $this->assertSame($original->id, (int) $source->fresh()->journal_entry_id);
    }

    public function test_manual_post_rejects_a_source_bearing_draft_as_a_defense_in_depth_control(): void
    {
        $source = $this->source('PT-MANUAL-SOURCE-01');
        $draft = app(JournalEntryService::class)->create([
            ...$this->payload($source, 'GL-PT-MANUAL-SOURCE-01'),
            'status' => 'draft',
        ]);

        try {
            app(JournalEntryService::class)->post($draft->id);
            $this->fail('Manual post must not post a draft carrying source lineage.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseMissing('journal_entries', [
            'source_document_type' => CashReceipt::class,
            'source_document_id' => $source->id,
            'status' => 'posted',
        ]);
    }

    private function source(string $number): CashReceipt
    {
        return CashReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'reason' => 'Thu tiền',
            'total_amount' => 100000,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(CashReceipt $source, string $voucherNumber): array
    {
        return [
            'company_id' => $this->company->id,
            'voucher_type' => 'cash_receipt',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'description' => 'Thu tiền',
            'source_document_type' => CashReceipt::class,
            'source_document_id' => $source->id,
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => 100000, 'credit_amount' => 0],
                ['account_code' => '131', 'debit_amount' => 0, 'credit_amount' => 100000],
            ],
        ];
    }
}
