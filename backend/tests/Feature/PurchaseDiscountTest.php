<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Item;
use App\Models\PurchaseDiscount;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $company;

    protected $supplier;

    protected $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Công ty Cổ phần Giảm Giá Test',
            'tax_code' => '0109998888',
            'address' => 'Hà Nội, Việt Nam',
        ]);

        $this->user->company_id = $this->company->id;
        $this->user->save();
        $this->configureAccountingTenant($this->user, $this->company);

        // Chart of accounts for VAS TT200
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1561', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '331', 'name' => 'Phải trả người bán', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1331', 'name' => 'Thuế GTGT được khấu trừ', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC002',
            'name' => 'Công ty TNHH Nhựa Tiền Phong',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'ONG-NHUA-PPR',
            'name' => 'Ống nhựa PPR D25',
            'type' => 'inventory',
        ]);
    }

    public function test_can_create_purchase_discount(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'voucher_number' => 'GGMH00001',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'reason' => 'Chiết khấu thương mại do đạt doanh số quý 2',
            'payment_method' => 'reduce_payable',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'debit_account' => '331',
                    'credit_account' => '1561',
                    'quantity' => 100,
                    'unit_price' => 50000,
                    'amount' => 500000, // discount amount
                    'tax_rate' => 10,
                    'tax_amount' => 50000,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/discounts', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('voucher_number', 'GGMH00001')
            ->assertJsonPath('total_amount', 550000);

        $this->assertDatabaseHas('purchase_discounts', [
            'voucher_number' => 'GGMH00001',
            'supplier_id' => $this->supplier->id,
            'total_amount' => 550000,
        ]);

        $this->assertDatabaseHas('purchase_discount_lines', [
            'item_id' => $this->item->id,
            'amount' => 500000,
        ]);
    }

    public function test_line_amounts_use_exact_money_arithmetic(): void
    {
        $service = app(PurchaseDiscountService::class);
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

    public function test_can_post_purchase_discount_to_gl_with_strict_double_entry_balance(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'GGMH00002',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'payment_method' => 'reduce_payable',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '331',
                    'credit_account' => '1561',
                    'quantity' => 200,
                    'unit_price' => 10000,
                    'amount' => 2000000,
                    'tax_rate' => 10,
                    'tax_amount' => 200000,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/purchase/discounts', $payload);
        $createRes->assertStatus(201);
        $discountId = $createRes->json('id');

        $postRes = $this->postJson("/api/v1/purchase/discounts/{$discountId}/post");
        $postRes->assertStatus(200);

        $purchaseDiscount = PurchaseDiscount::with('journalEntry.lines')->find($discountId);
        $this->assertTrue($purchaseDiscount->is_posted);
        $this->assertNotNull($purchaseDiscount->journal_entry_id);

        $je = $purchaseDiscount->journalEntry;
        $this->assertEquals('posted', $je->status);

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        // Total amount = 2,000,000 + 200,000 = 2,200,000
        $this->assertEquals(2200000, $sumDebit);
        $this->assertEquals(2200000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit, 'Sum Debit must strictly equal Sum Credit');
    }

    public function test_can_post_purchase_discount_with_bank_refund(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'GGMH00003',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'bank',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '1121',
                    'credit_account' => '1561',
                    'quantity' => 1,
                    'unit_price' => 3000000,
                    'amount' => 3000000,
                    'tax_rate' => 10,
                    'tax_amount' => 300000,
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/purchase/discounts', $payload);
        $discountId = $createRes->json('id');

        $this->postJson("/api/v1/purchase/discounts/{$discountId}/post")->assertStatus(200);

        $purchaseDiscount = PurchaseDiscount::with('journalEntry.lines')->find($discountId);
        $debitLine = $purchaseDiscount->journalEntry->lines->firstWhere('account_code', '1121');
        $this->assertNotNull($debitLine);
        $this->assertEquals(3300000, $debitLine->debit_amount);
    }

    public function test_can_unpost_and_duplicate_purchase_discount(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'GGMH00004',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'quantity' => 10,
                    'unit_price' => 1000,
                    'amount' => 10000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/purchase/discounts', $payload);
        $discountId = $createRes->json('id');

        // Post
        $this->postJson("/api/v1/purchase/discounts/{$discountId}/post")->assertStatus(200);
        $this->assertTrue(PurchaseDiscount::find($discountId)->is_posted);

        // Unpost
        $this->postJson("/api/v1/purchase/discounts/{$discountId}/unpost")->assertStatus(200);
        $this->assertFalse(PurchaseDiscount::find($discountId)->is_posted);

        // Duplicate
        $dupRes = $this->postJson("/api/v1/purchase/discounts/{$discountId}/duplicate");
        $dupRes->assertStatus(201);
        $dupId = $dupRes->json('id');
        $this->assertNotEquals($discountId, $dupId);
        $this->assertFalse($dupRes->json('is_posted'));
    }

    public function test_can_get_next_code(): void
    {
        $res = $this->getJson('/api/v1/purchase/discounts/next-code');
        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('code'));
    }

    public function test_can_search_in_voucher_reference_controller(): void
    {
        $disc = PurchaseDiscount::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'voucher_number' => 'GGMH-REF-01',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'total_amount' => 4000000,
            'status' => 'draft',
        ]);

        $res = $this->getJson('/api/v1/voucher-references/search?module_group=purchase&keyword=GGMH-REF-01');
        $res->assertStatus(200);
        $this->assertTrue(collect($res->json('data'))->contains('voucher_number', 'GGMH-REF-01'));
    }

    public function test_posted_purchase_discount_cannot_be_updated_or_deleted(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'voucher_number' => 'GGMH-POSTED-MUTATION',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'reason' => 'Giảm giá hàng mua',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'debit_account' => '331',
                    'credit_account' => '1561',
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'amount' => 100000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $id = $this->postJson('/api/v1/purchase/discounts', $payload)->json('data.id');
        PurchaseDiscount::findOrFail($id)->forceFill([
            'is_posted' => true,
            'status' => 'posted',
        ])->save();

        $this->putJson("/api/v1/purchase/discounts/{$id}", ['reason' => 'Không được thay đổi'])
            ->assertStatus(409);
        $this->deleteJson("/api/v1/purchase/discounts/{$id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('purchase_discounts', [
            'id' => $id,
            'is_posted' => 1,
            'status' => 'posted',
        ]);
    }
}
