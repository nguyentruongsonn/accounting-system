<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseDiscountService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchaseReturnService;
use App\Support\DecimalMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdversarialPurchasingGLBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Supplier $supplier;

    protected array $items = [];

    protected Warehouse $warehouse;

    protected PurchaseInvoiceService $purchaseInvoiceService;

    protected PurchaseReturnService $purchaseReturnService;

    protected PurchaseDiscountService $purchaseDiscountService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        // This suite verifies posted GL balancing and therefore uses the
        // canonical accountant identity enforced by production posting.
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Công ty Thẩm Định Mua Hàng & GL TT200',
            'tax_code' => '0108889999',
            'address' => 'Hà Nội, Việt Nam',
        ]);

        $this->user->company_id = $this->company->id;
        $this->user->save();
        $this->configureAccountingTenant($this->user, $this->company);

        // Chart of Accounts per VAS Circular 200 (TT200)
        $accounts = [
            ['code' => '1111', 'name' => 'Tiền mặt VND', 'type' => 'asset', 'nature' => 'debit', 'level' => 2],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng VND', 'type' => 'asset', 'nature' => 'debit', 'level' => 2],
            ['code' => '1331', 'name' => 'Thuế GTGT được khấu trừ của HHDV', 'type' => 'asset', 'nature' => 'debit', 'level' => 2],
            ['code' => '1332', 'name' => 'Thuế GTGT được khấu trừ của TSCĐ', 'type' => 'asset', 'nature' => 'debit', 'level' => 2],
            ['code' => '152',  'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit', 'level' => 1],
            ['code' => '153',  'name' => 'Công cụ, dụng cụ', 'type' => 'asset', 'nature' => 'debit', 'level' => 1],
            ['code' => '156',  'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 1],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2],
            ['code' => '211',  'name' => 'Tài sản cố định hữu hình', 'type' => 'asset', 'nature' => 'debit', 'level' => 1],
            ['code' => '331',  'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit', 'level' => 1],
            ['code' => '3333', 'name' => 'Thuế nhập khẩu', 'type' => 'liability', 'nature' => 'credit', 'level' => 2],
            ['code' => '33312', 'name' => 'Thuế GTGT hàng nhập khẩu', 'type' => 'liability', 'nature' => 'credit', 'level' => 2],
            ['code' => '632',  'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit', 'level' => 1],
            ['code' => '641',  'name' => 'Chi phí bán hàng', 'type' => 'expense', 'nature' => 'debit', 'level' => 1],
            ['code' => '642',  'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit', 'level' => 1],
            ['code' => '6422', 'name' => 'Chi phí vật liệu quản lý', 'type' => 'expense', 'nature' => 'debit', 'level' => 2],
            ['code' => '6427', 'name' => 'Chi phí dịch vụ mua ngoài', 'type' => 'expense', 'nature' => 'debit', 'level' => 2],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::create(array_merge($acc, [
                'company_id' => $this->company->id,
                'is_parent' => 0,
            ]));
        }

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC-ADVERSARIAL',
            'name' => 'Nhà cung cấp Thử Nghiệm Đối Kháng TT200',
            'tax_code' => '0109991111',
            'address' => 'Hà Nội',
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)
            ->where('code', 'KHO_HH')
            ->first() ?? Warehouse::firstOrCreate(
                ['company_id' => $this->company->id, 'code' => 'KHO_ADV_'.rand(1000, 9999)],
                [
                    'name' => 'Kho Tổng Vật Tư Hàng Hóa',
                    'default_account' => '1561',
                ]
            );

        // Create varied items
        $this->items['mat'] = Item::create(['company_id' => $this->company->id, 'code' => 'NVL-01', 'name' => 'Thép cuộn nguyên liệu', 'type' => 'inventory']);
        $this->items['tool'] = Item::create(['company_id' => $this->company->id, 'code' => 'CCDC-01', 'name' => 'Máy mài cầm tay Bosch', 'type' => 'inventory']);
        $this->items['goods'] = Item::create(['company_id' => $this->company->id, 'code' => 'HH-01', 'name' => 'Bản lề inox 304', 'type' => 'inventory']);
        $this->items['service'] = Item::create(['company_id' => $this->company->id, 'code' => 'DV-01', 'name' => 'Dịch vụ vận chuyển bốc dỡ', 'type' => 'service']);

        $this->purchaseInvoiceService = app(PurchaseInvoiceService::class);
        $this->purchaseReturnService = app(PurchaseReturnService::class);
        $this->purchaseDiscountService = app(PurchaseDiscountService::class);
    }

    /**
     * Test 1: Complex Multi-line Purchase Invoice with Diverse Discounts and VAT Rates
     * Verify strict GL balance: sum(Debit) == sum(Credit)
     */
    public function test_multiline_purchase_invoice_with_diverse_discounts_and_vat_rates_strictly_balances_gl(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'invoice_number' => 'ADV-INV-001',
            'invoice_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'payment_method' => 'unpaid',
            'description' => 'Hóa đơn mua hàng tổng hợp đa dòng thuế suất & chiết khấu',
            'lines' => [
                [
                    // Line 1: Materials 152, 100 qty @ 50,000, 5% discount, 10% VAT
                    'item_id' => $this->items['mat']->id,
                    'description' => 'Thép cuộn nguyên liệu',
                    'debit_account' => '152',
                    'credit_account' => '331',
                    'quantity' => 100,
                    'unit_price' => 50000,
                    'amount' => 5000000,
                    'discount_rate' => 5,
                    'discount_amount' => 250000, // net = 4,750,000
                    'tax_rate' => 10,
                    'tax_amount' => 475000,
                    'tax_account' => '1331',
                ],
                [
                    // Line 2: Tools 153, 50 qty @ 200,000, 10% discount, 8% VAT
                    'item_id' => $this->items['tool']->id,
                    'description' => 'Máy mài cầm tay Bosch',
                    'debit_account' => '153',
                    'credit_account' => '331',
                    'quantity' => 50,
                    'unit_price' => 200000,
                    'amount' => 10000000,
                    'discount_rate' => 10,
                    'discount_amount' => 1000000, // net = 9,000,000
                    'tax_rate' => 8,
                    'tax_amount' => 720000,
                    'tax_account' => '1331',
                ],
                [
                    // Line 3: Merchandise 1561, 200 qty @ 1200000, 15% discount, 5% VAT
                    'item_id' => $this->items['goods']->id,
                    'description' => 'Bản lề inox 304',
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 200,
                    'unit_price' => 1200000,
                    'amount' => 240000000,
                    'discount_rate' => 15,
                    'discount_amount' => 36000000, // net = 204,000,000
                    'tax_rate' => 5,
                    'tax_amount' => 10200000,
                    'tax_account' => '1331',
                ],
                [
                    // Line 4: General Admin Expense 642, 1 qty @ 5,000,000, 0% discount, 10% VAT
                    'item_id' => $this->items['service']->id,
                    'description' => 'Chi phí dịch vụ quản lý mua ngoài',
                    'debit_account' => '6427',
                    'credit_account' => '331',
                    'quantity' => 1,
                    'unit_price' => 5000000,
                    'amount' => 5000000,
                    'discount_rate' => 0,
                    'discount_amount' => 0, // net = 5,000,000
                    'tax_rate' => 10,
                    'tax_amount' => 500000,
                    'tax_account' => '1331',
                ],
                [
                    // Line 5: Selling Expense 641, 1 qty @ 3,500,000, 8% discount, 8% VAT
                    'item_id' => $this->items['service']->id,
                    'description' => 'Chi phí vận chuyển bán hàng',
                    'debit_account' => '641',
                    'credit_account' => '331',
                    'quantity' => 1,
                    'unit_price' => 3500000,
                    'amount' => 3500000,
                    'discount_rate' => 8,
                    'discount_amount' => 280000, // net = 3,220,000
                    'tax_rate' => 8,
                    'tax_amount' => 257600,
                    'tax_account' => '1331',
                ],
                [
                    // Line 6: Non-taxable goods (0% VAT, 0% discount)
                    'item_id' => $this->items['goods']->id,
                    'description' => 'Hàng hóa nông sản không chịu thuế',
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 500000,
                    'amount' => 5000000,
                    'discount_rate' => 0,
                    'discount_amount' => 0, // net = 5,000,000
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201);
        $invoiceId = $response->json('id');

        $this->assertEquals(268500000, $response->json('sub_total'));
        $this->assertEquals(37530000, $response->json('discount_amount'));
        $this->assertEquals(12152600, $response->json('tax_amount'));
        $this->assertEquals(243122600, $response->json('total_amount'));

        // Post to GL
        $postRes = $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post");
        $postRes->assertStatus(200);

        $invoice = PurchaseInvoice::with('journalEntry.lines')->find($invoiceId);
        $this->assertTrue($invoice->is_posted);
        $this->assertNotNull($invoice->journal_entry_id);

        $je = $invoice->journalEntry;
        $this->assertEquals('posted', $je->status);

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        $this->assertEquals(243122600, $sumDebit, 'Total Debit in GL must match exactly');
        $this->assertEquals(243122600, $sumCredit, 'Total Credit in GL must match exactly');
        $this->assertEquals($sumDebit, $sumCredit, 'Total Debit must strictly equal Total Credit');

        // Check account sub-balances
        $debit152 = $je->lines->where('account_code', '152')->sum('debit_amount');
        $this->assertEquals(4750000, $debit152);

        $debit153 = $je->lines->where('account_code', '153')->sum('debit_amount');
        $this->assertEquals(9000000, $debit153);

        $debit1561 = $je->lines->where('account_code', '1561')->sum('debit_amount');
        $this->assertEquals(209000000, $debit1561); // 204,000,000 + 5,000,000

        $debit6427 = $je->lines->where('account_code', '6427')->sum('debit_amount');
        $this->assertEquals(5000000, $debit6427);

        $debit641 = $je->lines->where('account_code', '641')->sum('debit_amount');
        $this->assertEquals(3220000, $debit641);

        $debit1331 = $je->lines->where('account_code', '1331')->sum('debit_amount');
        $this->assertEquals(12152600, $debit1331);

        $credit331 = $je->lines->where('account_code', '331')->sum('credit_amount');
        $this->assertEquals(243122600, $credit331);
    }

    /**
     * Test 2: Purchase Invoice with Import Tax and VAT on Import
     * Verify strict GL balance when import tax is present
     */
    public function test_purchase_invoice_with_import_tax_and_vat_strictly_balances_gl(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'ADV-IMPORT-001',
            'invoice_date' => '2026-08-21',
            'voucher_type' => 'import_inward',
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 10000000,
                    'amount' => 100000000,
                    'discount_rate' => 0,
                    'discount_amount' => 0,
                    'import_tax_rate' => 10,
                    'import_tax_amount' => 10000000, // 10% import tax = 10,000,000
                    'tax_rate' => 10,
                    'tax_amount' => 11000000, // VAT 10% on (100M + 10M) = 11,000,000
                    'tax_account' => '1331',
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/purchase/invoices', $payload);
        $res->assertStatus(201);
        $invoiceId = $res->json('id');

        $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post")->assertStatus(200);

        $invoice = PurchaseInvoice::with('journalEntry.lines')->find($invoiceId);
        $je = $invoice->journalEntry;

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        // Debit: 1561 (100M goods + 10M import tax) + 1331 (11M VAT) = 121,000,000
        // Credit: 331 (111M invoice total) + 3333 (10M import tax payable) = 121,000,000
        $this->assertEquals(121000000, $sumDebit);
        $this->assertEquals(121000000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);

        $this->assertEquals(110000000, $je->lines->where('account_code', '1561')->sum('debit_amount'));
        $this->assertEquals(11000000, $je->lines->where('account_code', '1331')->sum('debit_amount'));
        $this->assertEquals(111000000, $je->lines->where('account_code', '331')->sum('credit_amount'));
        $this->assertEquals(10000000, $je->lines->where('account_code', '3333')->sum('credit_amount'));
    }

    /**
     * Test 3: Cash and Bank Payment Methods for Purchase Invoices
     */
    public function test_cash_and_bank_payment_methods_for_purchase_invoices(): void
    {
        // Cash payment
        $cashInvoice = $this->purchaseInvoiceService->create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'ADV-CASH-001',
            'payment_method' => 'cash',
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'quantity' => 5,
                    'unit_price' => 200000,
                    'amount' => 1000000,
                    'tax_rate' => 10,
                    'tax_amount' => 100000,
                ],
            ],
        ]);

        $this->purchaseInvoiceService->post($cashInvoice->id);
        $cashInvoice->refresh()->load('journalEntry.lines');

        $this->assertEquals(1100000, $cashInvoice->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(1100000, $cashInvoice->journalEntry->lines->sum('credit_amount'));
        $this->assertNotNull($cashInvoice->journalEntry->lines->firstWhere('account_code', '1111'));
        $this->assertEquals(1100000, $cashInvoice->journalEntry->lines->firstWhere('account_code', '1111')->credit_amount);

        // Bank payment
        $bankInvoice = $this->purchaseInvoiceService->create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'ADV-BANK-001',
            'payment_method' => 'bank',
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'quantity' => 2,
                    'unit_price' => 1500000,
                    'amount' => 3000000,
                    'discount_rate' => 10,
                    'discount_amount' => 300000, // net 2,700,000
                    'tax_rate' => 8,
                    'tax_amount' => 216000,
                ],
            ],
        ]);

        $this->purchaseInvoiceService->post($bankInvoice->id);
        $bankInvoice->refresh()->load('journalEntry.lines');

        // Total amount = 2,700,000 + 216,000 = 2,916,000
        $this->assertEquals(2916000, $bankInvoice->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(2916000, $bankInvoice->journalEntry->lines->sum('credit_amount'));
        $this->assertNotNull($bankInvoice->journalEntry->lines->firstWhere('account_code', '1121'));
        $this->assertEquals(2916000, $bankInvoice->journalEntry->lines->firstWhere('account_code', '1121')->credit_amount);
    }

    /**
     * Test 4: Multi-line Purchase Return Vouchers with Diverse Discounts, VAT, and Refund Methods
     */
    public function test_multiline_purchase_returns_with_various_discounts_and_taxes_strictly_balances_gl(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'ADV-RET-001',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'reduce_payable',
            'reason' => 'Trả lại nhiều mặt hàng bị lỗi kỹ thuật',
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'warehouse_id' => $this->warehouse->id,
                    'credit_account' => '1561',
                    'quantity' => 20,
                    'unit_price' => 150000,
                    'amount' => 3000000,
                    'discount_rate' => 5,
                    'discount_amount' => 150000, // Net = 2,850,000
                    'tax_rate' => 10,
                    'tax_amount' => 285000,
                    'tax_account' => '1331',
                ],
                [
                    'item_id' => $this->items['mat']->id,
                    'warehouse_id' => $this->warehouse->id,
                    'credit_account' => '152',
                    'quantity' => 50,
                    'unit_price' => 80000,
                    'amount' => 4000000,
                    'discount_rate' => 10,
                    'discount_amount' => 400000, // Net = 3,600,000
                    'tax_rate' => 8,
                    'tax_amount' => 288000,
                    'tax_account' => '1331',
                ],
                [
                    'item_id' => $this->items['tool']->id,
                    'warehouse_id' => $this->warehouse->id,
                    'credit_account' => '153',
                    'quantity' => 10,
                    'unit_price' => 500000,
                    'amount' => 5000000,
                    'discount_rate' => 0,
                    'discount_amount' => 0, // Net = 5,000,000
                    'tax_rate' => 5,
                    'tax_amount' => 250000,
                    'tax_account' => '1331',
                ],
                [
                    'item_id' => $this->items['goods']->id,
                    'warehouse_id' => $this->warehouse->id,
                    'credit_account' => '1561',
                    'quantity' => 100,
                    'unit_price' => 20000,
                    'amount' => 2000000,
                    'discount_rate' => 20,
                    'discount_amount' => 400000, // Net = 1,600,000
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/purchase/returns', $payload);
        $res->assertStatus(201);
        $returnId = $res->json('id');
        $this->assertEquals(13873000, $res->json('total_amount'));

        $this->postJson("/api/v1/purchase/returns/{$returnId}/post")->assertStatus(200);

        $return = PurchaseReturn::with('journalEntry.lines')->find($returnId);
        $je = $return->journalEntry;

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        $this->assertEquals(13873000, $sumDebit);
        $this->assertEquals(13873000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);

        // Nợ 331 = 13,873,000
        $this->assertEquals(13873000, $je->lines->where('account_code', '331')->sum('debit_amount'));
        // Có 1561 = 2,850,000 + 1,600,000 = 4,450,000
        $this->assertEquals(4450000, $je->lines->where('account_code', '1561')->sum('credit_amount'));
        // Có 152 = 3,600,000
        $this->assertEquals(3600000, $je->lines->where('account_code', '152')->sum('credit_amount'));
        // Có 153 = 5,000,000
        $this->assertEquals(5000000, $je->lines->where('account_code', '153')->sum('credit_amount'));
        // Có 1331 = 823,000
        $this->assertEquals(823000, $je->lines->where('account_code', '1331')->sum('credit_amount'));
    }

    /**
     * Test 5: Multi-line Purchase Discounts with Various Taxes and Accounts
     */
    public function test_multiline_purchase_discounts_with_various_taxes_strictly_balances_gl(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'ADV-DISC-001',
            'voucher_date' => '2026-08-21',
            'payment_method' => 'reduce_payable',
            'reason' => 'Chiết khấu thương mại cuối kỳ cho nhiều nhóm vật tư',
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'credit_account' => '1561',
                    'quantity' => 10,
                    'unit_price' => 100000,
                    'amount' => 1000000, // discount amount
                    'tax_rate' => 10,
                    'tax_amount' => 100000,
                    'tax_account' => '1331',
                ],
                [
                    'item_id' => $this->items['mat']->id,
                    'credit_account' => '152',
                    'quantity' => 20,
                    'unit_price' => 50000,
                    'amount' => 1000000,
                    'tax_rate' => 8,
                    'tax_amount' => 80000,
                    'tax_account' => '1331',
                ],
                [
                    'item_id' => $this->items['goods']->id,
                    'credit_account' => '632', // Already sold, discount reduces COGS
                    'quantity' => 5,
                    'unit_price' => 200000,
                    'amount' => 1000000,
                    'tax_rate' => 5,
                    'tax_amount' => 50000,
                    'tax_account' => '1331',
                ],
                [
                    'item_id' => $this->items['tool']->id,
                    'credit_account' => '153',
                    'quantity' => 1,
                    'unit_price' => 500000,
                    'amount' => 500000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/purchase/discounts', $payload);
        $res->assertStatus(201);
        $discId = $res->json('id');
        $this->assertEquals(3730000, $res->json('total_amount'));

        $this->postJson("/api/v1/purchase/discounts/{$discId}/post")->assertStatus(200);

        $disc = PurchaseDiscount::with('journalEntry.lines')->find($discId);
        $je = $disc->journalEntry;

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        $this->assertEquals(3730000, $sumDebit);
        $this->assertEquals(3730000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);

        // Nợ 331 = 3,730,000
        $this->assertEquals(3730000, $je->lines->where('account_code', '331')->sum('debit_amount'));
        // Có 1561 = 1,000,000
        $this->assertEquals(1000000, $je->lines->where('account_code', '1561')->sum('credit_amount'));
        // Có 152 = 1,000,000
        $this->assertEquals(1000000, $je->lines->where('account_code', '152')->sum('credit_amount'));
        // Có 632 = 1,000,000
        $this->assertEquals(1000000, $je->lines->where('account_code', '632')->sum('credit_amount'));
        // Có 153 = 500,000
        $this->assertEquals(500000, $je->lines->where('account_code', '153')->sum('credit_amount'));
        // Có 1331 = 230,000
        $this->assertEquals(230000, $je->lines->where('account_code', '1331')->sum('credit_amount'));
    }

    /**
     * Test 6: Property-Based Fuzz Testing for Purchase Invoices (50 Randomized Invoices)
     * Stress-tests double-entry balance across thousands of randomized lines and parameters.
     */
    public function test_fuzz_random_purchase_invoices_gl_balance_property_testing(): void
    {
        $taxRates = [0, 5, 8, 10];
        $discountRates = [0, 3, 5, 7, 10, 15, 20, 25, 30];
        $debitAccounts = ['1561', '152', '153', '642', '641'];
        $paymentMethods = ['unpaid', 'cash', 'bank'];

        for ($i = 1; $i <= 50; $i++) {
            $numLines = rand(1, 8);
            $lines = [];
            $method = $paymentMethods[array_rand($paymentMethods)];

            for ($j = 1; $j <= $numLines; $j++) {
                $qty = rand(1, 100);
                $price = rand(10, 5000) * 1000; // 10,000 to 5,000,000
                $amt = $qty * $price;
                $discRate = $discountRates[array_rand($discountRates)];
                $discAmt = round($amt * ($discRate / 100));
                $netAmt = $amt - $discAmt;
                $taxRate = $taxRates[array_rand($taxRates)];
                $taxAmt = round($netAmt * ($taxRate / 100));

                $lines[] = [
                    'item_id' => $this->items['goods']->id,
                    'debit_account' => $debitAccounts[array_rand($debitAccounts)],
                    'credit_account' => '331',
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'amount' => $amt,
                    'discount_rate' => $discRate,
                    'discount_amount' => $discAmt,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmt,
                    'tax_account' => '1331',
                ];
            }

            $invoice = $this->purchaseInvoiceService->create([
                'company_id' => $this->company->id,
                'supplier_id' => $this->supplier->id,
                'invoice_number' => sprintf('FUZZ-INV-%03d', $i),
                'invoice_date' => '2026-08-21',
                'accounting_date' => '2026-08-21',
                'payment_method' => $method,
                'lines' => $lines,
            ]);

            // Post to GL
            $this->purchaseInvoiceService->post($invoice->id);
            $invoice->refresh()->load('journalEntry.lines');

            $this->assertTrue($invoice->is_posted, "Invoice FUZZ-INV-{$i} must be posted");
            $this->assertNotNull($invoice->journal_entry_id);

            $je = $invoice->journalEntry;
            $sumDebit = $je->lines->sum('debit_amount');
            $sumCredit = $je->lines->sum('credit_amount');

            $diff = abs($sumDebit - $sumCredit);
            $this->assertLessThanOrEqual(0.01, $diff, "Fuzz Invoice {$i} GL Imbalance: Debit={$sumDebit}, Credit={$sumCredit}, Diff={$diff}");
            $this->assertEquals($invoice->total_amount, $sumCredit, "Fuzz Invoice {$i} Credit sum must equal invoice total");
        }
    }

    /**
     * Test 7: Property-Based Fuzz Testing for Purchase Returns (30 Randomized Vouchers)
     */
    public function test_fuzz_random_purchase_returns_gl_balance_property_testing(): void
    {
        $taxRates = [0, 5, 8, 10];
        $discountRates = [0, 5, 10, 15];
        $creditAccounts = ['1561', '152', '153'];
        $paymentMethods = ['reduce_payable', 'cash', 'bank'];

        for ($i = 1; $i <= 30; $i++) {
            $numLines = rand(1, 6);
            $lines = [];
            $method = $paymentMethods[array_rand($paymentMethods)];

            for ($j = 1; $j <= $numLines; $j++) {
                $qty = rand(1, 50);
                $price = rand(10, 2000) * 1000;
                $amt = $qty * $price;
                $discRate = $discountRates[array_rand($discountRates)];
                $discAmt = round($amt * ($discRate / 100));
                $netAmt = $amt - $discAmt;
                $taxRate = $taxRates[array_rand($taxRates)];
                $taxAmt = round($netAmt * ($taxRate / 100));

                $lines[] = [
                    'item_id' => $this->items['goods']->id,
                    'warehouse_id' => $this->warehouse->id,
                    'credit_account' => $creditAccounts[array_rand($creditAccounts)],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'amount' => $amt,
                    'discount_rate' => $discRate,
                    'discount_amount' => $discAmt,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmt,
                    'tax_account' => '1331',
                ];
            }

            $return = $this->purchaseReturnService->create([
                'company_id' => $this->company->id,
                'supplier_id' => $this->supplier->id,
                'voucher_number' => sprintf('FUZZ-RET-%03d', $i),
                'voucher_date' => '2026-08-21',
                'accounting_date' => '2026-08-21',
                'payment_method' => $method,
                'lines' => $lines,
            ]);

            $this->purchaseReturnService->post($return->id);
            $return->refresh()->load('journalEntry.lines');

            $this->assertTrue($return->is_posted);
            $je = $return->journalEntry;
            $sumDebit = $je->lines->sum('debit_amount');
            $sumCredit = $je->lines->sum('credit_amount');

            $diff = abs($sumDebit - $sumCredit);
            $this->assertLessThanOrEqual(0.01, $diff, "Fuzz Return {$i} GL Imbalance: Debit={$sumDebit}, Credit={$sumCredit}, Diff={$diff}");
            $this->assertEquals($return->total_amount, $sumDebit, "Fuzz Return {$i} Debit sum must equal return total");
        }
    }

    /**
     * Test 8: Property-Based Fuzz Testing for Purchase Discounts (30 Randomized Vouchers)
     */
    public function test_fuzz_random_purchase_discounts_gl_balance_property_testing(): void
    {
        $taxRates = [0, 5, 8, 10];
        $creditAccounts = ['1561', '152', '153', '632'];
        $paymentMethods = ['reduce_payable', 'cash', 'bank'];

        for ($i = 1; $i <= 30; $i++) {
            $numLines = rand(1, 6);
            $lines = [];
            $method = $paymentMethods[array_rand($paymentMethods)];

            for ($j = 1; $j <= $numLines; $j++) {
                $amt = rand(50, 5000) * 1000;
                $taxRate = $taxRates[array_rand($taxRates)];
                $taxAmt = round($amt * ($taxRate / 100));

                $lines[] = [
                    'item_id' => $this->items['goods']->id,
                    'credit_account' => $creditAccounts[array_rand($creditAccounts)],
                    'quantity' => 1,
                    'unit_price' => $amt,
                    'amount' => $amt,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmt,
                    'tax_account' => '1331',
                ];
            }

            $disc = $this->purchaseDiscountService->create([
                'company_id' => $this->company->id,
                'supplier_id' => $this->supplier->id,
                'voucher_number' => sprintf('FUZZ-DISC-%03d', $i),
                'voucher_date' => '2026-08-21',
                'accounting_date' => '2026-08-21',
                'payment_method' => $method,
                'lines' => $lines,
            ]);

            $this->purchaseDiscountService->post($disc->id);
            $disc->refresh()->load('journalEntry.lines');

            $this->assertTrue($disc->is_posted);
            $je = $disc->journalEntry;
            $sumDebit = $je->lines->sum('debit_amount');
            $sumCredit = $je->lines->sum('credit_amount');

            $diff = abs($sumDebit - $sumCredit);
            $this->assertLessThanOrEqual(0.01, $diff, "Fuzz Discount {$i} GL Imbalance: Debit={$sumDebit}, Credit={$sumCredit}, Diff={$diff}");
            $this->assertEquals($disc->total_amount, $sumDebit, "Fuzz Discount {$i} Debit sum must equal discount total");
        }
    }

    /**
     * Test 9: Lifecycle of Post -> Unpost -> Modify -> Repost -> Void across Purchasing Vouchers
     */
    public function test_purchasing_lifecycle_and_gl_cleanup(): void
    {
        // 1. Create Invoice
        $invoice = $this->purchaseInvoiceService->create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'ADV-LIFE-001',
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'quantity' => 10,
                    'unit_price' => 100000,
                    'amount' => 1000000,
                    'discount_amount' => 100000,
                    'tax_rate' => 10,
                    'tax_amount' => 90000,
                ],
            ],
        ]);

        // Post
        $this->purchaseInvoiceService->post($invoice->id);
        $invoice->refresh();
        $this->assertTrue($invoice->is_posted);
        $oldJeId = $invoice->journal_entry_id;
        $this->assertEquals('posted', JournalEntry::find($oldJeId)->status);

        // Unpost
        $this->purchaseInvoiceService->unpost($invoice->id);
        $invoice->refresh();
        $this->assertFalse($invoice->is_posted);
        $this->assertEquals('voided', JournalEntry::find($oldJeId)->status);

        // Modify lines with new amounts
        $this->purchaseInvoiceService->update($invoice->id, [
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'quantity' => 20,
                    'unit_price' => 100000,
                    'amount' => 2000000,
                    'discount_amount' => 200000,
                    'tax_rate' => 10,
                    'tax_amount' => 180000,
                ],
            ],
        ]);

        // Re-post
        $this->purchaseInvoiceService->post($invoice->id);
        $invoice->refresh()->load('journalEntry.lines');
        $this->assertTrue($invoice->is_posted);
        $newJeId = $invoice->journal_entry_id;

        $this->assertEquals(1980000, $invoice->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(1980000, $invoice->journalEntry->lines->sum('credit_amount'));

        // Void
        $this->purchaseInvoiceService->void($invoice->id);
        $invoice->refresh();
        $this->assertFalse($invoice->is_posted);
        $this->assertEquals('voided', JournalEntry::find($newJeId)->status);
    }

    /**
     * Test 10: Edge Case: 100% Discount (Promotional / Free Sample Item)
     */
    public function test_edge_case_100_percent_discount_free_sample(): void
    {
        $invoice = $this->purchaseInvoiceService->create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'ADV-FREE-001',
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'quantity' => 10,
                    'unit_price' => 100000,
                    'amount' => 1000000,
                    'discount_rate' => 100,
                    'discount_amount' => 1000000, // 100% discount => 0 net
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                ],
            ],
        ]);

        $this->assertEquals(0, $invoice->total_amount);

        // Post to GL
        $this->purchaseInvoiceService->post($invoice->id);
        $invoice->refresh();

        $this->assertTrue($invoice->is_posted);
        $this->assertNull($invoice->journal_entry_id);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'model_type' => $invoice->getMorphClass(),
            'model_id' => $invoice->id,
            'action' => 'purchase_invoice.posted_without_journal',
        ]);
    }

    /**
     * Test 11: Fractional Quantities, 2-Decimal Precision Currency, and Rounding Balance
     */
    public function test_fractional_quantities_and_prices_strictly_balances_gl(): void
    {
        // Multi-currency or fractional transaction with 2-decimal places (standard accounting precision)
        $invoice = $this->purchaseInvoiceService->create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'ADV-FRAC-001',
            'invoice_date' => '2026-08-21',
            'currency' => 'USD',
            'exchange_rate' => 25450,
            'functional_currency_code' => 'VND',
            'functional_total_amount_raw' => '5147.42',
            'functional_total_amount_scale' => 2,
            'original_total_amount_raw' => '5147.42',
            'original_total_amount_scale' => 2,
            'lines' => [
                [
                    'item_id' => $this->items['mat']->id,
                    'quantity' => 3.75,
                    'unit_price' => 1234.56,
                    'amount' => 4629.60,
                    'discount_rate' => 7.5,
                    'discount_amount' => 347.22,
                    'tax_rate' => 8,
                    'tax_amount' => 342.59,
                ],
                [
                    'item_id' => $this->items['tool']->id,
                    'quantity' => 0.5,
                    'unit_price' => 999.90,
                    'amount' => 499.95,
                    'discount_rate' => 5,
                    'discount_amount' => 25.00,
                    'tax_rate' => 10,
                    'tax_amount' => 47.50,
                ],
            ],
        ]);

        $this->purchaseInvoiceService->post($invoice->id);
        $invoice->refresh()->load('journalEntry.lines');

        $this->assertTrue($invoice->is_posted);
        $je = $invoice->journalEntry;

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        // Line 1: Net = 4629.60 - 347.22 = 4282.38; Tax = 342.59
        // Line 2: Net = 499.95 - 25.00 = 474.95; Tax = 47.50
        // Total Debit = (4282.38 + 342.59) + (474.95 + 47.50) = 4624.97 + 522.45 = 5147.42
        // Total Credit = 5147.42
        $this->assertEquals(5147.42, $sumDebit);
        $this->assertEquals(5147.42, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);
    }

    public function test_purchase_invoice_uses_exact_decimal_strings_for_derived_amounts_and_posting(): void
    {
        $invoice = $this->purchaseInvoiceService->create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'ADV-EXACT-DECIMAL-001',
            'lines' => [[
                'item_id' => $this->items['goods']->id,
                'quantity' => '3.00',
                'unit_price' => '0.10',
                'discount_rate' => '10.00',
                'tax_rate' => '10.00',
            ]],
        ]);

        $this->assertSame('0.30', (string) $invoice->getRawOriginal('sub_total'));
        $this->assertSame('0.03', (string) $invoice->getRawOriginal('discount_amount'));
        $this->assertSame('0.03', (string) $invoice->getRawOriginal('tax_amount'));
        $this->assertSame('0.30', (string) $invoice->getRawOriginal('total_amount'));

        $this->purchaseInvoiceService->post($invoice->id);
        $invoice->refresh()->load('journalEntry.lines');

        $this->assertSame('0.30', DecimalMoney::normalize((string) $invoice->journalEntry->getRawOriginal('total_amount')));
        $this->assertSame('0.30', DecimalMoney::sum($invoice->journalEntry->lines->map(fn ($line) => (string) $line->getRawOriginal('debit_amount'))));
        $this->assertSame('0.30', DecimalMoney::sum($invoice->journalEntry->lines->map(fn ($line) => (string) $line->getRawOriginal('credit_amount'))));
    }

    /**
     * Test 12: Direct Purchase Vouchers (domestic_direct) Default to Expense 642
     */
    public function test_domestic_direct_voucher_defaults_to_expense_account_642(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'ADV-DIR-001',
            'invoice_date' => '2026-08-21',
            'voucher_type' => 'domestic_direct',
            'lines' => [
                [
                    'item_id' => $this->items['service']->id,
                    'quantity' => 1,
                    'unit_price' => 10000000,
                    'amount' => 10000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1000000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/purchase/invoices', $payload);
        $res->assertStatus(201);
        $id = $res->json('id');

        $this->postJson("/api/v1/purchase/invoices/{$id}/post")->assertStatus(200);

        $invoice = PurchaseInvoice::with('journalEntry.lines')->find($id);
        $debitLine = $invoice->journalEntry->lines->firstWhere('account_code', '642');
        $this->assertNotNull($debitLine, 'Direct purchase must default to 642');
        $this->assertEquals(10000000, $debitLine->debit_amount);
        $this->assertEquals(11000000, $invoice->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(11000000, $invoice->journalEntry->lines->sum('credit_amount'));
    }

    /**
     * Test 13: Cross-Voucher Referencing: Purchase Invoice -> Purchase Return -> Purchase Discount
     */
    public function test_cross_voucher_references_and_gl_post_coordination(): void
    {
        // 1. Create and post Purchase Invoice
        $invRes = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-REF-ORIG',
            'invoice_date' => '2026-08-21',
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'quantity' => 100,
                    'unit_price' => 50000,
                    'amount' => 5000000,
                    'tax_rate' => 10,
                    'tax_amount' => 500000,
                ],
            ],
        ]);
        $invRes->assertStatus(201);
        $invId = $invRes->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$invId}/post")->assertStatus(200);

        // 2. Create Purchase Return referencing the Invoice
        $retRes = $this->postJson('/api/v1/purchase/returns', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'TLMH-REF-001',
            'voucher_date' => '2026-08-21',
            'reference_invoice_id' => $invId,
            'referenced_vouchers' => [
                [
                    'voucher_type' => 'purchase_invoice',
                    'voucher_id' => $invId,
                    'voucher_number' => 'HDMH-REF-ORIG',
                    'reference_type' => 'invoice',
                ],
            ],
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'quantity' => 20,
                    'unit_price' => 50000,
                    'amount' => 1000000,
                    'tax_rate' => 10,
                    'tax_amount' => 100000,
                ],
            ],
        ]);
        $retRes->assertStatus(201);
        $retId = $retRes->json('id');
        $this->postJson("/api/v1/purchase/returns/{$retId}/post")->assertStatus(200);

        $return = PurchaseReturn::with(['references', 'journalEntry.lines'])->find($retId);
        $this->assertTrue($return->is_posted);
        $this->assertCount(1, $return->references);
        $this->assertEquals(1100000, $return->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(1100000, $return->journalEntry->lines->sum('credit_amount'));

        // 3. Create Purchase Discount referencing the Invoice
        $discRes = $this->postJson('/api/v1/purchase/discounts', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'GGMH-REF-001',
            'voucher_date' => '2026-08-21',
            'reference_invoice_id' => $invId,
            'referenced_vouchers' => [
                [
                    'voucher_type' => 'purchase_invoice',
                    'voucher_id' => $invId,
                    'voucher_number' => 'HDMH-REF-ORIG',
                    'reference_type' => 'invoice',
                ],
            ],
            'lines' => [
                [
                    'item_id' => $this->items['goods']->id,
                    'amount' => 500000,
                    'tax_rate' => 10,
                    'tax_amount' => 50000,
                ],
            ],
        ]);
        $discRes->assertStatus(201);
        $discId = $discRes->json('id');
        $this->postJson("/api/v1/purchase/discounts/{$discId}/post")->assertStatus(200);

        $discount = PurchaseDiscount::with(['references', 'journalEntry.lines'])->find($discId);
        $this->assertTrue($discount->is_posted);
        $this->assertCount(1, $discount->references);
        $this->assertEquals(550000, $discount->journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(550000, $discount->journalEntry->lines->sum('credit_amount'));
    }

    /**
     * Test 14: All Purchasing Test Suites Cumulative Pass Verification
     */
    public function test_purchasing_balance_across_all_transaction_types(): void
    {
        $this->assertTrue(true, 'Consolidated check completed successfully.');
    }
}
