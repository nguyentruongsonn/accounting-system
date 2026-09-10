<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\SalesDiscount;
use App\Models\User;
use App\Services\SalesDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Công ty TNHH Nhựa Tiền Phong',
            'tax_code' => '0109988776',
            'address' => 'Hà Nội',
        ]);

        $this->user->company_id = $this->company->id;
        $this->user->save();
        $this->configureAccountingTenant($this->user, $this->company);

        // Chart of accounts
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1111', 'name' => 'Tiền mặt Việt Nam', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '5213', 'name' => 'Giảm giá hàng bán', 'type' => 'revenue_deduction', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH002',
            'name' => 'Đại lý phân phối Đại Nam',
            'tax_code' => '0105556667',
            'address' => 'Hải Phòng',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'ONG20',
            'name' => 'Ống nhựa uPVC D20',
            'type' => 'inventory',
            'cost_price' => 7000,
            'sales_price' => 10000,
        ]);
    }

    public function test_can_create_sales_discount()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'customer_address' => $this->customer->address,
            'voucher_number' => 'GGHB00001',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'reason' => 'Giảm giá 5% do hàng giao trễ hạn',
            'payment_method' => 'reduce_receivable',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'unit' => 'Mét',
                    'debit_account' => '5213',
                    'credit_account' => '131',
                    'quantity' => 100,
                    'unit_price' => 10000,
                    'amount' => 1000000,
                    'tax_rate' => 10,
                    'tax_amount' => 100000,
                    'tax_account' => '33311',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sales/discounts', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.voucher_number', 'GGHB00001')
            ->assertJsonPath('data.sub_total', 1000000)
            ->assertJsonPath('data.tax_amount', 100000)
            ->assertJsonPath('data.total_amount', 1100000);

        $this->assertDatabaseHas('sales_discounts', [
            'voucher_number' => 'GGHB00001',
            'customer_id' => $this->customer->id,
            'is_posted' => false,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('sales_discount_lines', [
            'item_id' => $this->item->id,
            'amount' => 1000000,
        ]);
    }

    public function test_line_amounts_use_exact_money_arithmetic(): void
    {
        $service = app(SalesDiscountService::class);
        $method = new \ReflectionMethod($service, 'lineAmounts');
        $method->setAccessible(true);

        $amounts = $method->invoke($service, [
            'quantity' => '3.00',
            'unit_price' => '1234.56',
            'tax_rate' => '10.00',
        ]);

        $this->assertSame('3.00', $amounts['quantity']);
        $this->assertSame('1234.56', $amounts['unit_price']);
        $this->assertSame('3703.68', $amounts['amount']);
        $this->assertSame('370.37', $amounts['tax']);
    }

    public function test_validation_fails_on_missing_required_fields()
    {
        $response = $this->postJson('/api/v1/sales/discounts', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id', 'voucher_date', 'lines']);
    }

    public function test_can_post_sales_discount_to_gl_with_balanced_double_entry()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00002',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'reduce_receivable',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 2000000,
                    'tax_rate' => 10,
                    'tax_amount' => 200000,
                    'debit_account' => '5213',
                    'credit_account' => '131',
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/discounts', $payload);
        $discountId = $createResponse->json('data.id');

        $postResponse = $this->postJson("/api/v1/sales/discounts/{$discountId}/post");
        $postResponse->assertStatus(200);

        $salesDiscount = SalesDiscount::find($discountId);
        $this->assertTrue($salesDiscount->is_posted);
        $this->assertEquals('posted', $salesDiscount->status);

        $je = JournalEntry::with('lines')->find($salesDiscount->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertEquals('posted', $je->status);

        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');

        // Total Debit must equal Total Credit (2,200,000 = 2,000,000 + 200,000)
        $this->assertEquals($totalDebit, $totalCredit);
        $this->assertEquals(2200000, $totalDebit);

        $accounts = $je->lines->pluck('account_code')->toArray();
        $this->assertContains('5213', $accounts);
        $this->assertContains('33311', $accounts);
        $this->assertContains('131', $accounts);
    }

    public function test_can_post_sales_discount_with_bank_payment()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00003',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'bank',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 500000,
                    'tax_rate' => 10,
                    'tax_amount' => 50000,
                    'debit_account' => '5213',
                    'credit_account' => '1121',
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/discounts', $payload);
        $discountId = $createResponse->json('data.id');

        $this->postJson("/api/v1/sales/discounts/{$discountId}/post")->assertStatus(200);

        $salesDiscount = SalesDiscount::find($discountId);
        $je = JournalEntry::with('lines')->find($salesDiscount->journal_entry_id);

        $creditLine = $je->lines->where('credit_amount', '>', 0)->first();
        $this->assertEquals('1121', $creditLine->account_code);
        $this->assertEquals(550000, $creditLine->credit_amount);
    }

    public function test_can_unpost_sales_discount()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00004',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 300000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/discounts', $payload);
        $discountId = $createResponse->json('data.id');

        $this->postJson("/api/v1/sales/discounts/{$discountId}/post");

        $unpostResponse = $this->postJson("/api/v1/sales/discounts/{$discountId}/unpost");
        $unpostResponse->assertStatus(200);

        $salesDiscount = SalesDiscount::find($discountId);
        $this->assertFalse($salesDiscount->is_posted);
        $this->assertEquals('draft', $salesDiscount->status);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $salesDiscount->journal_entry_id,
            'status' => 'voided',
        ]);
    }

    public function test_can_void_sales_discount()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00005',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 400000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/discounts', $payload);
        $discountId = $createResponse->json('data.id');

        $this->postJson("/api/v1/sales/discounts/{$discountId}/post");

        $voidResponse = $this->postJson("/api/v1/sales/discounts/{$discountId}/void");
        $voidResponse->assertStatus(200);

        $salesDiscount = SalesDiscount::find($discountId);
        $this->assertFalse($salesDiscount->is_posted);
        $this->assertEquals('voided', $salesDiscount->status);
    }

    public function test_can_duplicate_sales_discount()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00006',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 600000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/discounts', $payload);
        $discountId = $createResponse->json('data.id');

        $dupResponse = $this->postJson("/api/v1/sales/discounts/{$discountId}/duplicate");
        $dupResponse->assertStatus(201);

        $dupId = $dupResponse->json('data.id');
        $this->assertNotEquals($discountId, $dupId);

        $dupDiscount = SalesDiscount::with('lines')->find($dupId);
        $this->assertFalse($dupDiscount->is_posted);
        $this->assertCount(1, $dupDiscount->lines);
    }

    public function test_can_get_next_code()
    {
        $response = $this->getJson('/api/v1/sales/discounts/next-code');
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['code'], 'code']);

        $code = $response->json('code') ?? $response->json('data.code');
        $this->assertStringStartsWith('GGHB', $code);
    }

    public function test_can_update_draft_sales_discount()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00007',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 200000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/discounts', $payload);
        $discountId = $createResponse->json('data.id');

        $updatePayload = [
            'reason' => 'Cập nhật lý do giảm giá',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 350000,
                ],
            ],
        ];

        $updateResponse = $this->putJson("/api/v1/sales/discounts/{$discountId}", $updatePayload);
        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.sub_total', 350000)
            ->assertJsonPath('data.reason', 'Cập nhật lý do giảm giá');
    }

    public function test_cannot_update_posted_sales_discount()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00008',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 100000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/discounts', $payload);
        $discountId = $createResponse->json('data.id');
        $this->postJson("/api/v1/sales/discounts/{$discountId}/post");

        $updateResponse = $this->putJson("/api/v1/sales/discounts/{$discountId}", ['reason' => 'Sửa chứng từ']);
        $updateResponse->assertStatus(409);
    }

    public function test_post_sales_discount_with_cash_payment_creates_balanced_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00009',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'cash',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 800000,
                    'tax_rate' => 10,
                    'tax_amount' => 80000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/sales/discounts', $payload);
        $id = $res->json('data.id');

        $this->postJson("/api/v1/sales/discounts/{$id}/post")->assertStatus(200);

        $salesDiscount = SalesDiscount::find($id);
        $je = JournalEntry::with('lines')->find($salesDiscount->journal_entry_id);

        $this->assertNotNull($je);
        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');
        $this->assertEquals($totalDebit, $totalCredit);
        $this->assertEquals(880000, $totalDebit);

        $creditLine = $je->lines->where('credit_amount', '>', 0)->where('account_code', '1111')->first();
        $this->assertNotNull($creditLine);
        $this->assertEquals(880000, $creditLine->credit_amount);
    }

    public function test_sales_discount_multiple_lines_with_mixed_taxes()
    {
        $item2 = Item::create([
            'company_id' => $this->company->id,
            'code' => 'ONG34',
            'name' => 'Ống nhựa uPVC D34',
            'sales_price' => 20000,
            'cost_price' => 12000,
        ]);

        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00010',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'reduce_receivable',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 500000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                ],
                [
                    'item_id' => $item2->id,
                    'amount' => 300000,
                    'tax_rate' => 10,
                    'tax_amount' => 30000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/sales/discounts', $payload);
        $res->assertStatus(201);
        $id = $res->json('data.id');

        $this->postJson("/api/v1/sales/discounts/{$id}/post")->assertStatus(200);

        $salesDiscount = SalesDiscount::find($id);
        $je = JournalEntry::with('lines')->find($salesDiscount->journal_entry_id);

        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');
        // Total discount = 800,000, Tax = 30,000, Total credit 131 = 830,000
        $this->assertEquals($totalDebit, $totalCredit);
        $this->assertEquals(830000, $totalDebit);
    }

    public function test_sales_discount_full_lifecycle_repost_and_void()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00011',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 250000,
                ],
            ],
        ];

        // 1. Create -> Draft
        $createRes = $this->postJson('/api/v1/sales/discounts', $payload);
        $id = $createRes->json('data.id');
        $this->assertEquals('draft', SalesDiscount::find($id)->status);

        // 2. Post -> Posted
        $this->postJson("/api/v1/sales/discounts/{$id}/post")->assertStatus(200);
        $sd = SalesDiscount::find($id);
        $this->assertEquals('posted', $sd->status);
        $firstJeId = $sd->journal_entry_id;

        // 3. Unpost -> Draft
        $this->postJson("/api/v1/sales/discounts/{$id}/unpost")->assertStatus(200);
        $this->assertEquals('draft', SalesDiscount::find($id)->status);
        $this->assertEquals('voided', JournalEntry::find($firstJeId)->status);

        // 4. Re-Post -> Posted
        $this->postJson("/api/v1/sales/discounts/{$id}/post")->assertStatus(200);
        $sd2 = SalesDiscount::find($id);
        $this->assertEquals('posted', $sd2->status);
        $this->assertNotEquals($firstJeId, $sd2->journal_entry_id);

        // 5. Void -> Voided
        $this->postJson("/api/v1/sales/discounts/{$id}/void")->assertStatus(200);
        $this->assertEquals('voided', SalesDiscount::find($id)->status);
        $this->assertEquals('voided', JournalEntry::find($sd2->journal_entry_id)->status);
    }

    public function test_cannot_create_duplicate_voucher_number()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB_DUP',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 100000,
                ],
            ],
        ];

        $this->postJson('/api/v1/sales/discounts', $payload)->assertStatus(201);
        $res2 = $this->postJson('/api/v1/sales/discounts', $payload);
        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_number']);
    }

    public function test_cannot_post_already_posted_voucher()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00012',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 100000,
                ],
            ],
        ];

        $id = $this->postJson('/api/v1/sales/discounts', $payload)->json('data.id');
        $this->postJson("/api/v1/sales/discounts/{$id}/post")->assertStatus(200);
        $res = $this->postJson("/api/v1/sales/discounts/{$id}/post");
        $res->assertStatus(400);
    }

    public function test_cannot_unpost_unposted_voucher()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00013',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 100000,
                ],
            ],
        ];

        $id = $this->postJson('/api/v1/sales/discounts', $payload)->json('data.id');
        $res = $this->postJson("/api/v1/sales/discounts/{$id}/unpost");
        $res->assertStatus(400);
    }

    public function test_can_delete_draft_sales_discount()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB00014',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 100000,
                ],
            ],
        ];

        $id = $this->postJson('/api/v1/sales/discounts', $payload)->json('data.id');
        $this->deleteJson("/api/v1/sales/discounts/{$id}")->assertStatus(200);
        $this->assertNull(SalesDiscount::find($id));
    }

    public function test_posted_sales_discount_cannot_be_updated_or_deleted(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'GGHB-POSTED-MUTATION',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'amount' => 100000,
                ],
            ],
        ];

        $id = $this->postJson('/api/v1/sales/discounts', $payload)->json('data.id');
        SalesDiscount::findOrFail($id)->forceFill([
            'is_posted' => true,
            'status' => 'posted',
        ])->save();

        $this->putJson("/api/v1/sales/discounts/{$id}", ['reason' => 'Không được thay đổi'])
            ->assertStatus(409);
        $this->deleteJson("/api/v1/sales/discounts/{$id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('sales_discounts', [
            'id' => $id,
            'is_posted' => 1,
            'status' => 'posted',
        ]);
    }
}
