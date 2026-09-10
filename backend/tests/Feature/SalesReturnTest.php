<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesReturnTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Công ty Cổ phần Thép Việt',
            'tax_code' => '0101234567',
            'address' => 'Hà Nội',
        ]);

        $this->user->company_id = $this->company->id;
        $this->user->save();

        FiscalYear::create([
            'company_id' => $this->company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        // Chart of accounts
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1111', 'name' => 'Tiền mặt Việt Nam', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '5212', 'name' => 'Hàng bán bị trả lại', 'type' => 'revenue_deduction', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1561', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH001',
            'name' => 'Công ty TNHH Minh Long',
            'tax_code' => '0309998887',
            'address' => 'TP Hồ Chí Minh',
        ]);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO-TONG',
            'name' => 'Kho Tổng',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'VT001',
            'name' => 'Gạch Ceramic 60x60',
            'type' => 'inventory',
            'cost_price' => 100000,
            'sales_price' => 150000,
        ]);
    }

    public function test_can_create_sales_return()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'customer_address' => $this->customer->address,
            'voucher_number' => 'TLHB00001',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'reason' => 'Khách trả lại do lỗi màu',
            'payment_method' => 'reduce_receivable',
            'is_inward' => true,
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'unit' => 'Hộp',
                    'warehouse_id' => $this->warehouse->id,
                    'debit_account' => '5212',
                    'credit_account' => '131',
                    'quantity' => 10,
                    'unit_price' => 150000,
                    'amount' => 1500000,
                    'tax_rate' => 10,
                    'tax_amount' => 150000,
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_unit_price' => 100000,
                    'cogs_amount' => 1000000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sales/returns', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.voucher_number', 'TLHB00001')
            ->assertJsonPath('data.sub_total', 1500000)
            ->assertJsonPath('data.tax_amount', 150000)
            ->assertJsonPath('data.total_amount', 1650000)
            ->assertJsonPath('data.cogs_total_amount', 1000000);

        $this->assertDatabaseHas('sales_returns', [
            'voucher_number' => 'TLHB00001',
            'customer_id' => $this->customer->id,
            'is_posted' => false,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('sales_return_lines', [
            'item_id' => $this->item->id,
            'quantity' => 10,
            'unit_price' => 150000,
            'amount' => 1500000,
        ]);
    }

    public function test_line_amounts_use_exact_money_arithmetic(): void
    {
        $service = app(SalesReturnService::class);
        $method = new \ReflectionMethod($service, 'lineAmounts');
        $method->setAccessible(true);

        $amounts = $method->invoke($service, [
            'quantity' => '3.00',
            'unit_price' => '1234.56',
            'tax_rate' => '10.00',
            'cogs_unit_price' => '100.00',
        ]);

        $this->assertSame('3.00', $amounts['quantity']);
        $this->assertSame('1234.56', $amounts['unit_price']);
        $this->assertSame('3703.68', $amounts['amount']);
        $this->assertSame('370.37', $amounts['tax']);
        $this->assertSame('100.00', $amounts['cogs_unit_price']);
        $this->assertSame('300.00', $amounts['cogs_amount']);
    }

    public function test_validation_fails_on_missing_required_fields()
    {
        $response = $this->postJson('/api/v1/sales/returns', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id', 'voucher_date', 'lines']);
    }

    public function test_can_post_sales_return_to_gl_with_balanced_double_entry()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00002',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'payment_method' => 'reduce_receivable',
            'is_inward' => true,
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 20,
                    'unit_price' => 150000,
                    'amount' => 3000000,
                    'tax_rate' => 10,
                    'tax_amount' => 300000,
                    'debit_account' => '5212',
                    'credit_account' => '131',
                    'cogs_unit_price' => 100000,
                    'cogs_amount' => 2000000,
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/returns', $payload);
        $returnId = $createResponse->json('data.id');

        $postResponse = $this->postJson("/api/v1/sales/returns/{$returnId}/post");
        $postResponse->assertStatus(200);

        $salesReturn = SalesReturn::find($returnId);
        $this->assertTrue($salesReturn->is_posted);
        $this->assertEquals('posted', $salesReturn->status);
        $this->assertNotNull($salesReturn->journal_entry_id);

        $je = JournalEntry::with('lines')->find($salesReturn->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertEquals('posted', $je->status);

        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');

        // Total Debit must equal Total Credit
        $this->assertEquals($totalDebit, $totalCredit);
        $this->assertEquals(5300000, $totalDebit); // (3,000,000 + 300,000) + 2,000,000 cogs

        // Verify account codes in entries
        $accounts = $je->lines->pluck('account_code')->toArray();
        $this->assertContains('5212', $accounts);
        $this->assertContains('33311', $accounts);
        $this->assertContains('131', $accounts);
        $this->assertContains('1561', $accounts);
        $this->assertContains('632', $accounts);
    }

    public function test_can_post_sales_return_with_cash_payment()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00003',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'cash',
            'is_inward' => false,
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 5,
                    'unit_price' => 100000,
                    'amount' => 500000,
                    'tax_rate' => 10,
                    'tax_amount' => 50000,
                    'debit_account' => '5212',
                    'credit_account' => '1111',
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/returns', $payload);
        $returnId = $createResponse->json('data.id');

        $this->postJson("/api/v1/sales/returns/{$returnId}/post")->assertStatus(200);

        $salesReturn = SalesReturn::find($returnId);
        $je = JournalEntry::with('lines')->find($salesReturn->journal_entry_id);

        $creditLine = $je->lines->where('credit_amount', '>', 0)->first();
        $this->assertEquals('1111', $creditLine->account_code);
        $this->assertEquals(550000, $creditLine->credit_amount);
    }

    public function test_can_unpost_sales_return()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00004',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 2,
                    'unit_price' => 100000,
                    'amount' => 200000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/returns', $payload);
        $returnId = $createResponse->json('data.id');

        $this->postJson("/api/v1/sales/returns/{$returnId}/post");

        $unpostResponse = $this->postJson("/api/v1/sales/returns/{$returnId}/unpost");
        $unpostResponse->assertStatus(200);

        $salesReturn = SalesReturn::find($returnId);
        $this->assertFalse($salesReturn->is_posted);
        $this->assertEquals('draft', $salesReturn->status);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $salesReturn->journal_entry_id,
            'status' => 'voided',
        ]);
    }

    public function test_can_void_sales_return()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00005',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/returns', $payload);
        $returnId = $createResponse->json('data.id');

        $this->postJson("/api/v1/sales/returns/{$returnId}/post");

        $voidResponse = $this->postJson("/api/v1/sales/returns/{$returnId}/void");
        $voidResponse->assertStatus(200);

        $salesReturn = SalesReturn::find($returnId);
        $this->assertFalse($salesReturn->is_posted);
        $this->assertEquals('voided', $salesReturn->status);
    }

    public function test_can_duplicate_sales_return()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00006',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 3,
                    'unit_price' => 100000,
                    'amount' => 300000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/returns', $payload);
        $returnId = $createResponse->json('data.id');

        $dupResponse = $this->postJson("/api/v1/sales/returns/{$returnId}/duplicate");
        $dupResponse->assertStatus(201);

        $dupId = $dupResponse->json('data.id');
        $this->assertNotEquals($returnId, $dupId);

        $dupReturn = SalesReturn::with('lines')->find($dupId);
        $this->assertFalse($dupReturn->is_posted);
        $this->assertCount(1, $dupReturn->lines);
        $this->assertEquals(3, $dupReturn->lines->first()->quantity);
    }

    public function test_can_get_next_code()
    {
        $response = $this->getJson('/api/v1/sales/returns/next-code');
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['code'], 'code']);

        $code = $response->json('code') ?? $response->json('data.code');
        $this->assertStringStartsWith('TLHB', $code);
    }

    public function test_can_update_draft_sales_return()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00007',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 2,
                    'unit_price' => 100000,
                    'amount' => 200000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/returns', $payload);
        $returnId = $createResponse->json('data.id');

        $updatePayload = [
            'reason' => 'Đổi lý do trả hàng',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 5,
                    'unit_price' => 100000,
                    'amount' => 500000,
                ],
            ],
        ];

        $updateResponse = $this->putJson("/api/v1/sales/returns/{$returnId}", $updatePayload);
        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.sub_total', 500000)
            ->assertJsonPath('data.reason', 'Đổi lý do trả hàng');
    }

    public function test_cannot_update_posted_sales_return()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00008',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                ],
            ],
        ];

        $createResponse = $this->postJson('/api/v1/sales/returns', $payload);
        $returnId = $createResponse->json('data.id');
        $this->postJson("/api/v1/sales/returns/{$returnId}/post");

        $updateResponse = $this->putJson("/api/v1/sales/returns/{$returnId}", ['reason' => 'Sửa chứng từ']);
        $updateResponse->assertStatus(409);
    }

    public function test_post_sales_return_with_bank_payment_creates_balanced_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00009',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'bank',
            'is_inward' => true,
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 4,
                    'unit_price' => 200000,
                    'amount' => 800000,
                    'tax_rate' => 10,
                    'tax_amount' => 80000,
                    'cogs_unit_price' => 120000,
                    'cogs_amount' => 480000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/sales/returns', $payload);
        $id = $res->json('data.id');

        $this->postJson("/api/v1/sales/returns/{$id}/post")->assertStatus(200);

        $salesReturn = SalesReturn::find($id);
        $je = JournalEntry::with('lines')->find($salesReturn->journal_entry_id);

        $this->assertNotNull($je);
        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');
        $this->assertEquals($totalDebit, $totalCredit);
        $this->assertEquals(1360000, $totalDebit); // (800k + 80k) + 480k

        $creditLine = $je->lines->where('credit_amount', '>', 0)->where('account_code', '1121')->first();
        $this->assertNotNull($creditLine);
        $this->assertEquals(880000, $creditLine->credit_amount);
    }

    public function test_sales_return_without_inward_inventory_omits_cogs_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00010',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'reduce_receivable',
            'is_inward' => false,
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 2,
                    'unit_price' => 100000,
                    'amount' => 200000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'cogs_unit_price' => 60000,
                    'cogs_amount' => 120000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/sales/returns', $payload);
        $id = $res->json('data.id');
        $this->postJson("/api/v1/sales/returns/{$id}/post")->assertStatus(200);

        $salesReturn = SalesReturn::find($id);
        $je = JournalEntry::with('lines')->find($salesReturn->journal_entry_id);

        $accounts = $je->lines->pluck('account_code')->toArray();
        $this->assertNotContains('1561', $accounts);
        $this->assertNotContains('632', $accounts);

        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');
        $this->assertEquals($totalDebit, $totalCredit);
        $this->assertEquals(200000, $totalDebit);
    }

    public function test_sales_return_multiple_lines_with_zero_and_standard_tax()
    {
        $item2 = Item::create([
            'company_id' => $this->company->id,
            'code' => 'VT002',
            'name' => 'Vật tư 2',
            'sales_price' => 50000,
            'cost_price' => 30000,
        ]);

        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00011',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'reduce_receivable',
            'is_inward' => true,
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 5,
                    'unit_price' => 100000,
                    'amount' => 500000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'cogs_unit_price' => 70000,
                    'cogs_amount' => 350000,
                ],
                [
                    'item_id' => $item2->id,
                    'quantity' => 10,
                    'unit_price' => 50000,
                    'amount' => 500000,
                    'tax_rate' => 10,
                    'tax_amount' => 50000,
                    'cogs_unit_price' => 30000,
                    'cogs_amount' => 300000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/sales/returns', $payload);
        $res->assertStatus(201);
        $id = $res->json('data.id');

        $this->postJson("/api/v1/sales/returns/{$id}/post")->assertStatus(200);

        $salesReturn = SalesReturn::find($id);
        $je = JournalEntry::with('lines')->find($salesReturn->journal_entry_id);

        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');
        // Total revenue = 1,000,000, Tax = 50,000, Total credit 131 = 1,050,000, COGS = 650,000
        // Total debit = 1,050,000 + 650,000 = 1,700,000
        $this->assertEquals($totalDebit, $totalCredit);
        $this->assertEquals(1700000, $totalDebit);
    }

    public function test_sales_return_full_lifecycle_repost_and_void()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00012',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                ],
            ],
        ];

        // 1. Create -> Draft
        $createRes = $this->postJson('/api/v1/sales/returns', $payload);
        $id = $createRes->json('data.id');
        $this->assertEquals('draft', SalesReturn::find($id)->status);

        // 2. Post -> Posted
        $this->postJson("/api/v1/sales/returns/{$id}/post")->assertStatus(200);
        $sr = SalesReturn::find($id);
        $this->assertEquals('posted', $sr->status);
        $firstJeId = $sr->journal_entry_id;

        // 3. Unpost -> Draft
        $this->postJson("/api/v1/sales/returns/{$id}/unpost")->assertStatus(200);
        $this->assertEquals('draft', SalesReturn::find($id)->status);
        $this->assertEquals('voided', JournalEntry::find($firstJeId)->status);

        // 4. Re-Post -> Posted
        $this->postJson("/api/v1/sales/returns/{$id}/post")->assertStatus(200);
        $sr2 = SalesReturn::find($id);
        $this->assertEquals('posted', $sr2->status);
        $this->assertNotEquals($firstJeId, $sr2->journal_entry_id);

        // 5. Void -> Voided
        $this->postJson("/api/v1/sales/returns/{$id}/void")->assertStatus(200);
        $this->assertEquals('voided', SalesReturn::find($id)->status);
        $this->assertEquals('voided', JournalEntry::find($sr2->journal_entry_id)->status);
    }

    public function test_cannot_create_duplicate_voucher_number()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB_DUP',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                ],
            ],
        ];

        $this->postJson('/api/v1/sales/returns', $payload)->assertStatus(201);
        $res2 = $this->postJson('/api/v1/sales/returns', $payload);
        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_number']);
    }

    public function test_cannot_post_already_posted_voucher()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00013',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                ],
            ],
        ];

        $id = $this->postJson('/api/v1/sales/returns', $payload)->json('data.id');
        $this->postJson("/api/v1/sales/returns/{$id}/post")->assertStatus(200);
        $res = $this->postJson("/api/v1/sales/returns/{$id}/post");
        $res->assertStatus(400);
    }

    public function test_cannot_unpost_unposted_voucher()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00014',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                ],
            ],
        ];

        $id = $this->postJson('/api/v1/sales/returns', $payload)->json('data.id');
        $res = $this->postJson("/api/v1/sales/returns/{$id}/unpost");
        $res->assertStatus(400);
    }

    public function test_can_delete_draft_sales_return()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB00015',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                ],
            ],
        ];

        $id = $this->postJson('/api/v1/sales/returns', $payload)->json('data.id');
        $this->deleteJson("/api/v1/sales/returns/{$id}")->assertStatus(200);
        $this->assertNull(SalesReturn::find($id));
    }

    public function test_posted_sales_return_cannot_be_updated_or_deleted(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB-POSTED-MUTATION',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                ],
            ],
        ];

        $id = $this->postJson('/api/v1/sales/returns', $payload)->json('data.id');
        SalesReturn::findOrFail($id)->forceFill([
            'is_posted' => true,
            'status' => 'posted',
        ])->save();

        $this->putJson("/api/v1/sales/returns/{$id}", ['reason' => 'Không được thay đổi'])
            ->assertStatus(409);
        $this->deleteJson("/api/v1/sales/returns/{$id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('sales_returns', [
            'id' => $id,
            'is_posted' => 1,
            'status' => 'posted',
        ]);
    }
}
