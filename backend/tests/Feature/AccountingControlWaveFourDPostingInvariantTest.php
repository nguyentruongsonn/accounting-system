<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingControlWaveFourDPostingInvariantTest extends TestCase
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

        $this->createAccount('1111', true, false);
        $this->createAccount('511', true, false, 'revenue', 'credit');
        $this->createAccount('INACTIVE', false, false);
        $this->createAccount('PARENT', true, true);
    }

    public function test_posting_date_must_fall_inside_selected_same_tenant_fiscal_year(): void
    {
        $payload = $this->payload('FY-DATE', '1111', '511');
        $payload['posting_date'] = '2027-01-01';

        $this->postJson('/api/v1/gl/journal-entries', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('posting_date');

        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'FY-DATE']);
    }

    public function test_public_create_rejects_inactive_parent_missing_and_foreign_accounts(): void
    {
        $otherCompany = Company::create(['name' => 'Foreign tenant', 'tax_code' => 'FOREIGN-W4D']);
        ChartOfAccount::withoutGlobalScope('company')->create([
            'company_id' => $otherCompany->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign account',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => false,
            'is_active' => true,
        ]);

        foreach (['INACTIVE', 'PARENT', 'MISSING', 'FOREIGN'] as $index => $invalidCode) {
            $voucherNumber = "PUBLIC-INVALID-{$index}";
            $this->postJson('/api/v1/gl/journal-entries', $this->payload($voucherNumber, $invalidCode, '511'))
                ->assertStatus(422)
                ->assertJsonValidationErrors('lines.0.account_code');

            $this->assertDatabaseMissing('journal_entries', ['voucher_number' => $voucherNumber]);
        }
    }

    public function test_public_create_rejects_zero_only_line(): void
    {
        $payload = $this->payload('ZERO-LINE', '1111', '511');
        $payload['lines'][0] = ['account_code' => '1111', 'debit_amount' => 0, 'credit_amount' => 0];

        $this->postJson('/api/v1/gl/journal-entries', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0');

        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'ZERO-LINE']);
    }

    public function test_trusted_create_posted_enforces_the_same_account_and_line_invariants(): void
    {
        $service = $this->app->make(JournalEntryService::class);
        $otherCompany = Company::create(['name' => 'Internal foreign tenant', 'tax_code' => 'INT-FOREIGN-W4D']);
        ChartOfAccount::withoutGlobalScope('company')->create([
            'company_id' => $otherCompany->id,
            'code' => 'FOREIGN',
            'name' => 'Internal foreign account',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => false,
            'is_active' => true,
        ]);

        foreach (['INACTIVE', 'PARENT', 'MISSING', 'FOREIGN'] as $index => $invalidCode) {
            try {
                $service->createPosted($this->payload("INTERNAL-INVALID-{$index}", $invalidCode, '511'));
                $this->fail("Expected internal posting with {$invalidCode} to be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('lines', $exception->errors());
            }
        }

        $zeroPayload = $this->payload('INTERNAL-ZERO', '1111', '511');
        $zeroPayload['lines'][0] = ['account_code' => '1111', 'debit_amount' => 0, 'credit_amount' => 0];
        try {
            $service->createPosted($zeroPayload);
            $this->fail('Expected internal zero-only line to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0', $exception->errors());
        }

        $this->assertSame(0, JournalEntry::where('voucher_number', 'like', 'INTERNAL-%')->count());
    }

    public function test_trusted_create_posted_resolves_fiscal_year_by_posting_date_and_accepts_valid_leaf_accounts(): void
    {
        $payload = $this->payload('INTERNAL-VALID', '1111', '511');
        unset($payload['fiscal_year_id']);

        $entry = $this->app->make(JournalEntryService::class)->createPosted($payload);

        $this->assertSame('posted', $entry->status);
        $this->assertSame($this->fiscalYear->id, $entry->fiscal_year_id);
        $this->assertSame(2, $entry->lines()->count());
    }

    public function test_post_transition_revalidates_legacy_draft_accounts(): void
    {
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'LEGACY-INACTIVE',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Legacy invalid draft',
            'total_amount' => 100,
            'status' => 'draft',
        ]);
        $entry->lines()->createMany([
            ['account_code' => 'INACTIVE', 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 100],
        ]);

        $this->postJson("/api/v1/gl/journal-entries/{$entry->id}/post")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->assertSame('draft', $entry->fresh()->status);
    }

    private function createAccount(
        string $code,
        bool $active,
        bool $parent,
        string $type = 'asset',
        string $nature = 'debit'
    ): void {
        ChartOfAccount::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'nature' => $nature,
            'level' => 1,
            'is_parent' => $parent,
            'is_active' => $active,
        ]);
    }

    private function payload(string $voucherNumber, string $debitAccount, string $creditAccount): array
    {
        return [
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Wave 4D posting invariant',
            'lines' => [
                ['account_code' => $debitAccount, 'debit_amount' => 100, 'credit_amount' => 0],
                ['account_code' => $creditAccount, 'debit_amount' => 0, 'credit_amount' => 100],
            ],
        ];
    }
}
