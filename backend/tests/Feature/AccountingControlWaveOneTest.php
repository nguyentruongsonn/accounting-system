<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingControlWaveOneTest extends TestCase
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
            ['code' => '911', 'name' => 'Xác định kết quả', 'type' => 'equity', 'nature' => 'amphibious'],
            ['code' => '4212', 'name' => 'Lợi nhuận năm nay', 'type' => 'equity', 'nature' => 'amphibious'],
        ] as $account) {
            ChartOfAccount::updateOrCreate(
                ['company_id' => $this->company->id, 'code' => $account['code']],
                [...$account, 'level' => 1, 'is_parent' => false, 'is_active' => true]
            );
        }
    }

    public function test_posted_journal_is_immutable_and_duplicate_number_cannot_overwrite_it(): void
    {
        $entryId = $this->createDraft('IMM-001');
        $this->postJson("/api/v1/gl/journal-entries/{$entryId}/post")->assertOk();

        $changedPayload = $this->journalPayload('IMM-001', 900000);
        $this->putJson("/api/v1/gl/journal-entries/{$entryId}", $changedPayload)
            ->assertStatus(409);
        $this->deleteJson("/api/v1/gl/journal-entries/{$entryId}")
            ->assertStatus(409);
        $this->postJson('/api/v1/gl/journal-entries', $changedPayload)
            ->assertStatus(409);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entryId,
            'voucher_number' => 'IMM-001',
            'status' => 'posted',
            'total_amount' => 500000,
        ]);
        $this->assertSame(2, JournalEntry::findOrFail($entryId)->lines()->count());
    }

    public function test_closed_period_rejects_create_update_post_and_delete(): void
    {
        $entryId = $this->createDraft('LOCK-EXISTING');

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

        $this->postJson('/api/v1/gl/journal-entries', $this->journalPayload('LOCK-NEW'))
            ->assertStatus(409);
        $this->putJson("/api/v1/gl/journal-entries/{$entryId}", $this->journalPayload('LOCK-EXISTING'))
            ->assertStatus(409);
        $this->postJson("/api/v1/gl/journal-entries/{$entryId}/post")
            ->assertStatus(409);
        $this->deleteJson("/api/v1/gl/journal-entries/{$entryId}")
            ->assertStatus(409);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entryId,
            'status' => 'draft',
        ]);
    }

    public function test_tampered_unbalanced_period_closing_lines_are_rejected(): void
    {
        $entryId = $this->createDraft('REV-001');
        $this->postJson("/api/v1/gl/journal-entries/{$entryId}/post")->assertOk();

        $this->postJson('/api/v1/gl/closing-entries/execute', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'close_reason' => 'Kiểm tra dữ liệu máy chủ',
            'lines' => [
                ['account_code' => '511', 'debit_amount' => 500000, 'credit_amount' => 0],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 1],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $this->company->id,
            'voucher_type' => 'period_closing',
        ]);
    }

    public function test_server_generated_period_closing_preview_is_balanced_but_execution_requires_readiness(): void
    {
        $this->openAugustPeriod();
        $entryId = $this->createDraft('REV-002');
        $this->postJson("/api/v1/gl/journal-entries/{$entryId}/post")->assertOk();

        // The preview is the independently reviewable accounting calculation.
        // It must remain usable while the mandatory close-readiness workflow is
        // not yet approved for this period.
        $preview = $this->getJson('/api/v1/gl/closing-entries/preview?from_date=2026-08-01&to_date=2026-08-31')
            ->assertOk()
            ->json('data');

        $this->assertSame('500000.00', $preview['total_revenue']);
        $this->assertSame('0.00', $preview['total_expenses']);
        $this->assertSame('500000.00', $preview['net_profit']);
        $this->assertSame('1000000.00', array_reduce(
            $preview['suggested_lines'],
            fn (string $total, array $line): string => bcadd($total, $line['amount'], 2),
            '0.00'
        ));

        $this->postJson('/api/v1/gl/closing-entries/execute', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'close_reason' => 'Đối chiếu cuối kỳ',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('period_close_readiness');

        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $this->company->id,
            'voucher_type' => 'period_closing',
        ]);
    }

    private function createDraft(string $voucherNumber): int
    {
        $response = $this->postJson('/api/v1/gl/journal-entries', $this->journalPayload($voucherNumber));
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function openAugustPeriod(): void
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
            'description' => 'Test accounting controls',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        ];
    }
}
