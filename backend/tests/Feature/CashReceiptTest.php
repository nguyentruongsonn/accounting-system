<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashReceiptTest extends TestCase
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
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '131', 'name' => 'Accounts Receivable', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '511', 'name' => 'Revenue', 'type' => 'revenue', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
    }

    public function test_can_create_cash_receipt()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PT001',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'payer_name' => 'John Doe',
            'description' => 'Receive cash from customer',
            'lines' => [
                [
                    'credit_account' => '131',
                    'amount' => 1000000,
                    'description' => 'Payment for invoice',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/cash/receipts', $payload);
        $response->dump();
        $response->assertStatus(201)
            ->assertJsonPath('data.voucher_number', 'PT001')
            ->assertJsonPath('data.total_amount', 1000000);

        $this->assertDatabaseHas('cash_receipts', [
            'voucher_number' => 'PT001',
            'total_amount' => 1000000,
        ]);

        $this->assertDatabaseHas('cash_receipt_lines', [
            'debit_account' => '1111',
            'credit_account' => '131',
            'amount' => 1000000,
        ]);
    }

    public function test_can_post_cash_receipt_to_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PT002',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'description' => 'Receive cash from customer',
            'lines' => [
                [
                    'credit_account' => '131',
                    'amount' => 1000000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/cash/receipts', $payload);
        $receiptId = $response->json('data.id');

        $postResponse = $this->postJson("/api/v1/cash/receipts/{$receiptId}/post");
        $postResponse->assertStatus(200);

        $this->getJson("/api/v1/cash/receipts/{$receiptId}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.is_posted', true);

        $this->assertDatabaseHas('cash_receipts', [
            'id' => $receiptId,
            'is_posted' => true,
        ]);

        // check GL entries
        $receipt = CashReceipt::find($receiptId);
        $this->assertNotNull($receipt->journal_entry_id);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $receipt->journal_entry_id,
            'status' => 'posted',
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $receipt->journal_entry_id,
            'account_code' => '1111',
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

    public function test_can_void_cash_receipt()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PT003',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'lines' => [
                [
                    'credit_account' => '131',
                    'amount' => 500000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/cash/receipts', $payload);
        $receiptId = $response->json('data.id');

        $this->postJson("/api/v1/cash/receipts/{$receiptId}/post");

        $voidResponse = $this->postJson("/api/v1/cash/receipts/{$receiptId}/void");
        $voidResponse->assertStatus(200);

        $this->assertDatabaseHas('cash_receipts', [
            'id' => $receiptId,
            'is_posted' => false,
        ]);

        $receipt = CashReceipt::find($receiptId);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $receipt->journal_entry_id,
            'status' => 'voided',
        ]);
    }

    public function test_posted_cash_receipt_cannot_be_updated_or_deleted(): void
    {
        $receiptId = $this->createPostedReceipt('PT-IMMUTABLE-POSTED');

        $this->putJson("/api/v1/cash/receipts/{$receiptId}", [
            'description' => 'Must not replace posted evidence',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Không thể sửa phiếu thu tiền mặt đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.');

        $this->deleteJson("/api/v1/cash/receipts/{$receiptId}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'Không thể xóa phiếu thu tiền mặt đã ghi sổ. Vui lòng bỏ ghi sổ trước khi xóa.');

        $this->assertDatabaseHas('cash_receipts', [
            'id' => $receiptId,
            'status' => 'posted',
            'is_posted' => true,
        ]);
    }

    public function test_voided_cash_receipt_cannot_be_updated_or_deleted(): void
    {
        $receiptId = $this->createPostedReceipt('PT-IMMUTABLE-VOIDED');
        $this->postJson("/api/v1/cash/receipts/{$receiptId}/void")->assertOk();

        $this->putJson("/api/v1/cash/receipts/{$receiptId}", [
            'description' => 'Must not replace voided evidence',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Không thể sửa phiếu thu tiền mặt đã hủy.');

        $this->deleteJson("/api/v1/cash/receipts/{$receiptId}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'Không thể xóa phiếu thu tiền mặt đã hủy.');

        $this->assertDatabaseHas('cash_receipts', [
            'id' => $receiptId,
            'status' => 'voided',
            'is_posted' => false,
        ]);
    }

    private function createPostedReceipt(string $voucherNumber): int
    {
        config()->set('accounting.enforce_cash_bank_posting_policy', false);
        config()->set('accounting.enforce_cash_bank_posting_account_mappings', false);

        $response = $this->postJson('/api/v1/cash/receipts', [
            'company_id' => $this->company->id,
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'description' => 'Immutable cash receipt',
            'lines' => [
                [
                    'credit_account' => '131',
                    'amount' => 1000000,
                ],
            ],
        ]);
        $response->assertCreated();
        $receiptId = (int) $response->json('data.id');

        $this->postJson("/api/v1/cash/receipts/{$receiptId}/post")->assertOk();

        return $receiptId;
    }

    public function test_can_duplicate_cash_receipt_with_references()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PT-ORIG-01',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'description' => 'Original receipt with reference',
            'referenced_vouchers' => [
                [
                    'target_type' => 'App\\Models\\SalesInvoice',
                    'target_id' => 88,
                    'voucher_type' => 'Hóa đơn bán hàng',
                    'voucher_number' => 'HDBH-88',
                    'voucher_date' => '2026-08-10',
                    'total_amount' => 1000000,
                    'description' => 'Tham chiếu HĐ bán 88',
                ],
            ],
            'lines' => [
                [
                    'credit_account' => '131',
                    'amount' => 1000000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/cash/receipts', $payload);
        $receiptId = $response->json('data.id');

        $dupResponse = $this->postJson("/api/v1/cash/receipts/{$receiptId}/duplicate");
        $dupResponse->assertStatus(201);
        $dupId = $dupResponse->json('data.id');

        $dup = CashReceipt::with('references')->find($dupId);
        $this->assertNotNull($dup);
        $this->assertCount(1, $dup->references);
        $this->assertEquals('HDBH-88', $dup->references->first()->target_voucher_number);
    }
}
