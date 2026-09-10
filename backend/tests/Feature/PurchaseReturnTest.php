<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Item;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $company;

    protected $supplier;

    protected $item;

    protected $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Công ty Cổ phần Mua Hàng Test',
            'tax_code' => '0101234567',
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
            'code' => 'NCC001',
            'name' => 'Công ty Cổ phần Thép Hòa Phát',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'THEP-PHI-18',
            'name' => 'Thép phi 18 Hòa Phát',
            'type' => 'inventory',
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)
            ->where('code', 'KHO_HH')
            ->first() ?? Warehouse::create([
                'company_id' => $this->company->id,
                'code' => 'KHO_TEST_'.rand(1000, 9999),
                'name' => 'Kho hàng hóa',
                'default_account' => '1561',
            ]);
    }

    public function test_can_create_purchase_return(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'voucher_number' => 'TLMH00001',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'reason' => 'Trả lại thép do lỗi kỹ thuật',
            'payment_method' => 'reduce_payable',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'warehouse_id' => $this->warehouse->id,
                    'debit_account' => '331',
                    'credit_account' => '1561',
                    'quantity' => 10,
                    'unit_price' => 100000,
                    'amount' => 1000000,
                    'tax_rate' => 10,
                    'tax_amount' => 100000,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/returns', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('voucher_number', 'TLMH00001')
            ->assertJsonPath('total_amount', 1100000);

        $this->assertDatabaseHas('purchase_returns', [
            'voucher_number' => 'TLMH00001',
            'supplier_id' => $this->supplier->id,
            'total_amount' => 1100000,
        ]);

        $this->assertDatabaseHas('purchase_return_lines', [
            'item_id' => $this->item->id,
            'quantity' => 10,
            'amount' => 1000000,
        ]);
    }

    public function test_line_amounts_use_exact_money_arithmetic(): void
    {
        $service = app(PurchaseReturnService::class);
        $method = new \ReflectionMethod($service, 'lineAmounts');
        $method->setAccessible(true);

        $amounts = $method->invoke($service, [
            'quantity' => '3.00',
            'unit_price' => '1234.56',
            'discount_rate' => '1.00',
            'tax_rate' => '10.00',
        ]);

        $this->assertSame('3.00', $amounts['quantity']);
        $this->assertSame('1234.56', $amounts['unit_price']);
        $this->assertSame('3703.68', $amounts['amount']);
        $this->assertSame('37.04', $amounts['discount']);
        $this->assertSame('366.66', $amounts['tax']);
    }

    public function test_can_post_purchase_return_to_gl_with_strict_double_entry_balance(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'TLMH00002',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'payment_method' => 'reduce_payable',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '331',
                    'credit_account' => '1561',
                    'quantity' => 20,
                    'unit_price' => 50000,
                    'amount' => 1000000,
                    'discount_amount' => 100000,
                    'tax_rate' => 10,
                    'tax_amount' => 90000, // 10% of 900,000 net
                    'tax_account' => '1331',
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/purchase/returns', $payload);
        $createRes->assertStatus(201);
        $returnId = $createRes->json('id');

        $postRes = $this->postJson("/api/v1/purchase/returns/{$returnId}/post");
        $postRes->assertStatus(200);

        $purchaseReturn = PurchaseReturn::with('journalEntry.lines')->find($returnId);
        $this->assertTrue($purchaseReturn->is_posted);
        $this->assertNotNull($purchaseReturn->journal_entry_id);

        $je = $purchaseReturn->journalEntry;
        $this->assertEquals('posted', $je->status);

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        // Total amount = (1,000,000 - 100,000) + 90,000 = 990,000
        $this->assertEquals(990000, $sumDebit);
        $this->assertEquals(990000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit, 'Sum Debit must strictly equal Sum Credit');
    }

    public function test_production_account_mapping_gate_blocks_purchase_return_posting(): void
    {
        config(['accounting.enforce_return_discount_posting_account_mappings' => true]);

        $createRes = $this->postJson('/api/v1/purchase/returns', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            // Keep the unique voucher fixture isolated from retained test data.
            'voucher_number' => 'TLMH-GATE-'.(string) Str::uuid(),
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'payment_method' => 'reduce_payable',
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'unit_price' => 100,
                'amount' => 100,
                'tax_amount' => 0,
                // Draft intake now requires explicit source account evidence
                // when the production return/discount mapping gate is on.
                'debit_account' => '331',
                'credit_account' => '1561',
            ]],
        ]);
        $createRes->assertStatus(201);
        $returnId = (int) $createRes->json('id');

        try {
            app(PurchaseReturnService::class)->post($returnId);
            $this->fail('Purchase-return posting must fail closed without approved account mappings.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('mapping tài khoản', $exception->getMessage());
        }

        $this->assertDatabaseHas('purchase_returns', [
            'id' => $returnId,
            'is_posted' => false,
        ]);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_can_post_purchase_return_with_cash_refund(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'TLMH00003',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'cash',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '1111',
                    'credit_account' => '1561',
                    'quantity' => 5,
                    'unit_price' => 200000,
                    'amount' => 1000000,
                    'tax_rate' => 10,
                    'tax_amount' => 100000,
                ],
            ],
        ];

        $createRes = $this->postJson('/api/v1/purchase/returns', $payload);
        $returnId = $createRes->json('id');

        $this->postJson("/api/v1/purchase/returns/{$returnId}/post")->assertStatus(200);

        $purchaseReturn = PurchaseReturn::with('journalEntry.lines')->find($returnId);
        $debitLine = $purchaseReturn->journalEntry->lines->firstWhere('account_code', '1111');
        $this->assertNotNull($debitLine);
        $this->assertEquals(1100000, $debitLine->debit_amount);
    }

    public function test_can_unpost_and_duplicate_purchase_return(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'TLMH00004',
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

        $createRes = $this->postJson('/api/v1/purchase/returns', $payload);
        $returnId = $createRes->json('id');

        // Post
        $this->postJson("/api/v1/purchase/returns/{$returnId}/post")->assertStatus(200);
        $this->assertTrue(PurchaseReturn::find($returnId)->is_posted);

        // Unpost
        $this->postJson("/api/v1/purchase/returns/{$returnId}/unpost")->assertStatus(200);
        $this->assertFalse(PurchaseReturn::find($returnId)->is_posted);

        // Duplicate
        $dupRes = $this->postJson("/api/v1/purchase/returns/{$returnId}/duplicate");
        $dupRes->assertStatus(201);
        $dupId = $dupRes->json('id');
        $this->assertNotEquals($returnId, $dupId);
        $this->assertFalse($dupRes->json('is_posted'));
    }

    public function test_can_get_next_code(): void
    {
        $res = $this->getJson('/api/v1/purchase/returns/next-code');
        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('code'));
    }

    public function test_can_search_in_voucher_reference_controller(): void
    {
        $ret = PurchaseReturn::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'voucher_number' => 'TLMH-REF-01',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'total_amount' => 5000000,
            'status' => 'draft',
        ]);

        $res = $this->getJson('/api/v1/voucher-references/search?module_group=purchase&keyword=TLMH-REF-01');
        $res->assertStatus(200);
        $this->assertTrue(collect($res->json('data'))->contains('voucher_number', 'TLMH-REF-01'));
    }

    public function test_posted_purchase_return_cannot_be_updated_or_deleted(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'voucher_number' => 'TLMH-POSTED-MUTATION',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'reason' => 'Trả lại hàng mua',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'item_code' => $this->item->code,
                    'item_name' => $this->item->name,
                    'warehouse_id' => $this->warehouse->id,
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

        $id = $this->postJson('/api/v1/purchase/returns', $payload)->json('data.id');
        PurchaseReturn::findOrFail($id)->forceFill([
            'is_posted' => true,
            'status' => 'posted',
        ])->save();

        $this->putJson("/api/v1/purchase/returns/{$id}", ['reason' => 'Không được thay đổi'])
            ->assertStatus(409);
        $this->deleteJson("/api/v1/purchase/returns/{$id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('purchase_returns', [
            'id' => $id,
            'is_posted' => 1,
            'status' => 'posted',
        ]);
    }
}
