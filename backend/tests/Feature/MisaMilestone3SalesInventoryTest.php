<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MisaMilestone3SalesInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected Customer $customer;

    protected Supplier $supplier;

    protected Warehouse $warehouse;

    protected Item $productItem;

    protected Item $serviceItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Công ty TNHH MISA M3 Test',
                'tax_code' => '0101234567',
                'address' => 'Tầng 10 Tòa nhà MISA, Cầu Giấy, Hà Nội',
                'is_active' => true,
            ]
        );

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Kế toán trưởng MISA M3',
            'email' => 'misa_m3_'.uniqid().'@example.com',
        ]);
        // Keep milestone fixtures aligned with the production two-role policy.
        // Posting is intentionally denied to roleless/legacy identities.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        $this->fiscalYear = FiscalYear::firstOrCreate(
            [
                'company_id' => $this->company->id,
                'year' => 2026,
            ],
            [
                'code' => 'FY2026',
                'name' => 'Năm tài chính 2026',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'open',
            ]
        );

        // Standard Circular 200 COA
        $accounts = [
            ['code' => '1111', 'name' => 'Tiền mặt Việt Nam Đồng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng VND', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '153', 'name' => 'Công cụ, dụng cụ', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '154', 'name' => 'Chi phí sản xuất dở dang', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '155', 'name' => 'Thành phẩm', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '5112', 'name' => 'Doanh thu bán thành phẩm', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '5113', 'name' => 'Doanh thu cung cấp dịch vụ', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '621', 'name' => 'Chi phí nguyên vật liệu trực tiếp', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '642', 'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit'],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::firstOrCreate(
                [
                    'company_id' => $this->company->id,
                    'code' => $acc['code'],
                ],
                array_merge($acc, [
                    'company_id' => $this->company->id,
                    'is_active' => true,
                    'is_parent' => false,
                    'level' => 1,
                ])
            );
        }

        $this->customer = Customer::firstOrCreate(
            ['code' => 'KH_M3_001'],
            [
                'company_id' => $this->company->id,
                'name' => 'Công ty ABC Test',
                'tax_code' => '0109998887',
                'address' => 'Hà Nội',
            ]
        );

        $this->supplier = Supplier::firstOrCreate(
            ['code' => 'NCC_M3_001'],
            [
                'company_id' => $this->company->id,
                'name' => 'Nhà cung cấp XYZ',
                'tax_code' => '0108887776',
                'address' => 'Hải Phòng',
            ]
        );

        $this->warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO_M3_001'],
            [
                'company_id' => $this->company->id,
                'name' => 'Kho Tổng Hà Nội M3',
                'account_code' => '1561',
            ]
        );

        $this->productItem = Item::firstOrCreate(
            ['code' => 'HH_M3_001'],
            [
                'company_id' => $this->company->id,
                'name' => 'Laptop Dell Inspiron',
                'unit' => 'Chiếc',
                'purchase_price' => 10000000,
                'selling_price' => 15000000,
                'warehouse_id' => $this->warehouse->id,
                'inventory_account' => '1561',
                'cogs_account' => '632',
                'sales_account' => '5111',
                'type' => 'Goods',
            ]
        );

        $this->serviceItem = Item::firstOrCreate(
            ['code' => 'DV_M3_001'],
            [
                'company_id' => $this->company->id,
                'name' => 'Dịch vụ cài đặt và bảo trì',
                'unit' => 'Gói',
                'selling_price' => 2000000,
                'sales_account' => '5113',
                'type' => 'Service',
            ]
        );
    }

    /**
     * 1. Test Sales Invoice with different payment methods
     */
    public function test_sales_invoice_payment_methods_gl_posting(): void
    {
        // A. Cash Payment (Thu tiền ngay bằng tiền mặt -> Nợ 1111)
        $cashPayload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_type' => 'Bán hàng thu tiền ngay (Tiền mặt)',
            'payment_method' => 'cash',
            'invoice_number' => 'HDBH-CASH-001',
            'invoice_date' => '2026-08-01',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'quantity' => 2,
                    'unit_price' => 15000000,
                    'amount' => 30000000,
                    'tax_rate' => 10,
                    'tax_amount' => 3000000,
                    'credit_account' => '5111',
                ],
            ],
        ];

        $resCash = $this->postJson('/api/v1/sales/invoices', $cashPayload);
        $resCash->assertStatus(201);
        $cashId = $resCash->json('id') ?? $resCash->json('data.id');

        $this->postJson("/api/v1/sales/invoices/{$cashId}/post")->assertStatus(200);

        $postedCash = SalesInvoice::with('journalEntry.lines')->find($cashId);
        $this->assertTrue($postedCash->is_posted);
        $cashJe = $postedCash->journalEntry;
        $this->assertNotNull($cashJe);

        $debit1111 = $cashJe->lines->firstWhere('account_code', '1111');
        $credit5111 = $cashJe->lines->firstWhere('account_code', '5111');
        $credit33311 = $cashJe->lines->firstWhere('account_code', '33311');

        $this->assertEquals(33000000, $debit1111->debit_amount);
        $this->assertEquals(30000000, $credit5111->credit_amount);
        $this->assertEquals(3000000, $credit33311->credit_amount);

        // B. Bank Payment (Thu tiền ngay gửi ngân hàng -> Nợ 1121)
        $bankPayload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_type' => 'Bán hàng thu tiền ngay (Chuyển khoản)',
            'payment_method' => 'bank',
            'invoice_number' => 'HDBH-BANK-001',
            'invoice_date' => '2026-08-02',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'quantity' => 1,
                    'unit_price' => 15000000,
                    'amount' => 15000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1500000,
                    'credit_account' => '5111',
                ],
            ],
        ];

        $resBank = $this->postJson('/api/v1/sales/invoices', $bankPayload);
        $resBank->assertStatus(201);
        $bankId = $resBank->json('id') ?? $resBank->json('data.id');

        $this->postJson("/api/v1/sales/invoices/{$bankId}/post")->assertStatus(200);

        $postedBank = SalesInvoice::with('journalEntry.lines')->find($bankId);
        $bankJe = $postedBank->journalEntry;
        $debit1121 = $bankJe->lines->firstWhere('account_code', '1121');
        $this->assertEquals(16500000, $debit1121->debit_amount);

        // C. Unpaid (Bán hàng chưa thu tiền -> Nợ 131)
        $unpaidPayload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_type' => 'Bán hàng chưa thu tiền',
            'payment_method' => 'unpaid',
            'invoice_number' => 'HDBH-CREDIT-001',
            'invoice_date' => '2026-08-03',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'quantity' => 1,
                    'unit_price' => 15000000,
                    'amount' => 15000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1500000,
                    'credit_account' => '5111',
                ],
            ],
        ];

        $resUnpaid = $this->postJson('/api/v1/sales/invoices', $unpaidPayload);
        $resUnpaid->assertStatus(201);
        $unpaidId = $resUnpaid->json('id') ?? $resUnpaid->json('data.id');

        $this->postJson("/api/v1/sales/invoices/{$unpaidId}/post")->assertStatus(200);

        $postedUnpaid = SalesInvoice::with('journalEntry.lines')->find($unpaidId);
        $unpaidJe = $postedUnpaid->journalEntry;
        $debit131 = $unpaidJe->lines->firstWhere('account_code', '131');
        $this->assertEquals(16500000, $debit131->debit_amount);
    }

    /**
     * 2. Test Sales Invoice kiêm phiếu xuất kho with auto COGS (632 / 1561)
     */
    public function test_sales_invoice_kiêm_phiếu_xuất_kho_auto_cogs(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_type' => 'Bán hàng kiêm phiếu xuất kho',
            'is_export_slip' => true,
            'is_include_delivery' => true,
            'payment_method' => 'unpaid',
            'invoice_number' => 'HDBH-EXPORT-001',
            'invoice_date' => '2026-08-05',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'quantity' => 3,
                    'unit_price' => 15000000,
                    'amount' => 45000000,
                    'tax_rate' => 10,
                    'tax_amount' => 4500000,
                    'credit_account' => '5111',
                    'cogs_price' => 10000000,
                    'cogs_unit_price' => 10000000,
                    'cogs_amount' => 30000000,
                    'cogs_account' => '632',
                    'inventory_account' => '1561',
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/sales/invoices', $payload);
        $res->assertStatus(201);
        $id = $res->json('id') ?? $res->json('data.id');

        $this->postJson("/api/v1/sales/invoices/{$id}/post")->assertStatus(200);

        $posted = SalesInvoice::with('journalEntry.lines')->find($id);
        $je = $posted->journalEntry;

        // Revenue: Nợ 131: 49,500,000 / Có 5111: 45,000,000 / Có 33311: 4,500,000
        $this->assertEquals(49500000, $je->lines->firstWhere('account_code', '131')->debit_amount);
        $this->assertEquals(45000000, $je->lines->firstWhere('account_code', '5111')->credit_amount);
        $this->assertEquals(4500000, $je->lines->firstWhere('account_code', '33311')->credit_amount);

        // COGS: Nợ 632: 30,000,000 / Có 1561: 30,000,000
        $debit632 = $je->lines->firstWhere('account_code', '632');
        $credit1561 = $je->lines->firstWhere('account_code', '1561');

        $this->assertNotNull($debit632);
        $this->assertNotNull($credit1561);
        $this->assertEquals(30000000, $debit632->debit_amount);
        $this->assertEquals(30000000, $credit1561->credit_amount);
    }

    /**
     * 3. Test Sales Quotes lifecycle, status transition, duplicate, references
     */
    public function test_sales_quotes_lifecycle_and_references(): void
    {
        // Next code
        $nextRes = $this->getJson('/api/v1/sales/quotes/next-code');
        $nextRes->assertStatus(200);
        $code = $nextRes->json('code') ?? $nextRes->json('data');
        $this->assertStringStartsWith('BG-2026-', $code);

        // Create
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'quote_number' => $code,
            'quote_date' => '2026-08-10',
            'expiry_date' => '2026-09-10',
            'status' => 'draft',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'quantity' => 5,
                    'unit_price' => 14500000,
                    'amount' => 72500000,
                    'tax_rate' => 10,
                    'tax_amount' => 7250000,
                ],
            ],
            'referenced_vouchers' => [
                [
                    'voucher_type' => 'Yêu cầu báo giá',
                    'voucher_number' => 'YCBG-001',
                    'voucher_date' => '2026-08-09',
                    'total_amount' => 72500000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/sales/quotes', $payload);
        $res->assertStatus(201);
        $id = $res->json('id') ?? $res->json('data.id');

        // Status update
        $this->postJson("/api/v1/sales/quotes/{$id}/status", ['status' => 'approved'])->assertStatus(200);
        $quote = SalesQuote::with('references')->find($id);
        $this->assertEquals('approved', $quote->status);
        $this->assertCount(1, $quote->references);

        // Duplicate
        $dupRes = $this->postJson("/api/v1/sales/quotes/{$id}/duplicate");
        $dupRes->assertStatus(201);
        $dupId = $dupRes->json('id') ?? $dupRes->json('data.id');
        $dupQuote = SalesQuote::find($dupId);
        $this->assertNotEquals($quote->quote_number, $dupQuote->quote_number);
        $this->assertEquals('draft', $dupQuote->status);
    }

    /**
     * 4. Test Sales Orders lifecycle, status transition, duplicate, references
     */
    public function test_sales_orders_lifecycle(): void
    {
        // Next code
        $nextRes = $this->getJson('/api/v1/sales/orders/next-code');
        $nextRes->assertStatus(200);
        $code = $nextRes->json('code') ?? $nextRes->json('data');
        $this->assertStringStartsWith('DDH-2026-', $code);

        // Create
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => $code,
            'order_date' => '2026-08-10',
            'delivery_date' => '2026-08-25',
            'status' => 'pending',
            'delivery_status' => 'not_delivered',
            'invoice_status' => 'not_invoiced',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'quantity' => 10,
                    'unit_price' => 14000000,
                    'amount' => 140000000,
                    'tax_rate' => 10,
                    'tax_amount' => 14000000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/sales/orders', $payload);
        $res->assertStatus(201);
        $id = $res->json('id') ?? $res->json('data.id');

        // Status update
        $this->postJson("/api/v1/sales/orders/{$id}/status", ['status' => 'confirmed'])->assertStatus(200);
        $order = SalesOrder::find($id);
        $this->assertEquals('confirmed', $order->status);

        // Duplicate
        $dupRes = $this->postJson("/api/v1/sales/orders/{$id}/duplicate");
        $dupRes->assertStatus(201);
    }

    /**
     * 5. Test Inventory Receipt multi-reasons and GL posting
     */
    public function test_inventory_receipt_multi_reasons(): void
    {
        // A. Nhập sản xuất (Nợ 155 / Có 154)
        $prodReceiptPayload = [
            'company_id' => $this->company->id,
            'voucher_type' => '2. Nhập kho từ sản xuất',
            'voucher_number' => 'PNK-PROD-001',
            'voucher_date' => '2026-08-11',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 10,
                    'unit_price' => 9500000,
                    'amount' => 95000000,
                    'debit_account' => '155',
                    'credit_account' => '154',
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/inventory/receipts', $prodReceiptPayload);
        $res->assertStatus(201);
        $id = $res->json('id') ?? $res->json('data.id');

        $this->postJson("/api/v1/inventory/receipts/{$id}/post")->assertStatus(200);

        $receipt = InventoryReceipt::with('journalEntry.lines')->find($id);
        $this->assertTrue($receipt->is_posted);
        $je = $receipt->journalEntry;
        $this->assertEquals(95000000, $je->lines->firstWhere('account_code', '155')->debit_amount);
        $this->assertEquals(95000000, $je->lines->firstWhere('account_code', '154')->credit_amount);
    }

    /**
     * 6. Test Inventory Issue multi-reasons and GL posting
     */
    public function test_inventory_issue_multi_reasons(): void
    {
        // The production issue guard checks available stock in the selected
        // warehouse. Seed the required quantity through the real receipt API
        // so this test exercises the GL reason mapping instead of relying on
        // the old implicit-zero-stock default.
        $openingReceipt = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_type' => '1. Nhập kho mua hàng',
            'voucher_number' => 'PNK-ISSUE-OPENING-001',
            'voucher_date' => '2026-08-01',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 2,
                    'unit_price' => 10000000,
                    'amount' => 20000000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $openingReceipt->assertStatus(201);
        $openingReceiptId = $openingReceipt->json('id') ?? $openingReceipt->json('data.id');
        $this->postJson("/api/v1/inventory/receipts/{$openingReceiptId}/post")->assertStatus(200);

        // Xuất sản xuất (Nợ 621 / Có 152)
        $issuePayload = [
            'company_id' => $this->company->id,
            'voucher_type' => '2. Xuất kho sản xuất',
            'voucher_number' => 'PXK-PROD-001',
            'voucher_date' => '2026-08-12',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 2,
                    'unit_price' => 10000000,
                    'amount' => 20000000,
                    'debit_account' => '621',
                    'credit_account' => '152',
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/inventory/issues', $issuePayload);
        $res->assertStatus(201);
        $id = $res->json('id') ?? $res->json('data.id');

        $this->postJson("/api/v1/inventory/issues/{$id}/post")->assertStatus(200);

        $issue = InventoryIssue::with('journalEntry.lines')->find($id);
        $this->assertTrue($issue->is_posted);
        $je = $issue->journalEntry;
        $this->assertEquals(20000000, $je->lines->firstWhere('account_code', '621')->debit_amount);
        $this->assertEquals(20000000, $je->lines->firstWhere('account_code', '152')->credit_amount);
    }

    /**
     * 7. Test Inventory Valuation Service & API endpoint (Weighted Average & FIFO)
     */
    public function test_inventory_valuation_and_cost_calculation_api(): void
    {
        // Step 1: Create Receipt 1 (10 units @ 10,000,000)
        $rcpt1 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_type' => '1. Nhập kho mua hàng',
            'voucher_number' => 'PNK-VAL-001',
            'voucher_date' => '2026-08-01',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 10,
                    'unit_price' => 10000000,
                    'amount' => 100000000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $rcpt1Id = $rcpt1->json('id') ?? $rcpt1->json('data.id');
        $this->postJson("/api/v1/inventory/receipts/{$rcpt1Id}/post")->assertStatus(200);

        // Step 2: Create Receipt 2 (10 units @ 12,000,000)
        $rcpt2 = $this->postJson('/api/v1/inventory/receipts', [
            'company_id' => $this->company->id,
            'voucher_type' => '1. Nhập kho mua hàng',
            'voucher_number' => 'PNK-VAL-002',
            'voucher_date' => '2026-08-10',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 10,
                    'unit_price' => 12000000,
                    'amount' => 120000000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $rcpt2Id = $rcpt2->json('id') ?? $rcpt2->json('data.id');
        $this->postJson("/api/v1/inventory/receipts/{$rcpt2Id}/post")->assertStatus(200);

        // Total available = 20 units, Total cost = 220,000,000 -> Weighted Average unit cost = 11,000,000

        // Step 3: Create Issue (4 units, initial temp unit price 0)
        $issue = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_type' => '1. Xuất kho bán hàng',
            'voucher_number' => 'PXK-VAL-001',
            'voucher_date' => '2026-08-15',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 4,
                    'unit_price' => 0,
                    'amount' => 0,
                    'debit_account' => '632',
                    'credit_account' => '1561',
                ],
            ],
        ]);
        $issueId = $issue->json('id') ?? $issue->json('data.id');
        $this->postJson("/api/v1/inventory/issues/{$issueId}/post")->assertStatus(200);

        // Step 4: Run Cost Calculation via API (Weighted Average)
        $calcRes = $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'weighted_average',
        ]);

        $calcRes->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify issue line was recalculated to 11,000,000 unit cost and 44,000,000 total amount
        $updatedIssue = InventoryIssue::with(['lines', 'journalEntry.lines'])->find($issueId);
        $this->assertEquals(44000000, $updatedIssue->total_amount);
        $this->assertEquals(11000000, $updatedIssue->lines[0]->unit_price);
        $this->assertEquals(44000000, $updatedIssue->lines[0]->amount);

        // Verify linked JournalEntry lines updated
        $this->assertEquals(44000000, $updatedIssue->journalEntry->total_amount);
        $this->assertEquals(44000000, $updatedIssue->journalEntry->lines->firstWhere('account_code', '632')->debit_amount);
        $this->assertEquals(44000000, $updatedIssue->journalEntry->lines->firstWhere('account_code', '1561')->credit_amount);
    }

    /**
     * 8. Test Stock Report balances
     */
    public function test_stock_report_balances_and_filters(): void
    {
        $reportRes = $this->getJson("/api/v1/inventory/stock-report?company_id={$this->company->id}&from_date=2026-08-01&to_date=2026-08-31");
        $reportRes->assertStatus(200);
        $this->assertIsArray($reportRes->json());
    }
}
