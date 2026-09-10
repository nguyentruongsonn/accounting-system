<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankPaymentTest extends TestCase
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
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '331', 'name' => 'Accounts Payable', 'type' => 'liability', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
    }

    public function test_can_create_bank_payment()
    {
        $payload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BP001',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'payee_name' => 'Jane Doe',
            'description' => 'Pay supplier via bank',
            'lines' => [
                [
                    'debit_account' => '331',
                    'amount' => 1500000,
                    'description' => 'Payment for invoice',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/bank/payments', $payload);
        $response->dump();
        $response->assertStatus(201)
            ->assertJsonPath('voucher_number', 'BP001')
            ->assertJsonPath('amount', 1500000);

        $this->assertDatabaseHas('bank_payments', [
            'voucher_number' => 'BP001',
            'amount' => 1500000,
        ]);

        $this->assertDatabaseHas('bank_payment_lines', [
            'credit_account' => '1121',
            'debit_account' => '331',
            'amount' => 1500000,
        ]);
    }

    public function test_can_post_bank_payment_to_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BP002',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'description' => 'Pay supplier via bank',
            'lines' => [
                [
                    'debit_account' => '331',
                    'amount' => 1500000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/bank/payments', $payload);
        $paymentId = $response->json('id');

        $postResponse = $this->postJson("/api/v1/bank/payments/{$paymentId}/post");
        $postResponse->assertStatus(200);

        $this->assertDatabaseHas('bank_payments', [
            'id' => $paymentId,
            'is_posted' => true,
        ]);

        // check GL entries
        $payment = BankPayment::find($paymentId);
        $this->assertNotNull($payment->journal_entry_id);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $payment->journal_entry_id,
            'status' => 'posted',
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $payment->journal_entry_id,
            'account_code' => '331',
            'debit_amount' => 1500000,
            'credit_amount' => 0,
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $payment->journal_entry_id,
            'account_code' => '1121',
            'debit_amount' => 0,
            'credit_amount' => 1500000,
        ]);
    }

    public function test_can_void_bank_payment()
    {
        $payload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BP003',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'lines' => [
                [
                    'debit_account' => '331',
                    'amount' => 1000000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/bank/payments', $payload);
        $paymentId = $response->json('id');

        $this->postJson("/api/v1/bank/payments/{$paymentId}/post");

        $voidResponse = $this->postJson("/api/v1/bank/payments/{$paymentId}/void");
        $voidResponse->assertStatus(200);

        $this->assertDatabaseHas('bank_payments', [
            'id' => $paymentId,
            'is_posted' => false,
        ]);

        $payment = BankPayment::find($paymentId);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $payment->journal_entry_id,
            'status' => 'voided',
        ]);
    }
}
