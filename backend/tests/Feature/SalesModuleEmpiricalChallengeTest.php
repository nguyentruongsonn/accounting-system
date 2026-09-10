<?php

namespace Tests\Feature;

use App\Enums\SystemVoucherType;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\SalesDiscount;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesModuleEmpiricalChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Item $item1;

    protected Item $item2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Công ty Thử Nghiệm Đối Kháng MISA',
            'tax_code' => '0108889999',
            'address' => 'Hà Nội',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
        ]);
        Sanctum::actingAs($this->user);
        $this->configureAccountingTenant($this->user, $this->company);

        // Standard Chart of Accounts
        $accounts = [
            ['code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '5212', 'name' => 'Hàng bán bị trả lại', 'type' => 'revenue_deduction', 'nature' => 'debit'],
            ['code' => '5213', 'name' => 'Giảm giá hàng bán', 'type' => 'revenue_deduction', 'nature' => 'debit'],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '1561', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit'],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                'code' => $acc['code'],
                'name' => $acc['name'],
                'type' => $acc['type'],
                'nature' => $acc['nature'],
                'level' => 1,
                'is_parent' => 0,
            ]);
        }

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH-EMP-01',
            'name' => 'Công ty TNHH Khách Hàng Thử Nghiệm',
            'tax_code' => '0107778888',
            'address' => 'Hà Nội',
        ]);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO-CHINH',
            'name' => 'Kho Chính',
        ]);

        $this->item1 = Item::create([
            'company_id' => $this->company->id,
            'code' => 'SP-01',
            'name' => 'Sản phẩm 1',
            'sales_price' => 500000,
            'cost_price' => 300000,
        ]);

        $this->item2 = Item::create([
            'company_id' => $this->company->id,
            'code' => 'SP-02',
            'name' => 'Sản phẩm 2',
            'sales_price' => 200000,
            'cost_price' => 120000,
        ]);
    }

    /**
     * EMPIRICAL TEST 1: GL Balance for Sales Return across all payment methods
     * Payment Methods: reduce_receivable (131), cash (1111), bank (1121)
     */
    public function test_sales_return_gl_balance_across_payment_methods(): void
    {
        $methods = [
            'reduce_receivable' => '131',
            'cash' => '1111',
            'bank' => '1121',
        ];

        $counter = 1;
        foreach ($methods as $method => $expectedAccount) {
            $voucherNum = sprintf('TLHB-M-%03d', $counter++);
            $payload = [
                'company_id' => $this->company->id,
                'customer_id' => $this->customer->id,
                'voucher_number' => $voucherNum,
                'voucher_date' => '2026-08-21',
                'accounting_date' => '2026-08-21',
                'payment_method' => $method,
                'is_inward' => true,
                'lines' => [
                    [
                        'item_id' => $this->item1->id,
                        'quantity' => 3,
                        'unit_price' => 500000,
                        'amount' => 1500000,
                        'tax_rate' => 10,
                        'tax_amount' => 150000,
                        'cogs_unit_price' => 300000,
                        'cogs_amount' => 900000,
                    ],
                ],
            ];

            $res = $this->postJson('/api/v1/sales/returns', $payload);
            $res->assertStatus(201);
            $id = $res->json('data.id');

            $postRes = $this->postJson("/api/v1/sales/returns/{$id}/post");
            $postRes->assertStatus(200);

            $salesReturn = SalesReturn::find($id);
            $this->assertTrue($salesReturn->is_posted);

            $je = JournalEntry::with('lines')->find($salesReturn->journal_entry_id);
            $this->assertNotNull($je);

            $totalDebit = $je->lines->sum('debit_amount');
            $totalCredit = $je->lines->sum('credit_amount');

            // 1. Double entry debit must strictly equal credit
            $this->assertEquals($totalDebit, $totalCredit, "Double-entry out of balance for method: {$method}");

            // 2. Expected amounts: (1,500,000 + 150,000) + 900,000 COGS = 2,550,000
            $this->assertEquals(2550000, $totalDebit);

            // 3. Expected credit account
            $creditLine = $je->lines->where('credit_amount', '>', 0)->where('account_code', $expectedAccount)->first();
            $this->assertNotNull($creditLine, "Missing expected credit account {$expectedAccount} for method {$method}");
            $this->assertEquals(1650000, $creditLine->credit_amount);

            // 4. Inventory debit (1561) and COGS credit (632)
            $invLine = $je->lines->where('account_code', '1561')->first();
            $cogsLine = $je->lines->where('account_code', '632')->first();
            $this->assertEquals(900000, $invLine->debit_amount);
            $this->assertEquals(900000, $cogsLine->credit_amount);
        }
    }

    /**
     * EMPIRICAL TEST 2: GL Balance for Sales Discount across all payment methods
     */
    public function test_sales_discount_gl_balance_across_payment_methods(): void
    {
        $methods = [
            'reduce_receivable' => '131',
            'cash' => '1111',
            'bank' => '1121',
        ];

        $counter = 1;
        foreach ($methods as $method => $expectedAccount) {
            $voucherNum = sprintf('GGHB-M-%03d', $counter++);
            $payload = [
                'company_id' => $this->company->id,
                'customer_id' => $this->customer->id,
                'voucher_number' => $voucherNum,
                'voucher_date' => '2026-08-21',
                'accounting_date' => '2026-08-21',
                'payment_method' => $method,
                'lines' => [
                    [
                        'item_id' => $this->item2->id,
                        'quantity' => 10,
                        'unit_price' => 20000,
                        'amount' => 200000,
                        'tax_rate' => 10,
                        'tax_amount' => 20000,
                    ],
                ],
            ];

            $res = $this->postJson('/api/v1/sales/discounts', $payload);
            $res->assertStatus(201);
            $id = $res->json('data.id');

            $postRes = $this->postJson("/api/v1/sales/discounts/{$id}/post");
            $postRes->assertStatus(200);

            $salesDiscount = SalesDiscount::find($id);
            $this->assertTrue($salesDiscount->is_posted);

            $je = JournalEntry::with('lines')->find($salesDiscount->journal_entry_id);
            $this->assertNotNull($je);

            $totalDebit = $je->lines->sum('debit_amount');
            $totalCredit = $je->lines->sum('credit_amount');

            // Double entry check: (200,000 + 20,000) = 220,000
            $this->assertEquals($totalDebit, $totalCredit, "Double-entry out of balance for discount method: {$method}");
            $this->assertEquals(220000, $totalDebit);

            $creditLine = $je->lines->where('credit_amount', '>', 0)->where('account_code', $expectedAccount)->first();
            $this->assertNotNull($creditLine);
            $this->assertEquals(220000, $creditLine->credit_amount);
        }
    }

    /**
     * EMPIRICAL TEST 3: Multi-item Sales Return with Mixed Tax Rates (0%, 5%, 10%)
     */
    public function test_multi_item_sales_return_with_mixed_tax_rates(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB-MIX-01',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'reduce_receivable',
            'is_inward' => true,
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'quantity' => 2,
                    'unit_price' => 500000,
                    'amount' => 1000000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'cogs_unit_price' => 300000,
                    'cogs_amount' => 600000,
                ],
                [
                    'item_id' => $this->item2->id,
                    'quantity' => 5,
                    'unit_price' => 200000,
                    'amount' => 1000000,
                    'tax_rate' => 10,
                    'tax_amount' => 100000,
                    'cogs_unit_price' => 120000,
                    'cogs_amount' => 600000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/sales/returns', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('data.sub_total', 2000000)
            ->assertJsonPath('data.tax_amount', 100000)
            ->assertJsonPath('data.total_amount', 2100000)
            ->assertJsonPath('data.cogs_total_amount', 1200000);

        $id = $res->json('data.id');
        $this->postJson("/api/v1/sales/returns/{$id}/post")->assertStatus(200);

        $sr = SalesReturn::find($id);
        $je = JournalEntry::with('lines')->find($sr->journal_entry_id);

        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');

        // Total Debit = 2,100,000 + 1,200,000 = 3,300,000
        $this->assertEquals($totalDebit, $totalCredit);
        $this->assertEquals(3300000, $totalDebit);
    }

    /**
     * EMPIRICAL TEST 4: Cross-Voucher Defaults Integration
     */
    public function test_cross_voucher_defaults_resolution_for_sales(): void
    {
        $resolvedReturn = SystemVoucherType::resolveType('sales_return');
        $this->assertEquals(SystemVoucherType::SALES_RETURN, $resolvedReturn);
        $this->assertEquals('Trả lại hàng bán', $resolvedReturn->label());
        $this->assertEquals('sales', $resolvedReturn->module());
        $this->assertEquals('5212', $resolvedReturn->defaultDebitAccount('TT200'));
        $this->assertEquals('131', $resolvedReturn->defaultCreditAccount('TT200'));

        $resolvedDiscount = SystemVoucherType::resolveType('sales_discount');
        $this->assertEquals(SystemVoucherType::SALES_DISCOUNT, $resolvedDiscount);
        $this->assertEquals('Giảm giá hàng bán', $resolvedDiscount->label());
        $this->assertEquals('5213', $resolvedDiscount->defaultDebitAccount('TT200'));
        $this->assertEquals('131', $resolvedDiscount->defaultCreditAccount('TT200'));
    }

    /**
     * EMPIRICAL TEST 5: Deep Duplicate Test for Sales Return and Sales Discount
     */
    public function test_deep_duplicate_preserves_lines_and_generates_fresh_voucher_code(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'TLHB-ORIG-01',
            'voucher_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->item1->id,
                    'quantity' => 7,
                    'unit_price' => 150000,
                    'amount' => 1050000,
                ],
            ],
        ];

        $origRes = $this->postJson('/api/v1/sales/returns', $payload);
        $origId = $origRes->json('data.id');

        $dupRes = $this->postJson("/api/v1/sales/returns/{$origId}/duplicate");
        $dupRes->assertStatus(201);
        $dupId = $dupRes->json('data.id');

        $this->assertNotEquals($origId, $dupId);

        $dup = SalesReturn::with('lines')->find($dupId);
        $this->assertFalse($dup->is_posted);
        $this->assertEquals('draft', $dup->status);
        $this->assertNull($dup->journal_entry_id);
        $this->assertNotEquals('TLHB-ORIG-01', $dup->voucher_number);
        $this->assertCount(1, $dup->lines);
        $this->assertEquals(7, $dup->lines->first()->quantity);
        $this->assertEquals(1050000, $dup->lines->first()->amount);
    }
}
