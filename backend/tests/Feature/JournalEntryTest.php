<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Company',
            'tax_code' => '123456789',
            'address' => 'Test Address',
        ]);

        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->grantGlReportPermissions($this->user);
        Sanctum::actingAs($this->user);

        FiscalYear::create([
            'company_id' => $this->company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1111', 'name' => 'Cash', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '511', 'name' => 'Revenue', 'type' => 'revenue', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
    }

    public function test_can_create_journal_entry()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'GJ001',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'total_amount' => 500000,
            'description' => 'General journal entry',
            'lines' => [
                [
                    'account_code' => '1111',
                    'debit_amount' => 500000,
                    'credit_amount' => 0,
                    'description' => 'Debit Cash',
                ],
                [
                    'account_code' => '511',
                    'debit_amount' => 0,
                    'credit_amount' => 500000,
                    'description' => 'Credit Revenue',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/gl/journal-entries', $payload);
        $response->dump();
        $response->assertStatus(201)
            ->assertJsonPath('data.voucher_number', 'GJ001');

        $this->assertDatabaseHas('journal_entries', [
            'voucher_number' => 'GJ001',
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'account_code' => '1111',
            'debit_amount' => 500000,
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'account_code' => '511',
            'credit_amount' => 500000,
        ]);
    }

    public function test_cannot_create_unbalanced_journal_entry()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'GJ002',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'total_amount' => 500000,
            'description' => 'Unbalanced entry',
            'lines' => [
                [
                    'account_code' => '1111',
                    'debit_amount' => 500000,
                    'credit_amount' => 0,
                ],
                [
                    'account_code' => '511',
                    'debit_amount' => 0,
                    'credit_amount' => 400000, // Unbalanced
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/gl/journal-entries', $payload);
        // Assuming validation rule or service logic prevents unbalanced entry.
        // It could return 400 or 422 depending on implementation.
        $this->assertTrue(in_array($response->status(), [400, 422, 500]));

        $this->assertDatabaseMissing('journal_entries', [
            'voucher_number' => 'GJ002',
        ]);
    }

    public function test_can_post_journal_entry()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'GJ003',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'total_amount' => 500000,
            'description' => 'Entry to post',
            'lines' => [
                [
                    'account_code' => '1111',
                    'debit_amount' => 500000,
                    'credit_amount' => 0,
                ],
                [
                    'account_code' => '511',
                    'debit_amount' => 0,
                    'credit_amount' => 500000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/gl/journal-entries', $payload);
        $entryId = $response->json('data.id');

        $postResponse = $this->postJson("/api/v1/gl/journal-entries/{$entryId}/post");
        $postResponse->assertStatus(200);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entryId,
            'status' => 'posted',
        ]);
    }

    public function test_can_void_journal_entry()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'GJ004',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'total_amount' => 500000,
            'lines' => [
                [
                    'account_code' => '1111',
                    'debit_amount' => 500000,
                    'credit_amount' => 0,
                ],
                [
                    'account_code' => '511',
                    'debit_amount' => 0,
                    'credit_amount' => 500000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/gl/journal-entries', $payload);
        $entryId = $response->json('data.id');

        $this->postJson("/api/v1/gl/journal-entries/{$entryId}/post");

        $voidResponse = $this->postJson("/api/v1/gl/journal-entries/{$entryId}/void");
        $voidResponse->assertStatus(200);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entryId,
            'status' => 'voided',
        ]);
    }
}
