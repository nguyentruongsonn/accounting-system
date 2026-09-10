<?php

namespace Tests\Feature\MisaE2E;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VoucherReference;
use App\Models\Warehouse;
use App\Services\BankPaymentService;
use App\Services\BankReceiptService;
use App\Services\CashPaymentService;
use App\Services\CashReceiptService;
use App\Services\FinancialReportService;
use App\Services\InventoryIssueService;
use App\Services\InventoryReceiptService;
use App\Services\InventoryValuationService;
use App\Services\JournalEntryService;
use App\Services\PeriodService;
use App\Services\PurchaseInvoiceService;
use App\Services\SalesInvoiceService;
use App\Services\StockReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Tier2BoundaryCornerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected Customer $customer;

    protected Supplier $supplier;

    protected BankAccount $bankAccount;

    protected Warehouse $mainWarehouse;

    protected Warehouse $secondaryWarehouse;

    protected Item $productItem;

    protected Item $serviceItem;

    protected Item $rawMaterialItem;

    protected FinancialReportService $reportService;

    protected InventoryValuationService $valuationService;

    protected JournalEntryService $journalService;

    protected StockReportService $stockReportService;

    protected BankReceiptService $bankReceiptService;

    protected BankPaymentService $bankPaymentService;

    protected PurchaseInvoiceService $purchaseInvoiceService;

    protected SalesInvoiceService $salesInvoiceService;

    protected CashReceiptService $cashReceiptService;

    protected CashPaymentService $cashPaymentService;

    protected InventoryReceiptService $inventoryReceiptService;

    protected InventoryIssueService $inventoryIssueService;

    protected PeriodService $periodService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Company & Fiscal Year
        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Công ty TNHH MISA E2E Tier 2 Test',
                'tax_code' => '0101998877',
                'address' => 'Tòa nhà MISA, Cầu Giấy, Hà Nội',
            ]
        );

        $this->fiscalYear = FiscalYear::firstOrCreate(
            ['id' => 1],
            [
                'company_id' => $this->company->id,
                'name' => '2026',
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'open',
                'is_closed' => false,
            ]
        );

        // 2. Setup Authenticated User with Roles & Permissions
        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        // Use the canonical operational role. The previous
        // `chief_accountant` fixture is intentionally no longer accepted by
        // the two-role posting authorizer.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        $this->grantGlReportPermissions($this->user);

        Sanctum::actingAs($this->user);

        // 3. Seed Standard TT200 Chart of Accounts
        $this->seedChartOfAccounts();

        // 4. Seed Master Entities
        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH001',
            'name' => 'Công ty Cổ phần Alpha Global',
            'tax_code' => '0309876543',
            'address' => 'Số 10 Nguyễn Huệ, Quận 1, TP. Hồ Chí Minh',
            'contact_person' => 'Trần Văn Alpha',
            'phone' => '0909123456',
            'email' => 'contact@alphaglobal.vn',
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC001',
            'name' => 'Công ty TNHH Thiết bị Công nghệ Beta',
            'tax_code' => '0108765432',
            'address' => 'Số 88 Hoàng Quốc Việt, Cầu Giấy, Hà Nội',
            'contact_person' => 'Lê Thị Beta',
            'phone' => '0912987654',
            'email' => 'sales@betatech.vn',
        ]);

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '19039998888001',
            'bank_name' => 'Techcombank',
            'branch' => 'Chi nhánh Thăng Long, Hà Nội',
            'account_holder' => 'CONG TY TNHH MISA E2E TIER 2',
            'currency' => 'VND',
            'is_active' => true,
        ]);

        $this->mainWarehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO-CHINH',
            'name' => 'Kho Tổng Miền Bắc',
            'address' => 'Hà Nội',
        ]);

        $this->secondaryWarehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO-PHU',
            'name' => 'Kho Phụ Hà Đông',
            'address' => 'Hà Đông, Hà Nội',
        ]);

        $this->productItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'VT01',
            'name' => 'Máy chủ Dell PowerEdge R750',
            'type' => 'Goods',
            'unit' => 'Chiếc',
            'cost_price' => 50000000,
            'selling_price' => 70000000,
            'sale_price' => 70000000,
            'tax_rate' => 10,
            'inventory_account' => '1561',
            'cogs_account' => '632',
            'revenue_account' => '5111',
            'is_active' => true,
        ]);

        $this->serviceItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'DV01',
            'name' => 'Dịch vụ cài đặt và triển khai hệ thống',
            'type' => 'Service',
            'unit' => 'Gói',
            'cost_price' => 5000000,
            'selling_price' => 10000000,
            'sale_price' => 10000000,
            'tax_rate' => 10,
            'is_active' => true,
        ]);

        $this->rawMaterialItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'NVL01',
            'name' => 'Cáp quang sợi đơn single-mode',
            'type' => 'Goods',
            'unit' => 'Mét',
            'cost_price' => 15000,
            'selling_price' => 25000,
            'sale_price' => 25000,
            'tax_rate' => 10,
            'inventory_account' => '152',
            'is_active' => true,
        ]);

        // 5. Instantiate Domain Services
        $this->reportService = app(FinancialReportService::class);
        $this->valuationService = app(InventoryValuationService::class);
        $this->journalService = app(JournalEntryService::class);
        $this->stockReportService = app(StockReportService::class);
        $this->bankReceiptService = app(BankReceiptService::class);
        $this->bankPaymentService = app(BankPaymentService::class);
        $this->purchaseInvoiceService = app(PurchaseInvoiceService::class);
        $this->salesInvoiceService = app(SalesInvoiceService::class);
        $this->cashReceiptService = app(CashReceiptService::class);
        $this->cashPaymentService = app(CashPaymentService::class);
        $this->inventoryReceiptService = app(InventoryReceiptService::class);
        $this->inventoryIssueService = app(InventoryIssueService::class);
        $this->periodService = app(PeriodService::class);
    }

    /**
     * Helper to seed complete Chart of Accounts TT200
     */
    private function seedChartOfAccounts(): void
    {
        $accounts = [
            ['code' => '111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '1111', 'name' => 'Tiền Việt Nam', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '112', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng VND', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '133', 'name' => 'Thuế GTGT được khấu trừ', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '1331', 'name' => 'Thuế GTGT được khấu trừ của HHDV', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 1],
            ['code' => '13311', 'name' => 'Thuế GTGT đầu vào 10%', 'type' => 'asset', 'nature' => 'debit', 'level' => 3, 'is_parent' => 0],
            ['code' => '13312', 'name' => 'Thuế GTGT đầu vào 5%', 'type' => 'asset', 'nature' => 'debit', 'level' => 3, 'is_parent' => 0],
            ['code' => '13313', 'name' => 'Thuế GTGT đầu vào 8%', 'type' => 'asset', 'nature' => 'debit', 'level' => 3, 'is_parent' => 0],
            ['code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '153', 'name' => 'Công cụ, dụng cụ', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '155', 'name' => 'Thành phẩm', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '156', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '1562', 'name' => 'Chi phí thu mua hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '211', 'name' => 'Tài sản cố định hữu hình', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '214', 'name' => 'Hao mòn tài sản cố định', 'type' => 'asset', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '333', 'name' => 'Thuế và các khoản phải nộp Nhà nước', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 1],
            ['code' => '3331', 'name' => 'Thuế GTGT phải nộp', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => 1],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra 10%', 'type' => 'liability', 'nature' => 'credit', 'level' => 3, 'is_parent' => 0],
            ['code' => '33312', 'name' => 'Thuế GTGT đầu ra 5%', 'type' => 'liability', 'nature' => 'credit', 'level' => 3, 'is_parent' => 0],
            ['code' => '33313', 'name' => 'Thuế GTGT đầu ra 8%', 'type' => 'liability', 'nature' => 'credit', 'level' => 3, 'is_parent' => 0],
            ['code' => '334', 'name' => 'Phải trả người lao động', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '341', 'name' => 'Vay và nợ thuê tài chính', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '411', 'name' => 'Vốn đầu tư của chủ sở hữu', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '421', 'name' => 'Lợi nhuận sau thuế chưa phân phối', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => 1],
            ['code' => '4212', 'name' => 'Lợi nhuận sau thuế chưa phân phối năm nay', 'type' => 'equity', 'nature' => 'credit', 'level' => 2, 'is_parent' => 0],
            ['code' => '511', 'name' => 'Doanh thu bán hàng và cung cấp dịch vụ', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 1],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => 0],
            ['code' => '5112', 'name' => 'Doanh thu bán các thành phẩm', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => 0],
            ['code' => '5113', 'name' => 'Doanh thu cung cấp dịch vụ', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => 0],
            ['code' => '515', 'name' => 'Doanh thu hoạt động tài chính', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '521', 'name' => 'Các khoản giảm trừ doanh thu', 'type' => 'revenue', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '5211', 'name' => 'Chiết khấu thương mại', 'type' => 'revenue', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '635', 'name' => 'Chi phí tài chính', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '641', 'name' => 'Chi phí bán hàng', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '642', 'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '6421', 'name' => 'Chi phí nhân viên quản lý', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '6422', 'name' => 'Chi phí vật liệu quản lý', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '6427', 'name' => 'Chi phí dịch vụ mua ngoài', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '821', 'name' => 'Chi phí thuế TNDN', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '911', 'name' => 'Xác định kết quả kinh doanh', 'type' => 'revenue', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::firstOrCreate(
                ['company_id' => $this->company->id, 'code' => $acc['code']],
                array_merge($acc, ['company_id' => $this->company->id])
            );
        }
    }

    // =========================================================================
    // SECTION 1: ZERO & EXTREME VALUES
    // =========================================================================

    /**
     * Test 1: Zero amount line item handling and validation
     */
    public function test_zero_amount_line_item_handling_and_validation(): void
    {
        // 1. Bank Payment validation should reject zero line amount (min: 1)
        $paymentPayload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'UNC-ZERO-001',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'payee_name' => 'Nhà cung cấp Beta',
            'lines' => [
                [
                    'debit_account' => '331',
                    'credit_account' => '1121',
                    'amount' => 0, // Zero amount line
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/bank/payments', $paymentPayload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.amount']);

        // 2. Bank Receipt validation should reject zero line amount (min: 1)
        $receiptPayload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BC-ZERO-001',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'payer_name' => 'Khách hàng Alpha',
            'lines' => [
                [
                    'debit_account' => '1121',
                    'credit_account' => '131',
                    'amount' => 0,
                ],
            ],
        ];

        $responseReceipt = $this->postJson('/api/v1/bank/receipts', $receiptPayload);
        $responseReceipt->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.amount']);

        // 3. Sales Invoice with zero unit price (e.g. promotional gift/sample items) is allowed
        $salesPayload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-SAMPLE-001',
            'invoice_date' => '2026-08-15',
            'accounting_date' => '2026-08-15',
            'due_date' => '2026-09-15',
            'description' => 'Xuất hàng mẫu không thu tiền',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'tax_rate' => 0,
                ],
            ],
        ];

        $responseSales = $this->postJson('/api/v1/sales/invoices', $salesPayload);
        $responseSales->assertStatus(201)
            ->assertJsonPath('total_amount', 0);
    }

    /**
     * Test 2: Extreme large amount transactions and decimal precision (50+ Billion VND)
     */
    public function test_extreme_large_amount_transactions_and_decimal_precision(): void
    {
        // 50,000,000,000 VND (50 billion VND transaction)
        $quantity = 500;
        $unitPrice = 100000000; // 100M each -> Subtotal = 50,000,000,000 VND
        $taxAmount = 5000000000; // 5 Billion VAT
        $totalAmount = 55000000000; // 55 Billion VND

        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-MEGA-001',
            'invoice_date' => '2026-08-16',
            'accounting_date' => '2026-08-16',
            'due_date' => '2026-09-16',
            'description' => 'Mua thiết bị trung tâm dữ liệu quy mô quốc gia',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => 10,
                    'tax_amount' => $taxAmount,
                    'tax_account' => '13311',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201);
        $invoiceId = $response->json('id');

        $this->assertEquals(55000000000, $response->json('total_amount'));

        // Post to General Ledger and verify GL lines
        $postResponse = $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post");
        $postResponse->assertStatus(200);

        $invoice = PurchaseInvoice::with('lines')->find($invoiceId);
        $this->assertTrue((bool) $invoice->is_posted);

        $journalEntry = JournalEntry::with('lines')->find($invoice->journal_entry_id);
        $this->assertNotNull($journalEntry);
        $this->assertEquals('posted', $journalEntry->status);

        $debitSum = $journalEntry->lines->sum('debit_amount');
        $creditSum = $journalEntry->lines->sum('credit_amount');
        $this->assertEquals(55000000000, $debitSum);
        $this->assertEquals(55000000000, $creditSum);
    }

    /**
     * Test 3: Fractional quantity and unit price precision
     */
    public function test_fractional_quantity_and_unit_price_precision(): void
    {
        // Purchasing fractional goods: 125.75 meters at 15420.50 VND/meter
        $quantity = 125.75;
        $unitPrice = 15420.50;
        $expectedSubtotal = $quantity * $unitPrice; // 1,939,127.875
        $expectedTax = round($expectedSubtotal * 0.10); // 10% VAT
        $expectedTotal = $expectedSubtotal + $expectedTax;

        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-FRAC-001',
            'invoice_date' => '2026-08-16',
            'accounting_date' => '2026-08-16',
            'due_date' => '2026-09-16',
            'description' => 'Mua cáp quang đo theo mét lẻ',
            'lines' => [
                [
                    'item_id' => $this->rawMaterialItem->id,
                    'debit_account' => '152',
                    'credit_account' => '331',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => 10,
                    'tax_amount' => $expectedTax,
                    'tax_account' => '13311',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201);
        $invoiceId = $response->json('id');

        $this->assertEqualsWithDelta($expectedTotal, $response->json('total_amount'), 1.0);

        // Verify posting doesn't fail on float values
        $postResponse = $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post");
        $postResponse->assertStatus(200);

        $invoice = PurchaseInvoice::find($invoiceId);
        $this->assertTrue((bool) $invoice->is_posted);
    }

    /**
     * Test 4: Negative quantity rejection and return handling via credit notes
     */
    public function test_negative_quantity_or_return_handling(): void
    {
        // 1. Validation should reject negative quantity on Sales Invoice
        $invalidSalesPayload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-NEG-001',
            'invoice_date' => '2026-08-16',
            'accounting_date' => '2026-08-16',
            'due_date' => '2026-09-16',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => -5, // Negative quantity
                    'unit_price' => 70000000,
                    'tax_rate' => 10,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sales/invoices', $invalidSalesPayload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.quantity']);

        // 2. Legitimate sales return is recorded through double-entry credit adjustment in GL
        $returnEntry = $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'sales_return',
            'voucher_number' => 'PKT-RETURN-001',
            'voucher_date' => '2026-08-16',
            'posting_date' => '2026-08-16',
            'description' => 'Hàng bán bị trả lại giảm trừ doanh thu và giá vốn',
            'status' => 'posted',
            'lines' => [
                // Revenue deduction: Nợ 5211 / Có 131: 70,000,000
                ['debit_account' => '5211', 'credit_account' => '131', 'amount' => 70000000, 'description' => 'Giảm trừ doanh thu hàng trả lại'],
                // COGS reversal: Nợ 1561 / Có 632: 50,000,000
                ['debit_account' => '1561', 'credit_account' => '632', 'amount' => 50000000, 'description' => 'Nhập lại kho giảm giá vốn'],
            ],
        ]);

        $this->assertEquals('posted', $returnEntry->status);
        $this->assertEquals(120000000, $returnEntry->lines->sum('debit_amount'));
        $this->assertEquals(120000000, $returnEntry->lines->sum('credit_amount'));
    }

    // =========================================================================
    // SECTION 2: MULTI-TAX & TRADE DISCOUNT BOUNDARIES
    // =========================================================================

    /**
     * Test 5: Voucher with mixed VAT rates: 0%, 5%, 8%, 10%, and exempt
     */
    public function test_voucher_with_mixed_vat_rates_0_5_8_10_and_exempt(): void
    {
        // 5 lines with 5 distinct tax regimes
        $lines = [
            // Line 1: 0% VAT (Export or standard 0%) -> Amt 10,000,000, Tax 0
            [
                'item_id' => $this->productItem->id,
                'debit_account' => '1561',
                'credit_account' => '331',
                'quantity' => 1,
                'unit_price' => 10000000,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'tax_account' => '1331',
            ],
            // Line 2: 5% VAT (Agricultural/water/etc.) -> Amt 20,000,000, Tax 1,000,000
            [
                'item_id' => $this->productItem->id,
                'debit_account' => '1561',
                'credit_account' => '331',
                'quantity' => 2,
                'unit_price' => 10000000,
                'tax_rate' => 5,
                'tax_amount' => 1000000,
                'tax_account' => '13312',
            ],
            // Line 3: 8% VAT (VAT reduction decree) -> Amt 30,000,000, Tax 2,400,000
            [
                'item_id' => $this->productItem->id,
                'debit_account' => '1561',
                'credit_account' => '331',
                'quantity' => 3,
                'unit_price' => 10000000,
                'tax_rate' => 8,
                'tax_amount' => 2400000,
                'tax_account' => '13313',
            ],
            // Line 4: 10% VAT (Standard) -> Amt 40,000,000, Tax 4,000,000
            [
                'item_id' => $this->productItem->id,
                'debit_account' => '1561',
                'credit_account' => '331',
                'quantity' => 4,
                'unit_price' => 10000000,
                'tax_rate' => 10,
                'tax_amount' => 4000000,
                'tax_account' => '13311',
            ],
            // Line 5: Exempt / Non-taxable (KCT) -> Amt 5,000,000, Tax 0
            [
                'item_id' => $this->serviceItem->id,
                'debit_account' => '6427',
                'credit_account' => '331',
                'quantity' => 1,
                'unit_price' => 5000000,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'vat_group' => 'KCT',
            ],
        ];

        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-MULTI-TAX-001',
            'invoice_date' => '2026-08-16',
            'accounting_date' => '2026-08-16',
            'due_date' => '2026-09-16',
            'description' => 'Hóa đơn mua hàng tổng hợp nhiều thuế suất',
            'lines' => $lines,
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201);
        $invoiceId = $response->json('id');

        // Subtotal = 10M + 20M + 30M + 40M + 5M = 105,000,000
        // Total Tax = 0 + 1M + 2.4M + 4M + 0 = 7,400,000
        // Total Amount = 112,400,000
        $this->assertEquals(105000000, $response->json('sub_total'));
        $this->assertEquals(7400000, $response->json('tax_amount'));
        $this->assertEquals(112400000, $response->json('total_amount'));

        // Post to GL
        $postResponse = $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post");
        $postResponse->assertStatus(200);

        $invoice = PurchaseInvoice::find($invoiceId);
        $journalEntry = JournalEntry::with('lines')->find($invoice->journal_entry_id);

        $this->assertEquals(112400000, $journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(112400000, $journalEntry->lines->sum('credit_amount'));
    }

    /**
     * Test 6: Line-level trade discount and tax base calculation
     */
    public function test_line_level_trade_discount_and_tax_base_calculation(): void
    {
        // 10 units at 10,000,000 = 100,000,000
        // Trade discount 10% = 10,000,000
        // Net taxable amount = 90,000,000
        // Tax 10% on net = 9,000,000
        // Total line = 99,000,000
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-DISCOUNT-001',
            'invoice_date' => '2026-08-16',
            'accounting_date' => '2026-08-16',
            'due_date' => '2026-09-16',
            'description' => 'Bán hàng có chiết khấu thương mại dòng',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 10,
                    'unit_price' => 10000000,
                    'discount_rate' => 10,
                    'discount_amount' => 10000000,
                    'tax_rate' => 10,
                    'tax_amount' => 9000000,
                    'tax_account' => '33311',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sales/invoices', $payload);
        $response->assertStatus(201);
        $invoiceId = $response->json('id');

        $line = SalesInvoiceLine::where('sales_invoice_id', $invoiceId)->first();
        $this->assertNotNull($line);
        $this->assertEquals(10000000, $line->discount_amount);
        $this->assertEquals(9000000, $line->tax_amount);
    }

    /**
     * Test 7: Multi-line invoice with rounding precision reconciliation
     */
    public function test_multi_line_invoice_with_rounding_precision_reconciliation(): void
    {
        // 10 lines of small uneven amounts
        $lines = [];
        $accumulatedSubtotal = 0;
        $accumulatedTax = 0;

        for ($i = 1; $i <= 10; $i++) {
            $qty = 3;
            $price = 33333; // 99,999 VND per line
            $tax = round($qty * $price * 0.10); // 10,000 VND
            $accumulatedSubtotal += ($qty * $price);
            $accumulatedTax += $tax;

            $lines[] = [
                'item_id' => $this->rawMaterialItem->id,
                'debit_account' => '152',
                'credit_account' => '331',
                'quantity' => $qty,
                'unit_price' => $price,
                'tax_rate' => 10,
                'tax_amount' => $tax,
                'tax_account' => '13311',
            ];
        }

        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-ROUND-001',
            'invoice_date' => '2026-08-16',
            'accounting_date' => '2026-08-16',
            'due_date' => '2026-09-16',
            'description' => 'Hóa đơn 10 dòng làm tròn số học',
            'lines' => $lines,
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201);
        $invoiceId = $response->json('id');

        $this->assertEquals($accumulatedSubtotal, $response->json('sub_total'));
        $this->assertEquals($accumulatedTax, $response->json('tax_amount'));
        $this->assertEquals($accumulatedSubtotal + $accumulatedTax, $response->json('total_amount'));

        // Post to GL and verify perfect zero discrepancy
        $postResponse = $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post");
        $postResponse->assertStatus(200);

        $invoice = PurchaseInvoice::find($invoiceId);
        $je = JournalEntry::with('lines')->find($invoice->journal_entry_id);
        $this->assertEquals($je->lines->sum('debit_amount'), $je->lines->sum('credit_amount'));
    }

    // =========================================================================
    // SECTION 3: BANK & CASH BOUNDARIES
    // =========================================================================

    /**
     * Test 8: Bank payment fee bearer options (Buyer pays fee vs Supplier pays fee)
     */
    public function test_bank_payment_fee_bearer_options_buyer_vs_seller(): void
    {
        // Buyer bears bank transfer fee: UNC includes payment to supplier (30M) + bank fee line (33,000 VND Nợ 6427 / Có 1121)
        $payloadBuyerBearsFee = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'UNC-FEE-BUYER-001',
            'voucher_date' => '2026-08-17',
            'posting_date' => '2026-08-17',
            'payee_name' => 'Công ty Beta',
            'description' => 'Chi trả tiền hàng Beta kèm phí chuyển tiền đơn vị chịu',
            'lines' => [
                [
                    'debit_account' => '331',
                    'credit_account' => '1121',
                    'amount' => 30000000,
                    'description' => 'Thanh toán tiền hàng cho Beta',
                ],
                [
                    'debit_account' => '6427',
                    'credit_account' => '1121',
                    'amount' => 33000,
                    'description' => 'Phí chuyển khoản ngân hàng (Đơn vị chịu)',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/bank/payments', $payloadBuyerBearsFee);
        $response->assertStatus(201);
        $paymentId = $response->json('id');

        $this->assertEquals(30033000, $response->json('amount'));

        // Post to GL
        $postResponse = $this->postJson("/api/v1/bank/payments/{$paymentId}/post");
        $postResponse->assertStatus(200);

        $payment = BankPayment::find($paymentId);
        $journalEntry = JournalEntry::with('lines')->find($payment->journal_entry_id);

        $this->assertEquals(30033000, $journalEntry->lines->sum('debit_amount'));
        $this->assertEquals(30033000, $journalEntry->lines->sum('credit_amount'));
    }

    /**
     * Test 9: UNC missing source or destination account validation
     */
    public function test_unc_missing_source_or_destination_account_validation(): void
    {
        // 1. Missing bank_account_id
        $resp1 = $this->postJson('/api/v1/bank/payments', [
            'company_id' => $this->company->id,
            'voucher_number' => 'UNC-NO-BANK-001',
            'voucher_date' => '2026-08-17',
            'posting_date' => '2026-08-17',
            'lines' => [
                ['debit_account' => '331', 'credit_account' => '1121', 'amount' => 1000000],
            ],
        ]);
        $resp1->assertStatus(422)->assertJsonValidationErrors(['bank_account_id']);

        // 2. Non-existent bank_account_id
        $resp2 = $this->postJson('/api/v1/bank/payments', [
            'company_id' => $this->company->id,
            'bank_account_id' => 999999, // Non-existent
            'voucher_number' => 'UNC-BAD-BANK-001',
            'voucher_date' => '2026-08-17',
            'posting_date' => '2026-08-17',
            'lines' => [
                ['debit_account' => '331', 'credit_account' => '1121', 'amount' => 1000000],
            ],
        ]);
        $resp2->assertStatus(422)->assertJsonValidationErrors(['bank_account_id']);

        // 3. Empty lines array
        $resp3 = $this->postJson('/api/v1/bank/payments', [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'UNC-EMPTY-LINES-001',
            'voucher_date' => '2026-08-17',
            'posting_date' => '2026-08-17',
            'lines' => [],
        ]);
        $resp3->assertStatus(422)->assertJsonValidationErrors(['lines']);
    }

    /**
     * Test 10: Bank receipt duplicate bank transaction code validation
     */
    public function test_bank_receipt_duplicate_bank_transaction_code(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BC-UNIQUE-TEST-001',
            'voucher_date' => '2026-08-17',
            'posting_date' => '2026-08-17',
            'payer_name' => 'Khách hàng Alpha',
            'lines' => [
                ['debit_account' => '1121', 'credit_account' => '131', 'amount' => 5000000],
            ],
        ];

        // First creation succeeds
        $resp1 = $this->postJson('/api/v1/bank/receipts', $payload);
        $resp1->assertStatus(201);

        // Second creation with identical voucher_number must fail with 422
        $resp2 = $this->postJson('/api/v1/bank/receipts', $payload);
        $resp2->assertStatus(422)->assertJsonValidationErrors(['voucher_number']);
    }

    // =========================================================================
    // SECTION 4: INVENTORY & COSTING BOUNDARIES
    // =========================================================================

    /**
     * Test 11: Inventory issue with zero stock and cost resolution
     */
    public function test_inventory_issue_with_zero_stock_and_cost_resolution(): void
    {
        // Create an item with 0 initial stock and 0 receipts
        $zeroStockItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'VT-ZERO-STOCK',
            'name' => 'Hàng hóa chưa từng nhập kho',
            'type' => 'Goods',
            'unit' => 'Cái',
            'cost_price' => 0,
            'selling_price' => 100000,
            'sale_price' => 100000,
            'inventory_account' => '1561',
            'is_active' => true,
        ]);

        // Attempting to calculate moving average cost on 0 stock should safely return 0
        $cost = $this->valuationService->getMovingAverageCost($zeroStockItem->id, $this->company->id);
        $this->assertEquals(0, $cost);

        // Creating an inventory issue for this item
        $issue = $this->inventoryIssueService->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-ZERO-STOCK-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'description' => 'Xuất kho hàng tồn bằng 0',
            'lines' => [
                ['item_id' => $zeroStockItem->id, 'quantity' => 10],
            ],
        ]);

        $this->assertEquals(0, $issue->total_amount);
        $this->assertEquals(0, $issue->lines->first()->unit_price);

        // Production posting is fail-closed when no positive monetary GL line
        // exists; a source must not become posted without a journal entry.
        // Disable the separate stock-availability guard for this focused
        // assertion so the test reaches the zero-value journal invariant
        // rather than failing earlier on the intentionally empty stock.
        config()->set('accounting.enforce_inventory_stock_availability', false);
        try {
            $this->inventoryIssueService->post($issue->id);
            $this->fail('Zero-cost inventory issue must be rejected before posting.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }
        $issue->refresh();
        $this->assertFalse((bool) $issue->is_posted);
        $this->assertNull($issue->journal_entry_id);
    }

    /**
     * Test 12: Inventory cost calculation on empty period
     */
    public function test_inventory_cost_calculation_on_empty_period(): void
    {
        // When there are no inventory transactions at all in the database
        $report = $this->stockReportService->generateReport($this->company->id);
        $this->assertIsArray($report);
        // Should contain 0 active item rows since no receipts or issues were posted
        $this->assertEmpty($report);
    }

    /**
     * Test 13: Inventory cost calculation with only opening balance and no receipts
     */
    public function test_inventory_cost_calculation_with_only_opening_balance_and_no_receipts(): void
    {
        // 1. Initial receipt in January: 100 units @ 50,000 VND = 5,000,000 VND
        $receipt = $this->inventoryReceiptService->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-INIT-001',
            'voucher_date' => '2026-01-10',
            'posting_date' => '2026-01-10',
            'warehouse_id' => $this->mainWarehouse->id,
            'description' => 'Nhập kho đầu kỳ',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->mainWarehouse->id,
                    'quantity' => 100,
                    'unit_price' => 50000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $this->inventoryReceiptService->post($receipt->id);

        // 2. In February (no new receipts), issue 25 units
        $febCost = $this->valuationService->getMovingAverageCost($this->productItem->id, $this->company->id, '2026-02-15');
        $this->assertEquals(50000, $febCost);

        $issue = $this->inventoryIssueService->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-FEB-001',
            'voucher_date' => '2026-02-15',
            'posting_date' => '2026-02-15',
            'description' => 'Xuất kho tháng 2',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->mainWarehouse->id,
                    'quantity' => 25,
                ],
            ],
        ]);
        $this->inventoryIssueService->post($issue->id);

        $this->assertEquals(1250000, $issue->total_amount); // 25 * 50,000

        // 3. Check Stock Report reflects remaining 75 units and 3,750,000 VND
        $report = $this->stockReportService->generateReport($this->company->id);
        $productRow = collect($report)->firstWhere('item_id', $this->productItem->id);

        $this->assertNotNull($productRow);
        $this->assertEquals(100, $productRow['in_qty']);
        $this->assertEquals(5000000, $productRow['in_amt']);
        $this->assertEquals(25, $productRow['out_qty']);
        $this->assertEquals(1250000, $productRow['out_amt']);
        $this->assertEquals(75, $productRow['end_qty']);
        $this->assertEquals(3750000, $productRow['end_amt']);
    }

    /**
     * Test 14: Warehouse transfer between warehouses tracking
     */
    public function test_warehouse_transfer_same_source_and_destination_validation(): void
    {
        // 1. Inward receipt into Main Warehouse
        $receipt = $this->inventoryReceiptService->create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-WH-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'warehouse_id' => $this->mainWarehouse->id,
            'description' => 'Nhập hàng vào Kho Tổng',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->mainWarehouse->id,
                    'quantity' => 50,
                    'unit_price' => 50000000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $this->inventoryReceiptService->post($receipt->id);

        // 2. Transfer from Main Warehouse to Secondary Warehouse via General Journal entry
        // Nợ 1561 (Kho phụ) / Có 1561 (Kho chính): 10 units @ 50M = 500,000,000 VND
        $transferEntry = $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'warehouse_transfer',
            'voucher_number' => 'PKT-CK-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'description' => 'Chuyển kho nội bộ từ Kho Tổng sang Kho Phụ',
            'status' => 'posted',
            'lines' => [
                [
                    'debit_account' => '1561',
                    'credit_account' => '1561',
                    'amount' => 500000000,
                    'description' => 'Điều chuyển 10 chiếc Dell PowerEdge sang Kho Phụ Hà Đông',
                ],
            ],
        ]);

        $this->assertEquals('posted', $transferEntry->status);
        $this->assertEquals(500000000, $transferEntry->total_amount);
    }

    // =========================================================================
    // SECTION 5: PERIOD CLOSING BOUNDARIES
    // =========================================================================

    /**
     * Test 15: Period closing execution on empty financial period
     */
    public function test_period_closing_execution_on_empty_financial_period(): void
    {
        // 1. Create a fresh Period with no transactions
        $period = $this->periodService->create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_number' => 1,
            'name' => 'Tháng 01/2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);

        // 2. Financial Reports should return clean zeroes
        $trialBalance = $this->reportService->getTrialBalance($this->company->id);
        $this->assertNotEmpty($trialBalance);
        $this->assertEquals(0, $trialBalance->sum('arising_debit'));
        $this->assertEquals(0, $trialBalance->sum('arising_credit'));

        $incomeStatement = $this->reportService->getIncomeStatement($this->company->id);
        $this->assertNotEmpty($incomeStatement);
        $netProfitRow = collect($incomeStatement)->firstWhere('code', '60');
        $this->assertEquals(0, $netProfitRow['this_period']);

        // 3. A bare period flag is not closing evidence. The direct endpoint
        // must not bypass the server-generated closing workflow.
        $response = $this->postJson('/api/v1/gl/periods/close', [
            'period_id' => $period->id,
        ]);
        $response->assertStatus(409);
        $this->assertFalse((bool) $period->fresh()->is_closed);
    }

    /**
     * Test 16: Period closing idempotency on repeated runs
     */
    public function test_period_closing_idempotency_on_repeated_runs(): void
    {
        $period = $this->periodService->create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_number' => 2,
            'name' => 'Tháng 02/2026',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
        ]);

        // Direct close is deliberately rejected on every attempt; idempotency
        // belongs to the generated closing-voucher workflow instead.
        $resp1 = $this->postJson('/api/v1/gl/periods/close', ['period_id' => $period->id]);
        $resp1->assertStatus(409);

        // Run 2 remains a safe rejection and does not mutate the period.
        $resp2 = $this->postJson('/api/v1/gl/periods/close', ['period_id' => $period->id]);
        $resp2->assertStatus(409);

        $period->refresh();
        $this->assertFalse((bool) $period->is_closed);
    }

    /**
     * Test 17: Period closing with loss (Debit 4212 transfer)
     */
    public function test_period_closing_with_loss_debit_4212_transfer(): void
    {
        // 1. Post Revenue: 20,000,000 VND (Nợ 1111 / Có 5111)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'sales_revenue',
            'voucher_number' => 'PKT-REV-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'description' => 'Doanh thu bán hàng tháng 8',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '1111', 'credit_account' => '5111', 'amount' => 20000000],
            ],
        ]);

        // 2. Post Admin Expense: 50,000,000 VND (Nợ 6422 / Có 1111) -> Loss of 30,000,000 VND
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'admin_expense',
            'voucher_number' => 'PKT-EXP-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'description' => 'Chi phí quản lý doanh nghiệp tháng 8',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '6422', 'credit_account' => '1111', 'amount' => 50000000],
            ],
        ]);

        // 3. Verify Income Statement shows negative net profit (-30,000,000 VND)
        $incomeStatement = $this->reportService->getIncomeStatement($this->company->id);
        $profitRow = collect($incomeStatement)->firstWhere('code', '60');
        $this->assertEquals(-30000000, $profitRow['this_period']);

        // 4. Execute Period Closing Entries (911 -> 4212 loss transfer):
        // Revenue transfer: Nợ 5111 / Có 911: 20,000,000
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'closing_revenue',
            'voucher_number' => 'KC-REV-001',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-08-31',
            'description' => 'Kết chuyển doanh thu sang 911',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '5111', 'credit_account' => '911', 'amount' => 20000000],
            ],
        ]);

        // Expense transfer: Nợ 911 / Có 6422: 50,000,000
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'closing_expense',
            'voucher_number' => 'KC-EXP-001',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-08-31',
            'description' => 'Kết chuyển chi phí sang 911',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '911', 'credit_account' => '6422', 'amount' => 50000000],
            ],
        ]);

        // Result transfer (Loss -> Nợ 4212 / Có 911: 30,000,000)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'closing_result',
            'voucher_number' => 'KC-LOSS-001',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-08-31',
            'description' => 'Kết chuyển lỗ hoạt động kinh doanh vào 4212',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '4212', 'credit_account' => '911', 'amount' => 30000000],
            ],
        ]);

        // 5. Verify 911 Account is fully zeroed out on Trial Balance
        $trialBalance = $this->reportService->getTrialBalance($this->company->id);
        $acc911 = $trialBalance->firstWhere('code', '911');
        $this->assertNotNull($acc911);
        $this->assertEquals(50000000, $acc911['arising_debit']);
        $this->assertEquals(50000000, $acc911['arising_credit']);
        $this->assertEquals(0, $acc911['ending_debit']);
        $this->assertEquals(0, $acc911['ending_credit']);
    }

    /**
     * Test 18: Period closing date overlap and boundary validation
     */
    public function test_period_closing_date_overlap_validation(): void
    {
        // 1. Inverted date range (end_date before start_date)
        $resp1 = $this->postJson('/api/v1/gl/periods', [
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_number' => 3,
            'name' => 'Kỳ sai ngày',
            'start_date' => '2026-03-31',
            'end_date' => '2026-03-01', // Before start date
        ]);
        $resp1->assertStatus(422)->assertJsonValidationErrors(['end_date']);

        // 2. Invalid period number (> 12 or < 1)
        $resp2 = $this->postJson('/api/v1/gl/periods', [
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_number' => 13,
            'name' => 'Kỳ 13 không hợp lệ',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);
        $resp2->assertStatus(422)->assertJsonValidationErrors(['period_number']);
    }

    // =========================================================================
    // SECTION 6: VOUCHER LIFECYCLE & REFERENCE EDGE CASES
    // =========================================================================

    /**
     * Test 19: Unposting already unposted voucher is idempotent or handled cleanly
     */
    public function test_unposting_already_unposted_voucher_is_idempotent(): void
    {
        // Create draft bank payment
        $payment = $this->bankPaymentService->create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'UNC-UNPOST-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'lines' => [
                ['debit_account' => '331', 'credit_account' => '1121', 'amount' => 10000000],
            ],
        ]);

        $this->assertFalse((bool) $payment->is_posted);

        // Calling unpost on unposted voucher returns 400 with descriptive error
        $voidResp = $this->postJson("/api/v1/bank/payments/{$payment->id}/void");
        $voidResp->assertStatus(400)
            ->assertJsonPath('error', 'Voucher is not posted yet');

        // Post the voucher
        $postResp = $this->postJson("/api/v1/bank/payments/{$payment->id}/post");
        $postResp->assertStatus(200);

        // Calling post again on already posted voucher returns 400
        $rePostResp = $this->postJson("/api/v1/bank/payments/{$payment->id}/post");
        $rePostResp->assertStatus(400)
            ->assertJsonPath('error', 'Voucher is already posted');
    }

    /**
     * Test 20: Prevent editing or deleting posted voucher without unposting (or auto-voiding GL)
     */
    public function test_prevent_editing_or_deleting_posted_voucher_without_unposting(): void
    {
        // Create and post Bank Payment
        $payment = $this->bankPaymentService->create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'UNC-DELETE-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'lines' => [
                ['debit_account' => '331', 'credit_account' => '1121', 'amount' => 20000000],
            ],
        ]);
        $this->bankPaymentService->post($payment->id);
        $payment->refresh();
        $this->assertTrue((bool) $payment->is_posted);
        $jeId = $payment->journal_entry_id;

        // Delete the voucher: BankPaymentService cleanly voids the linked JournalEntry before deletion
        $delResp = $this->deleteJson("/api/v1/bank/payments/{$payment->id}");
        $delResp->assertStatus(200);

        $this->assertDatabaseMissing('bank_payments', ['id' => $payment->id]);

        $linkedJe = JournalEntry::find($jeId);
        $this->assertEquals('voided', $linkedJe->status);
    }

    /**
     * Test 21: Voucher number sequential generation and uniqueness
     */
    public function test_voucher_number_sequential_generation_and_uniqueness_under_concurrency(): void
    {
        // 1. Test next-code endpoints
        $poCodeResp = $this->getJson('/api/v1/purchase/orders/next-code');
        $poCodeResp->assertStatus(200);
        $poCode = $poCodeResp->json('code');
        // The current controller contract uses the Vietnamese purchase-order
        // prefix; do not infer a PO prefix from an English test label.
        $this->assertStringStartsWith('ĐMH', $poCode);

        $contractCodeResp = $this->getJson('/api/v1/purchase/contracts/next-code');
        $contractCodeResp->assertStatus(200);
        $contractCode = $contractCodeResp->json('code');
        // Preserve the controller's Vietnamese contract prefix (HĐM).
        $this->assertStringStartsWith('HĐM', $contractCode);

        $itemCodeResp = $this->getJson('/api/v1/inventory/items/next-code');
        $itemCodeResp->assertStatus(200);
        $itemCode = $itemCodeResp->json('code');
        $this->assertStringStartsWith('VT', $itemCode);

        // 2. Sequential uniqueness check
        PurchaseOrder::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_code' => $this->supplier->code,
            'supplier_name' => $this->supplier->name,
            'order_number' => $poCode,
            'order_date' => '2026-08-18',
            'total_amount' => 10000000,
        ]);

        $poCodeResp2 = $this->getJson('/api/v1/purchase/orders/next-code');
        $poCodeResp2->assertStatus(200);
        $nextPoCode = $poCodeResp2->json('code');
        $this->assertNotEquals($poCode, $nextPoCode);
    }

    /**
     * Test 22: Voucher reference attaching invalid or soft deleted target
     */
    public function test_voucher_reference_attaching_invalid_or_soft_deleted_target(): void
    {
        $payment = $this->bankPaymentService->create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'UNC-REF-TARGET-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'lines' => [
                ['debit_account' => '331', 'credit_account' => '1121', 'amount' => 5000000],
            ],
        ]);

        // Attach reference pointing to non-existent ID
        $ref = VoucherReference::create([
            'source_type' => 'App\Models\BankPayment',
            'source_id' => $payment->id,
            'target_type' => 'App\Models\PurchaseOrder',
            'target_id' => 9999999, // Non-existent target
            'target_voucher_type' => 'Đơn mua hàng',
            'target_voucher_number' => 'PO-NON-EXISTENT',
            'target_voucher_date' => '2026-08-01',
            'target_total_amount' => 5000000,
            'description' => 'Tham chiếu tới đơn hàng không tồn tại',
        ]);

        // Assert polymorphic relation returns null safely without throwing exception
        $this->assertNull($ref->target);

        // Voucher search endpoint works without throwing 500
        $searchResp = $this->getJson('/api/v1/voucher-references/search');
        $searchResp->assertStatus(200)->assertJsonPath('success', true);
    }

    /**
     * Test 23: Circular reference prevention in voucher references
     */
    public function test_circular_reference_prevention_in_voucher_references(): void
    {
        // Create 2 Sales Invoices
        $invA = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-CIRC-A',
            'invoice_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'sub_total' => 10000000,
            'total_amount' => 11000000,
        ]);

        $invB = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-CIRC-B',
            'invoice_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'sub_total' => 20000000,
            'total_amount' => 22000000,
        ]);

        // A references B
        VoucherReference::create([
            'source_type' => 'App\Models\SalesInvoice',
            'source_id' => $invA->id,
            'target_type' => 'App\Models\SalesInvoice',
            'target_id' => $invB->id,
            'target_voucher_type' => 'Hóa đơn bán hàng',
            'target_voucher_number' => $invB->invoice_number,
            'target_total_amount' => $invB->total_amount,
        ]);

        // B references A (Circular)
        VoucherReference::create([
            'source_type' => 'App\Models\SalesInvoice',
            'source_id' => $invB->id,
            'target_type' => 'App\Models\SalesInvoice',
            'target_id' => $invA->id,
            'target_voucher_type' => 'Hóa đơn bán hàng',
            'target_voucher_number' => $invA->invoice_number,
            'target_total_amount' => $invA->total_amount,
        ]);

        // Assert JSON serialization does not enter infinite recursion
        $jsonA = $invA->load('references')->toArray();
        $this->assertIsArray($jsonA);
        $this->assertCount(1, $jsonA['references']);

        $jsonB = $invB->load('references')->toArray();
        $this->assertIsArray($jsonB);
        $this->assertCount(1, $jsonB['references']);
    }

    /**
     * Test 24: Bulk unpost and post integrity
     */
    public function test_bulk_unpost_and_post_integrity(): void
    {
        $vouchers = [];

        // Create 5 Bank Receipts
        for ($i = 1; $i <= 5; $i++) {
            $receipt = $this->bankReceiptService->create([
                'company_id' => $this->company->id,
                'bank_account_id' => $this->bankAccount->id,
                'voucher_number' => "BC-BULK-00{$i}",
                'voucher_date' => '2026-08-18',
                'posting_date' => '2026-08-18',
                'lines' => [
                    ['debit_account' => '1121', 'credit_account' => '131', 'amount' => 10000000],
                ],
            ]);
            $vouchers[] = $receipt;
        }

        // Post all 5
        foreach ($vouchers as $v) {
            $this->bankReceiptService->post($v->id);
            $v->refresh();
            $this->assertTrue((bool) $v->is_posted);
            $this->assertNotNull($v->journal_entry_id);
        }

        // Check Trial Balance reflects 50,000,000 arising debit on 1121
        $tbPosted = $this->reportService->getTrialBalance($this->company->id);
        $acc1121 = $tbPosted->firstWhere('code', '1121');
        $this->assertEquals(50000000, $acc1121['arising_debit']);

        // Void / Unpost all 5
        foreach ($vouchers as $v) {
            $this->bankReceiptService->void($v->id);
            $v->refresh();
            $this->assertFalse((bool) $v->is_posted);
        }

        // Check Trial Balance reflects 0 active arising balance
        $tbVoided = $this->reportService->getTrialBalance($this->company->id);
        $acc1121Voided = $tbVoided->firstWhere('code', '1121');
        $this->assertEquals(0, $acc1121Voided['arising_debit']);
    }

    /**
     * Test 25: Financial reports with zero opening and zero arising balances
     */
    public function test_financial_reports_with_zero_opening_and_zero_arising_balances(): void
    {
        // 1. Trial Balance
        $tbResponse = $this->getJson('/api/v1/reports/trial-balance');
        $tbResponse->assertStatus(200);
        $tbData = $tbResponse->json();
        $this->assertIsArray($tbData);
        $this->assertNotEmpty($tbData);

        $totalEndingDebit = collect($tbData)->sum('ending_debit');
        $totalEndingCredit = collect($tbData)->sum('ending_credit');
        $this->assertEquals(0, $totalEndingDebit);
        $this->assertEquals(0, $totalEndingCredit);

        // 2. Balance Sheet
        $bsResponse = $this->getJson('/api/v1/reports/balance-sheet');
        $bsResponse->assertStatus(200);
        $bsData = $bsResponse->json();

        $totalAssets = collect($bsData['assets'])->sum('end_balance');
        $totalLiabilities = collect($bsData['liabilities'])->sum('end_balance');
        $totalEquity = collect($bsData['equity'])->sum('end_balance');

        $this->assertEquals(0, $totalAssets);
        $this->assertEquals(0, $totalLiabilities + $totalEquity);
        // Accounting fundamental equation: Assets = Liabilities + Equity
        $this->assertEquals($totalAssets, $totalLiabilities + $totalEquity);

        // 3. Income Statement
        $isResponse = $this->getJson('/api/v1/reports/income-statement');
        $isResponse->assertStatus(200);
        $isData = $isResponse->json();

        $revenue = collect($isData)->firstWhere('code', '01')['this_period'];
        $profit = collect($isData)->firstWhere('code', '60')['this_period'];
        $this->assertEquals(0, $revenue);
        $this->assertEquals(0, $profit);
    }

    // =========================================================================
    // SECTION 7: ADDITIONAL ADVERSARIAL & DEEP CORNER TESTS
    // =========================================================================

    /**
     * Test 26: Adversarial malformed inputs, special characters and SQL injection resilience
     */
    public function test_adversarial_malformed_json_and_sql_injection_on_boundary_inputs(): void
    {
        $sqlPayload = "' OR '1'='1' -- /*";
        $xssPayload = '<script>alert("XSS")</script>';
        $unicodePayload = '🔥 Phí dịch vụ 測試 100% #!@#$%^&*()_+';

        $response = $this->getJson('/api/v1/voucher-references/search?keyword='.urlencode($sqlPayload));
        $response->assertStatus(200)->assertJsonPath('success', true);

        $response2 = $this->getJson('/api/v1/voucher-references/search?keyword='.urlencode($xssPayload));
        $response2->assertStatus(200)->assertJsonPath('success', true);

        $response3 = $this->getJson('/api/v1/voucher-references/search?keyword='.urlencode($unicodePayload));
        $response3->assertStatus(200)->assertJsonPath('success', true);
    }

    /**
     * Test 27: Sales Invoice export slip toggle COGS GL entry generation
     */
    public function test_sales_invoice_export_slip_toggle_cogs_gl_generation(): void
    {
        // 1. Without export slip (is_export_slip = false): GL only has Revenue & AR
        $invNoSlip = $this->salesInvoiceService->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-NO-SLIP-001',
            'invoice_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'is_export_slip' => false,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 1,
                    'unit_price' => 70000000,
                    'tax_rate' => 10,
                    'cogs_price' => 50000000,
                ],
            ],
        ]);
        $invNoSlip = $this->salesInvoiceService->post($invNoSlip->id);
        $jeNoSlip = JournalEntry::with('lines')->find($invNoSlip->journal_entry_id);
        // Only 3 lines: Debit 131, Credit 5111, Credit 33311 (no 632 / 1561)
        $this->assertCount(3, $jeNoSlip->lines);
        $this->assertNull($jeNoSlip->lines->firstWhere('account_code', '632'));

        // 2. With export slip (is_export_slip = true): GL includes COGS (632 / 1561)
        $invWithSlip = $this->salesInvoiceService->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-WITH-SLIP-001',
            'invoice_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'is_export_slip' => true,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 1,
                    'unit_price' => 70000000,
                    'tax_rate' => 10,
                    'cogs_price' => 50000000,
                    'cogs_account' => '632',
                    'inventory_account' => '1561',
                ],
            ],
        ]);
        $invWithSlip = $this->salesInvoiceService->post($invWithSlip->id);
        $jeWithSlip = JournalEntry::with('lines')->find($invWithSlip->journal_entry_id);
        // 5 lines: Debit 131, Credit 5111, Credit 33311, Debit 632, Credit 1561
        $this->assertCount(5, $jeWithSlip->lines);
        $this->assertNotNull($jeWithSlip->lines->firstWhere('account_code', '632'));
        $this->assertNotNull($jeWithSlip->lines->firstWhere('account_code', '1561'));
    }

    /**
     * Test 28: Purchase invoice inward stock vs direct expense account posting
     */
    public function test_purchase_invoice_inward_vs_direct_expense_account_posting(): void
    {
        // 1. Inward Stock Purchase -> Debit 1561
        $invStock = $this->purchaseInvoiceService->create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-STOCK-001',
            'invoice_date' => '2026-08-18',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 2,
                    'unit_price' => 50000000,
                    'tax_rate' => 10,
                    'tax_amount' => 10000000,
                    'tax_account' => '13311',
                ],
            ],
        ]);
        $invStock = $this->purchaseInvoiceService->post($invStock->id);
        $jeStock = JournalEntry::with('lines')->find($invStock->journal_entry_id);
        $this->assertNotNull($jeStock->lines->firstWhere('account_code', '1561'));

        // 2. Direct Expense Service -> Debit 6427
        $invService = $this->purchaseInvoiceService->create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-SRV-001',
            'invoice_date' => '2026-08-18',
            'lines' => [
                [
                    'item_id' => $this->serviceItem->id,
                    'debit_account' => '6427',
                    'credit_account' => '331',
                    'quantity' => 1,
                    'unit_price' => 10000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1000000,
                    'tax_account' => '13311',
                ],
            ],
        ]);
        $invService = $this->purchaseInvoiceService->post($invService->id);
        $jeService = JournalEntry::with('lines')->find($invService->journal_entry_id);
        $this->assertNotNull($jeService->lines->firstWhere('account_code', '6427'));
    }

    /**
     * Test 29: Cash receipt sanitizes unauthorized fields according to reason
     */
    public function test_cash_receipt_sanitize_unauthorized_fields_by_reason(): void
    {
        // When reason is customer payment, loan contract and employee fields should be sanitized
        $receipt = $this->cashReceiptService->create([
            'company_id' => $this->company->id,
            'voucher_type' => '1. Thu tiền khách hàng (không theo hóa đơn)',
            'voucher_number' => 'PT-SANITIZE-001',
            'voucher_date' => '2026-08-18',
            'employee_id' => 999, // Should be sanitized to null
            'lines' => [
                [
                    'debit_account' => '1111',
                    'credit_account' => '131',
                    'amount' => 15000000,
                    'loan_contract' => 'KU-UNAUTHORIZED-01', // Should be sanitized to null
                ],
            ],
        ]);

        $this->assertNull($receipt->employee_id);
        $this->assertNull($receipt->lines->first()->loan_contract);
    }

    /**
     * Test 30: General journal rejects unbalanced debit-credit input
     */
    public function test_general_journal_unbalanced_debit_credit_rejection(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Double-entry validation failed: Total Debit (10000000) does not equal Total Credit (9000000)');

        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-UNBALANCED-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'description' => 'Chứng từ mất cân đối nợ có',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => 10000000, 'credit_amount' => 0],
                ['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 9000000],
            ],
        ]);
    }
}
