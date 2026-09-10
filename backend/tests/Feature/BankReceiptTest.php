<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Test Company',
            'tax_code' => '123456789',
            'address' => 'Test Address',
        ]);
        $this->configureAccountingTenant($this->user, $this->company);

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '123456789',
            'bank_name' => 'Test Bank',
            'gl_account_code' => '1121',
        ]);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1121', 'name' => 'Cash in Bank', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '131', 'name' => 'Accounts Receivable', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
    }

    public function test_can_create_bank_receipt()
    {
        $payload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BR001',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'payer_name' => 'John Doe',
            'description' => 'Receive money from customer via bank',
            'lines' => [
                [
                    'credit_account' => '131',
                    'amount' => 1000000,
                    'description' => 'Payment for invoice',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/bank/receipts', $payload);
        $response->dump();
        $response->assertStatus(201)
            ->assertJsonPath('voucher_number', 'BR001')
            ->assertJsonPath('amount', 1000000);

        $this->assertDatabaseHas('bank_receipts', [
            'voucher_number' => 'BR001',
            'amount' => 1000000,
        ]);

        $this->assertDatabaseHas('bank_receipt_lines', [
            'debit_account' => '1121',
            'credit_account' => '131',
            'amount' => 1000000,
        ]);
    }

    public function test_can_post_bank_receipt_to_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BR002',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'description' => 'Receive money',
            'lines' => [
                [
                    'credit_account' => '131',
                    'amount' => 1000000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/bank/receipts', $payload);
        $receiptId = $response->json('id');

        $postResponse = $this->postJson("/api/v1/bank/receipts/{$receiptId}/post");
        $postResponse->assertStatus(200);

        $this->assertDatabaseHas('bank_receipts', [
            'id' => $receiptId,
            'is_posted' => true,
        ]);

        // check GL entries
        $receipt = BankReceipt::find($receiptId);
        $this->assertNotNull($receipt->journal_entry_id);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $receipt->journal_entry_id,
            'status' => 'posted',
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $receipt->journal_entry_id,
            'account_code' => '1121',
            'debit_amount' => 1000000,
            'credit_amount' => 0,
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $receipt->journal_entry_id,
            'account_code' => '131',
            'debit_amount' => 0,
            'credit_amount' => 1000000,
        ]);
    }

    public function test_can_void_bank_receipt()
    {
        $payload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BR003',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'lines' => [
                [
                    'credit_account' => '131',
                    'amount' => 500000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/bank/receipts', $payload);
        $receiptId = $response->json('id');

        $this->postJson("/api/v1/bank/receipts/{$receiptId}/post");

        $voidResponse = $this->postJson("/api/v1/bank/receipts/{$receiptId}/void");
        $voidResponse->assertStatus(200);

        $this->assertDatabaseHas('bank_receipts', [
            'id' => $receiptId,
            'is_posted' => false,
        ]);

        $receipt = BankReceipt::find($receiptId);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $receipt->journal_entry_id,
            'status' => 'voided',
        ]);
    }
}
