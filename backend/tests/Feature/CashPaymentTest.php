<?php

namespace Tests\Feature;

use App\Models\CashPayment;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Test Company',
            'tax_code' => '123456789',
            'address' => 'Test Address',
        ]);
        $this->configureAccountingTenant($this->user, $this->company);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1111', 'name' => 'Cash', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '331', 'name' => 'Accounts Payable', 'type' => 'liability', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '642', 'name' => 'Admin Expenses', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
    }

    public function test_can_create_cash_payment()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PC001',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'payee_name' => 'Jane Doe',
            'description' => 'Pay cash to supplier',
            'lines' => [
                [
                    'debit_account' => '331',
                    'amount' => 500000,
                    'description' => 'Payment for invoice',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/cash/payments', $payload);
        $response->dump();
        $response->assertStatus(201)
            ->assertJsonPath('data.voucher_number', 'PC001')
            ->assertJsonPath('data.total_amount', 500000)
            ->assertJsonPath('data.receiver_name', 'Jane Doe');

        $this->assertDatabaseHas('cash_payments', [
            'voucher_number' => 'PC001',
            'total_amount' => 500000,
        ]);

        $this->assertDatabaseHas('cash_payment_lines', [
            'credit_account' => '1111',
            'debit_account' => '331',
            'amount' => 500000,
        ]);
    }

    public function test_can_post_cash_payment_to_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PC002',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'description' => 'Pay cash to supplier',
            'lines' => [
                [
                    'debit_account' => '331',
                    'amount' => 500000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/cash/payments', $payload);
        $paymentId = $response->json('data.id');

        $postResponse = $this->postJson("/api/v1/cash/payments/{$paymentId}/post");
        $postResponse->assertStatus(200);

        $this->getJson("/api/v1/cash/payments/{$paymentId}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.is_posted', true);

        $this->assertDatabaseHas('cash_payments', [
            'id' => $paymentId,
            'is_posted' => true,
        ]);

        // check GL entries
        $payment = CashPayment::find($paymentId);
        $this->assertNotNull($payment->journal_entry_id);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $payment->journal_entry_id,
            'status' => 'posted',
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $payment->journal_entry_id,
            'account_code' => '331',
            'debit_amount' => 500000,
            'credit_amount' => 0,
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $payment->journal_entry_id,
            'account_code' => '1111',
            'debit_amount' => 0,
            'credit_amount' => 500000,
        ]);
    }

    public function test_can_void_cash_payment()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PC003',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'lines' => [
                [
                    'debit_account' => '331',
                    'amount' => 250000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/cash/payments', $payload);
        $paymentId = $response->json('data.id');

        $this->postJson("/api/v1/cash/payments/{$paymentId}/post");

        $voidResponse = $this->postJson("/api/v1/cash/payments/{$paymentId}/void");
        $voidResponse->assertStatus(200);

        $this->assertDatabaseHas('cash_payments', [
            'id' => $paymentId,
            'is_posted' => false,
        ]);

        $payment = CashPayment::find($paymentId);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $payment->journal_entry_id,
            'status' => 'voided',
        ]);
    }

    public function test_posted_cash_payment_cannot_be_updated_or_deleted(): void
    {
        $paymentId = $this->createPostedPayment('PC-IMMUTABLE-POSTED');

        $this->putJson("/api/v1/cash/payments/{$paymentId}", [
            'description' => 'Must not replace posted evidence',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Không thể sửa phiếu chi tiền mặt đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.');

        $this->deleteJson("/api/v1/cash/payments/{$paymentId}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'Không thể xóa phiếu chi tiền mặt đã ghi sổ. Vui lòng bỏ ghi sổ trước khi xóa.');

        $this->assertDatabaseHas('cash_payments', [
            'id' => $paymentId,
            'status' => 'posted',
            'is_posted' => true,
        ]);
    }

    public function test_voided_cash_payment_cannot_be_updated_or_deleted(): void
    {
        $paymentId = $this->createPostedPayment('PC-IMMUTABLE-VOIDED');
        $this->postJson("/api/v1/cash/payments/{$paymentId}/void")->assertOk();

        $this->putJson("/api/v1/cash/payments/{$paymentId}", [
            'description' => 'Must not replace voided evidence',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Không thể sửa phiếu chi tiền mặt đã hủy.');

        $this->deleteJson("/api/v1/cash/payments/{$paymentId}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'Không thể xóa phiếu chi tiền mặt đã hủy.');

        $this->assertDatabaseHas('cash_payments', [
            'id' => $paymentId,
            'status' => 'voided',
            'is_posted' => false,
        ]);
    }

    private function createPostedPayment(string $voucherNumber): int
    {
        config()->set('accounting.enforce_cash_bank_posting_policy', false);
        config()->set('accounting.enforce_cash_bank_posting_account_mappings', false);

        $response = $this->postJson('/api/v1/cash/payments', [
            'company_id' => $this->company->id,
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'description' => 'Immutable cash payment',
            'lines' => [
                [
                    'debit_account' => '331',
                    'amount' => 500000,
                ],
            ],
        ]);
        $response->assertCreated();
        $paymentId = (int) $response->json('data.id');

        $this->postJson("/api/v1/cash/payments/{$paymentId}/post")->assertOk();

        return $paymentId;
    }

    public function test_can_duplicate_cash_payment_with_references()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PC-ORIG-01',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'description' => 'Original payment with reference',
            'referenced_vouchers' => [
                [
                    'target_type' => 'App\\Models\\PurchaseInvoice',
                    'target_id' => 99,
                    'voucher_type' => 'Hóa đơn mua hàng',
                    'voucher_number' => 'HDMH-99',
                    'voucher_date' => '2026-08-10',
                    'total_amount' => 500000,
                    'description' => 'Tham chiếu HĐ mua 99',
                ],
            ],
            'lines' => [
                [
                    'debit_account' => '331',
                    'amount' => 500000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/cash/payments', $payload);
        $paymentId = $response->json('data.id');

        $dupResponse = $this->postJson("/api/v1/cash/payments/{$paymentId}/duplicate");
        $dupResponse->assertStatus(201);
        $dupId = $dupResponse->json('data.id');

        $dup = CashPayment::with('references')->find($dupId);
        $this->assertNotNull($dup);
        $this->assertCount(1, $dup->references);
        $this->assertEquals('HDMH-99', $dup->references->first()->target_voucher_number);
    }
}
