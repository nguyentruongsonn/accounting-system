<?php

namespace Tests\Feature\MisaE2E;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryReceipt;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\PurchaseContract;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VoucherReference;
use App\Models\Warehouse;
use App\Services\BankPaymentService;
use App\Services\BankReceiptService;
use App\Services\FinancialReportService;
use App\Services\InventoryIssueService;
use App\Services\InventoryReceiptService;
use App\Services\InventoryValuationService;
use App\Services\JournalEntryService;
use App\Services\PeriodClosingService;
use App\Services\PeriodService;
use App\Services\PurchaseInvoiceService;
use App\Services\SalesInvoiceService;
use App\Services\SalesOrderService;
use App\Services\SalesQuoteService;
use App\Services\StockReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Tier5AdversarialStressTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected Customer $customer;

    protected Supplier $supplier;

    protected BankAccount $bankAccountVnd;

    protected BankAccount $bankAccountUsd;

    protected Warehouse $warehouseHn;

    protected Warehouse $warehouseHcm;

    protected Item $productItemA;

    protected Item $productItemB;

    protected Item $serviceItem;

    protected Item $zeroCostGiftItem;

    protected FinancialReportService $reportService;

    protected InventoryValuationService $valuationService;

    protected JournalEntryService $journalService;

    protected StockReportService $stockReportService;

    protected BankReceiptService $bankReceiptService;

    protected BankPaymentService $bankPaymentService;

    protected PurchaseInvoiceService $purchaseInvoiceService;

    protected SalesInvoiceService $salesInvoiceService;

    protected SalesOrderService $salesOrderService;

    protected SalesQuoteService $salesQuoteService;

    protected InventoryReceiptService $inventoryReceiptService;

    protected InventoryIssueService $inventoryIssueService;

    protected PeriodService $periodService;

    protected PeriodClosingService $periodClosingService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Company & Fiscal Year
        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'CÔNG TY TNHH MISA TIER 5 ADVERSARIAL TEST',
                'tax_code' => '0109998888',
                'address' => 'Tầng 10, Tòa nhà MISA Tower, Cầu Giấy, Hà Nội',
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
        // Use the canonical two-role policy in stress fixtures. The production
        // authorizer deliberately rejects legacy role names such as
        // `chief_accountant` instead of allowing tests to bypass RBAC.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        $this->grantGlReportPermissions($this->user);

        Sanctum::actingAs($this->user);

        // 3. Seed Chart of Accounts
        $this->seedChartOfAccounts();

        // 4. Seed Master Entities
        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH_ADV_01',
            'name' => 'Tập đoàn Công nghệ Alpha Tech',
            'tax_code' => '0301122334',
            'address' => 'Số 100 Lê Duẩn, Quận 1, TP. Hồ Chí Minh',
            'contact_person' => 'Nguyễn Văn Alpha',
            'phone' => '0901234567',
            'email' => 'alpha@techcorp.vn',
            'is_customer' => true,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC_ADV_01',
            'name' => 'Tổng Công ty Phân phối Thiết bị Beta',
            'tax_code' => '0105566778',
            'address' => 'Số 50 Duy Tân, Cầu Giấy, Hà Nội',
            'contact_person' => 'Trần Thị Beta',
            'phone' => '0912345678',
            'email' => 'beta@distributor.vn',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        $this->bankAccountVnd = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '111222333444',
            'bank_name' => 'Vietcombank',
            'bank_code' => 'VCB',
            'branch' => 'Sở Giao dịch Hà Nội',
            'account_holder' => 'CONG TY TNHH MISA TIER 5',
            'currency' => 'VND',
            'is_active' => true,
        ]);

        $this->bankAccountUsd = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '999888777666',
            'bank_name' => 'Techcombank',
            'bank_code' => 'TCB',
            'branch' => 'Chi nhánh Ba Đình',
            'account_holder' => 'CONG TY TNHH MISA TIER 5',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->warehouseHn = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO_HN',
            'name' => 'Kho Tổng Miền Bắc (Hà Nội)',
            'address' => 'Cụm Công nghiệp Nam Từ Liêm, Hà Nội',
            'is_active' => true,
        ]);

        $this->warehouseHcm = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO_HCM',
            'name' => 'Kho Phân Phối Miền Nam (TP.HCM)',
            'address' => 'Khu Chế Xuất Tân Thuận, Quận 7, TP.HCM',
            'is_active' => true,
        ]);

        $this->productItemA = Item::create([
            'company_id' => $this->company->id,
            'code' => 'ITEM_SERVER_X1',
            'name' => 'Máy chủ Dell Enterprise X1',
            'type' => 'Goods',
            'unit' => 'Bộ',
            'cost_price' => 20000000,
            'purchase_price' => 20000000,
            'selling_price' => 30000000,
            'tax_rate' => 10,
            'inventory_account' => '1561',
            'cogs_account' => '632',
            'revenue_account' => '5111',
            'is_active' => true,
        ]);

        $this->productItemB = Item::create([
            'company_id' => $this->company->id,
            'code' => 'ITEM_ROUTER_R2',
            'name' => 'Bộ định tuyến Cisco Router R2',
            'type' => 'Goods',
            'unit' => 'Chiếc',
            'cost_price' => 5000000,
            'purchase_price' => 5000000,
            'selling_price' => 8000000,
            'tax_rate' => 5,
            'inventory_account' => '1561',
            'cogs_account' => '632',
            'revenue_account' => '5111',
            'is_active' => true,
        ]);

        $this->serviceItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'SVC_MAINTENANCE',
            'name' => 'Dịch vụ bảo trì và vận hành hệ thống IT',
            'type' => 'Service',
            'unit' => 'Gói',
            'cost_price' => 0,
            'purchase_price' => 0,
            'selling_price' => 12000000,
            'tax_rate' => 10,
            'revenue_account' => '5113',
            'is_active' => true,
        ]);

        $this->zeroCostGiftItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'GIFT_PROMO',
            'name' => 'Quà tặng khuyến mại 0 đồng',
            'type' => 'Goods',
            'unit' => 'Cái',
            'cost_price' => 0,
            'purchase_price' => 0,
            'selling_price' => 0,
            'tax_rate' => 0,
            'inventory_account' => '1561',
            'cogs_account' => '632',
            'revenue_account' => '5111',
            'is_active' => true,
        ]);

        // 5. Instantiate Services
        $this->journalService = app(JournalEntryService::class);
        $this->reportService = app(FinancialReportService::class);
        $this->valuationService = app(InventoryValuationService::class);
        $this->stockReportService = app(StockReportService::class);
        $this->bankReceiptService = app(BankReceiptService::class);
        $this->bankPaymentService = app(BankPaymentService::class);
        $this->purchaseInvoiceService = app(PurchaseInvoiceService::class);
        $this->salesInvoiceService = app(SalesInvoiceService::class);
        $this->salesOrderService = app(SalesOrderService::class);
        $this->salesQuoteService = app(SalesQuoteService::class);
        $this->inventoryReceiptService = app(InventoryReceiptService::class);
        $this->inventoryIssueService = app(InventoryIssueService::class);
        $this->periodService = app(PeriodService::class);
        $this->periodClosingService = app(PeriodClosingService::class);
    }

    private function seedChartOfAccounts(): void
    {
        $accounts = [
            ['code' => '111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1111', 'name' => 'Tiền Việt Nam', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1112', 'name' => 'Ngoại tệ', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '112', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1121', 'name' => 'Tiền Việt Nam tại NH', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1122', 'name' => 'Ngoại tệ tại NH', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '133', 'name' => 'Thuế GTGT được khấu trừ', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1331', 'name' => 'Thuế GTGT được khấu trừ của HHTT&DV', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '156', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1562', 'name' => 'Chi phí thu mua hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '333', 'name' => 'Thuế và các khoản phải nộp Nhà nước', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '3331', 'name' => 'Thuế GTGT phải nộp', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '411', 'name' => 'Vốn đầu tư của chủ sở hữu', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '4111', 'name' => 'Vốn góp của chủ sở hữu', 'type' => 'equity', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '421', 'name' => 'Lợi nhuận sau thuế chưa phân phối', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '4212', 'name' => 'Lợi nhuận sau thuế chưa phân phối năm nay', 'type' => 'equity', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '511', 'name' => 'Doanh thu bán hàng và cung cấp DV', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '5113', 'name' => 'Doanh thu cung cấp dịch vụ', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '515', 'name' => 'Doanh thu hoạt động tài chính', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '635', 'name' => 'Chi phí tài chính', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '642', 'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '911', 'name' => 'Xác định kết quả kinh doanh', 'type' => 'equity', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::create(array_merge($acc, [
                'company_id' => $this->company->id,
                'is_active' => true,
            ]));
        }
    }

    // =========================================================================
    // 1. BANK MODULE ADVERSARIAL STRESS TESTS
    // =========================================================================

    /**
     * Bank Test 1: Large transfers (50 billion VND) & zero fee bearer handling
     */
    public function test_bank_large_transfers_and_zero_fee_bearer_handling(): void
    {
        $largeAmount = 50000000000.0; // 50 Billion VND

        // 1. Create large Bank Payment (UNC) with zero fee and 'buyer' fee bearer
        $paymentPayload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'supplier_payment',
            'voucher_number' => 'UNC-50B-001',
            'contact_id' => $this->supplier->id,
            'contact_type' => 'supplier',
            'contact_name' => $this->supplier->name,
            'bank_account_id' => $this->bankAccountVnd->id,
            'payee_name' => $this->supplier->name,
            'payee_bank_account' => '999988887777',
            'payee_bank_name' => 'VietinBank',
            'fee_bearer' => 'buyer',
            'description' => 'Ủy nhiệm chi chuyển khoản lô thanh toán quy mô lớn 50 tỷ VND',
            'voucher_date' => '2026-08-01',
            'currency' => 'VND',
            'exchange_rate' => 1,
            'lines' => [
                [
                    'description' => 'Thanh toán đợt 1 tiền mua hàng thiết bị hạ tầng',
                    'debit_account' => '331',
                    'credit_account' => '1121',
                    'amount' => $largeAmount,
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/bank/payments', $paymentPayload);
        $resp->assertStatus(201);
        $paymentId = $resp->json('id');
        $this->assertEquals($largeAmount, (float) $resp->json('amount'));

        // 2. Post payment to GL
        $postResp = $this->postJson("/api/v1/bank/payments/{$paymentId}/post");
        $postResp->assertStatus(200);

        $postedPayment = BankPayment::find($paymentId);
        $this->assertTrue((bool) $postedPayment->is_posted);

        // Verify Journal Entry
        $jeId = $postedPayment->journal_entry_id;
        $this->assertNotNull($jeId);
        $je = JournalEntry::with('lines')->find($jeId);
        $this->assertEquals('posted', $je->status);
        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');
        $this->assertEquals($largeAmount, (float) $totalDebit);
        $this->assertEquals($largeAmount, (float) $totalCredit);

        // 3. Unpost payment
        $unpostResp = $this->postJson("/api/v1/bank/payments/{$paymentId}/unpost");
        $unpostResp->assertStatus(200);
        $this->assertFalse((bool) BankPayment::find($paymentId)->is_posted);

        // 4. Duplicate payment creates independent voucher
        $dupResp = $this->postJson("/api/v1/bank/payments/{$paymentId}/duplicate");
        $dupResp->assertStatus(201);
        $dupId = $dupResp->json('id');
        $this->assertNotEquals($paymentId, $dupId);
        $this->assertEquals('draft', $dupResp->json('status'));
        $this->assertEquals($largeAmount, (float) $dupResp->json('amount'));
    }

    /**
     * Bank Test 2: Foreign currencies (USD) and exchange rate multiplication
     */
    public function test_bank_foreign_currency_transactions_and_exchange_rates(): void
    {
        $usdAmount = 25000.0;
        $exchangeRate = 25450.0;
        $expectedVnd = $usdAmount * $exchangeRate; // 636,250,000 VND

        // 1. Create USD Bank Receipt (Báo Có)
        $receiptPayload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'customer_payment',
            'voucher_number' => 'BC-USD-001',
            'contact_id' => $this->customer->id,
            'contact_type' => 'customer',
            'contact_name' => $this->customer->name,
            'bank_account_id' => $this->bankAccountUsd->id,
            'payer_name' => $this->customer->name,
            'payer_bank_account' => 'USD-INTL-987654321',
            'description' => 'Thu tiền xuất khẩu phần mềm bằng ngoại tệ USD',
            'voucher_date' => '2026-08-05',
            'currency' => 'USD',
            'exchange_rate' => $exchangeRate,
            'lines' => [
                [
                    'description' => 'Khách hàng thanh toán hợp đồng dịch vụ công nghệ số (USD)',
                    'debit_account' => '1122',
                    'credit_account' => '131',
                    'amount' => $expectedVnd,
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/bank/receipts', $receiptPayload);
        $resp->assertStatus(201);
        $receiptId = $resp->json('id');
        $this->assertEquals('USD', $resp->json('currency'));
        $this->assertEquals($exchangeRate, (float) $resp->json('exchange_rate'));
        $this->assertEquals($expectedVnd, (float) $resp->json('amount'));

        // 2. Post USD receipt
        $postResp = $this->postJson("/api/v1/bank/receipts/{$receiptId}/post");
        $postResp->assertStatus(200);

        // Verify GL Balances on 1122 (Foreign currency at bank)
        $balances = $this->reportService->getAccountBalances($this->company->id);
        $bankUsdEnding = $this->reportService->getEndingBalance($balances, '1122', 'debit');
        $this->assertEquals($expectedVnd, $bankUsdEnding);
    }

    /**
     * Bank Test 3: Invalid account numbers, boundary strings, and SQL injection sanitization
     */
    public function test_bank_invalid_or_missing_account_numbers_and_sanitization(): void
    {
        $maliciousPayeeAccount = "'; DROP TABLE bank_accounts; -- <script>alert(1)</script>";
        $longDescription = str_repeat('MISA_BANK_VOUCHER_STRESS_TEST_', 30);

        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'other_payment',
            'voucher_number' => 'UNC-SAN-001',
            'contact_name' => 'Đơn vị thụ hưởng đặc biệt',
            'bank_account_id' => $this->bankAccountVnd->id,
            'payee_name' => "Robert'); DROP TABLE users;--",
            'payee_bank_account' => $maliciousPayeeAccount,
            'payee_bank_name' => 'Ngân hàng Quốc tế Á Châu',
            'description' => $longDescription,
            'voucher_date' => '2026-08-08',
            'currency' => 'VND',
            'exchange_rate' => 1,
            'lines' => [
                [
                    'description' => 'Chi phí hoa hồng môi giới',
                    'debit_account' => '642',
                    'credit_account' => '1121',
                    'amount' => 15000000,
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/bank/payments', $payload);
        $resp->assertStatus(201);
        $paymentId = $resp->json('id');

        // Verify table still exists and data was escaped safely
        $this->assertDatabaseHas('bank_payments', [
            'id' => $paymentId,
            'payee_bank_account' => $maliciousPayeeAccount,
        ]);

        $postResp = $this->postJson("/api/v1/bank/payments/{$paymentId}/post");
        $postResp->assertStatus(200);
    }

    // =========================================================================
    // 2. PURCHASE MODULE ADVERSARIAL STRESS TESTS
    // =========================================================================

    /**
     * Purchase Test 4: Complex multi-rate VAT with freight landed cost allocation and unposting
     */
    public function test_purchase_complex_multirate_vat_with_freight_landed_cost_allocation(): void
    {
        // Line 1: Item A (10 units @ 10,000,000 = 100M, 0% VAT, Freight allocated 2,000,000 -> Stock Value 102M)
        // Line 2: Item B (20 units @ 5,000,000 = 100M, 5% VAT = 5M, Freight allocated 3,000,000 -> Stock Value 103M)
        // Line 3: Item A (5 units @ 20,000,000 = 100M, 10% VAT = 10M, Freight allocated 5,000,000 -> Stock Value 105M)
        // Total Subtotal = 300,000,000 VND. Total VAT = 15,000,000 VND. Freight = 10,000,000 VND.
        // Total Payable to Supplier (Nợ 1561: 300M, Nợ 1331: 15M / Có 331: 315M).

        $piPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'invoice_number' => 'HDMH-MULTI-VAT-01',
            'invoice_date' => '2026-08-10',
            'accounting_date' => '2026-08-10',
            'due_date' => '2026-09-10',
            'voucher_type' => 'domestic_inward',
            'payment_method' => 'unpaid',
            'description' => 'Mua hàng nhập kho đa thuế suất VAT (0%, 5%, 10%) kèm phân bổ phí vận chuyển',
            'purchase_expense' => 10000000,
            'lines' => [
                [
                    'item_id' => $this->productItemA->id,
                    'description' => 'Máy chủ Dell Enterprise X1 (0% VAT)',
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 10000000,
                    'amount' => 100000000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'tax_account' => '1331',
                    'purchase_expense' => 2000000,
                    'stock_value' => 102000000,
                ],
                [
                    'item_id' => $this->productItemB->id,
                    'description' => 'Bộ định tuyến Cisco Router R2 (5% VAT)',
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 20,
                    'unit_price' => 5000000,
                    'amount' => 100000000,
                    'tax_rate' => 5,
                    'tax_amount' => 5000000,
                    'tax_account' => '1331',
                    'purchase_expense' => 3000000,
                    'stock_value' => 103000000,
                ],
                [
                    'item_id' => $this->productItemA->id,
                    'description' => 'Máy chủ Dell Enterprise X1 Lô 2 (10% VAT)',
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 5,
                    'unit_price' => 20000000,
                    'amount' => 100000000,
                    'tax_rate' => 10,
                    'tax_amount' => 10000000,
                    'tax_account' => '1331',
                    'purchase_expense' => 5000000,
                    'stock_value' => 105000000,
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/purchase/invoices', $piPayload);
        $resp->assertStatus(201);
        $piId = $resp->json('id');
        $this->assertEquals(300000000, (float) $resp->json('sub_total'));
        $this->assertEquals(15000000, (float) $resp->json('tax_amount'));
        $this->assertEquals(315000000, (float) $resp->json('total_amount'));
        $this->assertEquals(10000000, (float) $resp->json('purchase_expense'));

        $storedPi = PurchaseInvoice::find($piId);
        $this->assertEquals(310000000, (float) $storedPi->total_stock_value);

        // Post Purchase Invoice
        $postResp = $this->postJson("/api/v1/purchase/invoices/{$piId}/post");
        $postResp->assertStatus(200);

        // Verify GL Balances: AP (331) = 315M, Deductible VAT (1331) = 15M, Inventory (1561) = 300M
        $balances = $this->reportService->getAccountBalances($this->company->id);
        $apCredit = $this->reportService->getEndingBalance($balances, '331', 'credit');
        $vatDebit = $this->reportService->getEndingBalance($balances, '1331', 'debit');
        $invDebit = $this->reportService->getEndingBalance($balances, '1561', 'debit');

        $this->assertEquals(315000000, $apCredit);
        $this->assertEquals(15000000, $vatDebit);
        $this->assertEquals(300000000, $invDebit);
    }

    /**
     * Purchase Test 5: Purchase Invoice unposting, editing, and reposting lifecycle integrity
     */
    public function test_purchase_invoice_unposting_and_reposting_lifecycle(): void
    {
        // 1. Create Purchase Invoice
        $piPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-LIFECYCLE-01',
            'invoice_date' => '2026-08-12',
            'voucher_type' => 'domestic_inward',
            'payment_method' => 'unpaid',
            'lines' => [
                [
                    'item_id' => $this->productItemA->id,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 2,
                    'unit_price' => 20000000,
                    'amount' => 40000000,
                    'tax_rate' => 10,
                    'tax_amount' => 4000000,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/purchase/invoices', $piPayload);
        $resp->assertStatus(201);
        $piId = $resp->json('id');

        // 2. Post
        $this->postJson("/api/v1/purchase/invoices/{$piId}/post")->assertStatus(200);
        $balancesAfterPost = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(44000000, $this->reportService->getEndingBalance($balancesAfterPost, '331', 'credit'));

        // 3. Unpost
        $unpostResp = $this->postJson("/api/v1/purchase/invoices/{$piId}/unpost");
        $unpostResp->assertStatus(200);
        $this->assertFalse((bool) PurchaseInvoice::find($piId)->is_posted);

        // Verify balances after unpost are zeroed
        $balancesAfterUnpost = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(0, $this->reportService->getEndingBalance($balancesAfterUnpost, '331', 'credit'));

        // 4. Update while unposted
        $updateResp = $this->putJson("/api/v1/purchase/invoices/{$piId}", [
            'description' => 'Hóa đơn đã được điều chỉnh số lượng sau thương thảo',
            'lines' => [
                [
                    'item_id' => $this->productItemA->id,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 3,
                    'unit_price' => 20000000,
                    'amount' => 60000000,
                    'tax_rate' => 10,
                    'tax_amount' => 6000000,
                    'tax_account' => '1331',
                ],
            ],
        ]);
        $updateResp->assertStatus(200);
        $this->assertEquals(66000000, (float) $updateResp->json('total_amount'));

        // 5. Repost
        $repostResp = $this->postJson("/api/v1/purchase/invoices/{$piId}/post");
        $repostResp->assertStatus(200);
        $this->assertTrue((bool) PurchaseInvoice::find($piId)->is_posted);

        $balancesAfterRepost = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(66000000, $this->reportService->getEndingBalance($balancesAfterRepost, '331', 'credit'));
    }

    /**
     * Purchase Test 6: Purchase Order partial linkage and contract tracking
     */
    public function test_purchase_order_partial_linkage_and_contract_tracking(): void
    {
        // 1. Create Purchase Contract
        $contract = PurchaseContract::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'contract_number' => 'HDNT-2026-001',
            'signed_date' => '2026-08-01',
            'total_amount' => 500000000,
            'status' => 'active',
            'description' => 'Hợp đồng khung mua sắm thiết bị viễn thông năm 2026',
        ]);

        // 2. Create Purchase Order for 50 units
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'order_number' => 'PO-2026-ADV-01',
            'order_date' => '2026-08-05',
            'expected_delivery_date' => '2026-08-25',
            'sub_total' => 250000000,
            'tax_amount' => 25000000,
            'total_amount' => 275000000,
            'status' => 'approved',
            'description' => 'Đơn mua hàng đợt 1 theo hợp đồng HDNT-2026-001',
        ]);

        // 3. Purchase Invoice 1: Receive 20 units
        $pi1Resp = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-PO-PARTIAL-01',
            'invoice_date' => '2026-08-10',
            'lines' => [
                [
                    'item_id' => $this->productItemB->id,
                    'quantity' => 20,
                    'unit_price' => 5000000,
                    'amount' => 100000000,
                    'tax_rate' => 10,
                    'tax_amount' => 10000000,
                    'order_id' => $po->id,
                    'contract_id' => $contract->id,
                ],
            ],
        ]);
        $pi1Resp->assertStatus(201);
        $pi1Id = $pi1Resp->json('id');

        // Link references
        VoucherReference::create([
            'source_type' => PurchaseInvoice::class,
            'source_id' => $pi1Id,
            'target_type' => PurchaseOrder::class,
            'target_id' => $po->id,
            'target_voucher_type' => 'Đơn mua hàng',
            'target_voucher_number' => $po->order_number,
            'target_voucher_date' => $po->order_date,
            'target_total_amount' => $po->total_amount,
            'description' => 'Nhập kho đợt 1 (20/50 cái)',
        ]);

        // 4. Verify reference search API finds linkage
        $refSearch = $this->getJson("/api/v1/voucher-references/search?search_by=voucher_number&keyword={$po->order_number}");
        $refSearch->assertStatus(200);
        $this->assertNotEmpty($refSearch->json('data'));
    }

    // =========================================================================
    // 3. SALES MODULE ADVERSARIAL STRESS TESTS
    // =========================================================================

    /**
     * Sales Test 7: Sales Order partial delivery and invoicing flow (Quote -> Order -> Invoices)
     */
    public function test_sales_order_partial_delivery_and_invoicing_flow(): void
    {
        // 1. Create Sales Quote
        $quote = SalesQuote::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'quote_number' => 'BG-2026-001',
            'quote_date' => '2026-08-01',
            'valid_until' => '2026-08-31',
            'sub_total' => 300000000,
            'vat_amount' => 30000000,
            'total_amount' => 330000000,
            'status' => 'accepted',
            'description' => 'Báo giá cung cấp 10 bộ máy chủ Dell X1',
        ]);

        // 2. Convert Quote to Sales Order for 10 units
        $order = SalesOrder::create([
            'company_id' => $this->company->id,
            'sales_quote_id' => $quote->id,
            'quote_id' => $quote->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'order_number' => 'DH-2026-001',
            'order_date' => '2026-08-03',
            'delivery_date' => '2026-08-20',
            'sub_total' => 300000000,
            'vat_amount' => 30000000,
            'total_amount' => 330000000,
            'grand_total' => 330000000,
            'status' => 'confirmed',
            'delivery_status' => 'partially_delivered',
            'invoice_status' => 'partially_invoiced',
        ]);

        $order->lines()->create([
            'item_id' => $this->productItemA->id,
            'item_code' => $this->productItemA->code,
            'item_name' => $this->productItemA->name,
            'quantity' => 10,
            'delivered_quantity' => 4,
            'invoiced_quantity' => 4,
            'unit_price' => 30000000,
            'amount' => 300000000,
            'tax_rate' => 10,
            'tax_amount' => 30000000,
            'total_amount' => 330000000,
        ]);

        // 3. Partial Delivery 1: Invoice kiêm PXK for 4 units
        $si1Resp = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-DH-01',
            'invoice_date' => '2026-08-05',
            'is_export_slip' => true,
            'payment_method' => 'unpaid',
            'lines' => [
                [
                    'item_id' => $this->productItemA->id,
                    'quantity' => 4,
                    'unit_price' => 30000000,
                    'cogs_price' => 20000000,
                    'tax_rate' => 10,
                    'order_id' => $order->id,
                ],
            ],
        ]);
        $si1Resp->assertStatus(201);
        $si1Id = $si1Resp->json('id');
        $this->assertEquals(132000000, (float) $si1Resp->json('total_amount'));

        // Post Delivery 1
        $this->postJson("/api/v1/sales/invoices/{$si1Id}/post")->assertStatus(200);

        // 4. Delivery 2: Remaining 6 units
        $si2Resp = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-DH-02',
            'invoice_date' => '2026-08-15',
            'is_export_slip' => true,
            'payment_method' => 'unpaid',
            'lines' => [
                [
                    'item_id' => $this->productItemA->id,
                    'quantity' => 6,
                    'unit_price' => 30000000,
                    'cogs_price' => 20000000,
                    'tax_rate' => 10,
                    'order_id' => $order->id,
                ],
            ],
        ]);
        $si2Resp->assertStatus(201);
        $si2Id = $si2Resp->json('id');
        $this->assertEquals(198000000, (float) $si2Resp->json('total_amount'));

        // Post Delivery 2
        $this->postJson("/api/v1/sales/invoices/{$si2Id}/post")->assertStatus(200);

        // Total AR (131) should equal full quote/order amount: 330,000,000 VND
        $balances = $this->reportService->getAccountBalances($this->company->id);
        $arDebit = $this->reportService->getEndingBalance($balances, '131', 'debit');
        $this->assertEquals(330000000, $arDebit);
    }

    /**
     * Sales Test 8: Sales Invoice kiêm PXK with zero-cost item fallback and service lines
     */
    public function test_sales_invoice_kiem_pxk_with_zero_cost_item_fallback(): void
    {
        // Line 1: Goods item with cost (5 units @ 30M = 150M, cogs_price = 20M -> COGS = 100M)
        // Line 2: Service item (1 package @ 12M = 12M, cogs_price = 0)
        // Line 3: Zero-cost gift promo item (2 units @ 0 VND = 0 VND, cogs_price = 0)
        // Total Subtotal = 162M. VAT 10% = 16.2M. Total Amount = 178.2M.

        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-ZERO-COST-01',
            'invoice_date' => '2026-08-16',
            'is_export_slip' => true,
            'payment_method' => 'unpaid',
            'description' => 'Bán hàng kèm dịch vụ và quà tặng 0 đồng',
            'lines' => [
                [
                    'item_id' => $this->productItemA->id,
                    'quantity' => 5,
                    'unit_price' => 30000000,
                    'amount' => 150000000,
                    'tax_rate' => 10,
                    'tax_amount' => 15000000,
                    'cogs_price' => 20000000,
                    'cogs_amount' => 100000000,
                    'credit_account' => '5111',
                ],
                [
                    'item_id' => $this->serviceItem->id,
                    'quantity' => 1,
                    'unit_price' => 12000000,
                    'amount' => 12000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1200000,
                    'cogs_price' => 0,
                    'cogs_amount' => 0,
                    'credit_account' => '5113',
                ],
                [
                    'item_id' => $this->zeroCostGiftItem->id,
                    'quantity' => 2,
                    'unit_price' => 0,
                    'amount' => 0,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'cogs_price' => 0,
                    'cogs_amount' => 0,
                    'credit_account' => '5111',
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/sales/invoices', $payload);
        $resp->assertStatus(201);
        $siId = $resp->json('id');
        $this->assertEquals(162000000, (float) $resp->json('sub_total'));
        $this->assertEquals(16200000, (float) $resp->json('tax_amount'));
        $this->assertEquals(178200000, (float) $resp->json('total_amount'));

        // Post to GL
        $postResp = $this->postJson("/api/v1/sales/invoices/{$siId}/post");
        $postResp->assertStatus(200);

        // Verify Journal Entry:
        // Revenue lines: Debit 131 (178.2M) / Credit 5111 (150M), Credit 5113 (12M), Credit 33311 (16.2M)
        // COGS lines: Debit 632 (100M) / Credit 1561 (100M)
        $je = JournalEntry::with('lines')->find($postResp->json('journal_entry_id'));
        $this->assertNotNull($je);

        $line632 = $je->lines->where('account_code', '632')->first();
        $this->assertNotNull($line632);
        $this->assertEquals(100000000, (float) $line632->debit_amount);

        // Ensure no zero-amount GL lines were created
        $zeroLines = $je->lines->where('debit_amount', 0)->where('credit_amount', 0);
        $this->assertCount(0, $zeroLines);
    }

    /**
     * Sales Test 9: Sales bank settlement and invoice reconciliation
     */
    public function test_sales_bank_settlement_and_invoice_reconciliation(): void
    {
        // 1. Create Sales Invoice for 110,000,000 VND
        $siResp = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-SETTLE-01',
            'invoice_date' => '2026-08-18',
            'payment_method' => 'unpaid',
            'lines' => [
                [
                    'item_id' => $this->productItemA->id,
                    'quantity' => 3,
                    'unit_price' => 33333333.33,
                    'amount' => 100000000,
                    'tax_rate' => 10,
                    'tax_amount' => 10000000,
                ],
            ],
        ]);
        $siResp->assertStatus(201);
        $siId = $siResp->json('id');
        $this->postJson("/api/v1/sales/invoices/{$siId}/post")->assertStatus(200);

        // Verify AR debt is 110,000,000 VND
        $balances = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(110000000, $this->reportService->getEndingBalance($balances, '131', 'debit'));

        // 2. Customer pays in full via Bank Receipt (Báo Có)
        $brResp = $this->postJson('/api/v1/bank/receipts', [
            'company_id' => $this->company->id,
            'voucher_type' => 'customer_payment',
            'voucher_number' => 'BC-SETTLE-001',
            'contact_id' => $this->customer->id,
            'contact_type' => 'customer',
            'bank_account_id' => $this->bankAccountVnd->id,
            'description' => 'Thu tiền khách hàng thanh toán cho hóa đơn HDBH-SETTLE-01',
            'voucher_date' => '2026-08-20',
            'lines' => [
                [
                    'description' => 'Thu tiền hóa đơn HDBH-SETTLE-01',
                    'debit_account' => '1121',
                    'credit_account' => '131',
                    'amount' => 110000000,
                    'invoice_id' => $siId,
                ],
            ],
        ]);
        $brResp->assertStatus(201);
        $brId = $brResp->json('id');

        // Post Bank Receipt
        $this->postJson("/api/v1/bank/receipts/{$brId}/post")->assertStatus(200);

        // 3. Verify Invoice status changed to 'Paid'
        $updatedSi = SalesInvoice::find($siId);
        $this->assertEquals('Paid', $updatedSi->status);

        // 4. Verify AR Balance on 131 is cleared to 0
        $balancesAfterPayment = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(0, $this->reportService->getEndingBalance($balancesAfterPayment, '131', 'debit'));
        $this->assertEquals(110000000, $this->reportService->getEndingBalance($balancesAfterPayment, '1121', 'debit'));
    }

    // =========================================================================
    // 4. INVENTORY MODULE ADVERSARIAL STRESS TESTS
    // =========================================================================

    /**
     * Inventory Test 10: FIFO queue starvation and automated cost calculation
     */
    public function test_inventory_fifo_queue_starvation_and_cost_calculation(): void
    {
        // 1. Inward Batch 1: 5 units @ 10,000,000 VND = 50,000,000 VND
        $ir = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseHn->id,
            'voucher_number' => 'NK-FIFO-01',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'voucher_type' => 'goods_receipt',
            'total_amount' => 50000000,
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $ir->lines()->create([
            'item_id' => $this->productItemA->id,
            'warehouse_id' => $this->warehouseHn->id,
            'quantity' => 5,
            'unit_price' => 10000000,
            'amount' => 50000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        // 2. Issue 1: 3 units (within batch 1)
        $ii1 = InventoryIssue::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseHn->id,
            'voucher_number' => 'XK-FIFO-01',
            'voucher_date' => '2026-08-05',
            'posting_date' => '2026-08-05',
            'voucher_type' => 'goods_issue',
            'total_amount' => 0, // Uncalculated initial cost
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $ii1->lines()->create([
            'item_id' => $this->productItemA->id,
            'warehouse_id' => $this->warehouseHn->id,
            'quantity' => 3,
            'unit_price' => 0,
            'amount' => 0,
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);

        // 3. Issue 2: 7 units (Starvation: 2 units remaining in batch 1 + 5 excess units fallback)
        $ii2 = InventoryIssue::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseHn->id,
            'voucher_number' => 'XK-FIFO-02',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'voucher_type' => 'goods_issue',
            'total_amount' => 0,
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $ii2->lines()->create([
            'item_id' => $this->productItemA->id,
            'warehouse_id' => $this->warehouseHn->id,
            'quantity' => 7,
            'unit_price' => 0,
            'amount' => 0,
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);

        // 4. Run FIFO Cost Engine
        $calcResp = $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'company_id' => $this->company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => 'fifo',
            'item_id' => $this->productItemA->id,
        ]);
        $calcResp->assertStatus(200);
        $this->assertTrue($calcResp->json('success'));

        // 5. Verify computed issue costs
        $line1 = InventoryIssueLine::where('inventory_issue_id', $ii1->id)->first();
        $this->assertEquals(30000000, (float) $line1->amount);
        $this->assertEquals(10000000, (float) $line1->unit_price);

        $line2 = InventoryIssueLine::where('inventory_issue_id', $ii2->id)->first();
        // 2 units @ 10M + 5 units @ fallback 10M = 70M
        $this->assertEquals(70000000, (float) $line2->amount);
        $this->assertEquals(10000000, (float) $line2->unit_price);
    }

    /**
     * Inventory Test 11: Multi-warehouse stock segregation and stock report filtering
     */
    public function test_inventory_multi_warehouse_stock_calculation(): void
    {
        // 1. Inward 20 units to KHO_HN @ 20M = 400M
        $irHn = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseHn->id,
            'voucher_number' => 'NK-HN-01',
            'voucher_date' => '2026-08-02',
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $irHn->lines()->create([
            'item_id' => $this->productItemA->id,
            'warehouse_id' => $this->warehouseHn->id,
            'quantity' => 20,
            'unit_price' => 20000000,
            'amount' => 400000000,
        ]);

        // 2. Inward 10 units to KHO_HCM @ 20M = 200M
        $irHcm = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseHcm->id,
            'voucher_number' => 'NK-HCM-01',
            'voucher_date' => '2026-08-03',
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $irHcm->lines()->create([
            'item_id' => $this->productItemA->id,
            'warehouse_id' => $this->warehouseHcm->id,
            'quantity' => 10,
            'unit_price' => 20000000,
            'amount' => 200000000,
        ]);

        // 3. Issue 5 units from KHO_HN
        $iiHn = InventoryIssue::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseHn->id,
            'voucher_number' => 'XK-HN-01',
            'voucher_date' => '2026-08-10',
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $iiHn->lines()->create([
            'item_id' => $this->productItemA->id,
            'warehouse_id' => $this->warehouseHn->id,
            'quantity' => 5,
            'unit_price' => 20000000,
            'amount' => 100000000,
        ]);

        // 4. Issue 2 units from KHO_HCM
        $iiHcm = InventoryIssue::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseHcm->id,
            'voucher_number' => 'XK-HCM-01',
            'voucher_date' => '2026-08-12',
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $iiHcm->lines()->create([
            'item_id' => $this->productItemA->id,
            'warehouse_id' => $this->warehouseHcm->id,
            'quantity' => 2,
            'unit_price' => 20000000,
            'amount' => 40000000,
        ]);

        // 5. Check Stock Report for KHO_HN
        $reportHn = $this->stockReportService->generateReport($this->company->id, [
            'warehouse_id' => $this->warehouseHn->id,
            'item_id' => $this->productItemA->id,
        ]);
        $rowHn = collect($reportHn)->firstWhere('item_id', $this->productItemA->id);
        $this->assertEquals(20, $rowHn['in_qty']);
        $this->assertEquals(5, $rowHn['out_qty']);
        $this->assertEquals(15, $rowHn['end_qty']);
        $this->assertEquals(300000000, $rowHn['end_amt']);

        // 6. Check Stock Report for KHO_HCM
        $reportHcm = $this->stockReportService->generateReport($this->company->id, [
            'warehouse_id' => $this->warehouseHcm->id,
            'item_id' => $this->productItemA->id,
        ]);
        $rowHcm = collect($reportHcm)->firstWhere('item_id', $this->productItemA->id);
        $this->assertEquals(10, $rowHcm['in_qty']);
        $this->assertEquals(2, $rowHcm['out_qty']);
        $this->assertEquals(8, $rowHcm['end_qty']);
        $this->assertEquals(160000000, $rowHcm['end_amt']);

        // 7. Check Combined Stock Report
        $reportTotal = $this->stockReportService->generateReport($this->company->id, [
            'item_id' => $this->productItemA->id,
        ]);
        $rowTotal = collect($reportTotal)->firstWhere('item_id', $this->productItemA->id);
        $this->assertEquals(30, $rowTotal['in_qty']);
        $this->assertEquals(7, $rowTotal['out_qty']);
        $this->assertEquals(23, $rowTotal['end_qty']);
        $this->assertEquals(460000000, $rowTotal['end_amt']);
    }

    /**
     * Inventory Test 12: Negative balance handling and non-crashing stock report calculation
     */
    public function test_inventory_negative_balance_handling_and_stock_report(): void
    {
        // 1. Issue 10 units with zero opening stock
        $ii = InventoryIssue::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseHn->id,
            'voucher_number' => 'XK-NEG-01',
            'voucher_date' => '2026-08-01',
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $ii->lines()->create([
            'item_id' => $this->productItemB->id,
            'warehouse_id' => $this->warehouseHn->id,
            'quantity' => 10,
            'unit_price' => 5000000,
            'amount' => 50000000,
        ]);

        // Stock report should handle negative ending quantity gracefully
        $report = $this->stockReportService->generateReport($this->company->id, [
            'item_id' => $this->productItemB->id,
        ]);
        $row = collect($report)->firstWhere('item_id', $this->productItemB->id);
        $this->assertNotNull($row);
        $this->assertEquals(0, $row['in_qty']);
        $this->assertEquals(10, $row['out_qty']);
        $this->assertEquals(-10, $row['end_qty']);

        // 2. Receive 15 units afterwards
        $ir = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseHn->id,
            'voucher_number' => 'NK-RECV-01',
            'voucher_date' => '2026-08-10',
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $ir->lines()->create([
            'item_id' => $this->productItemB->id,
            'warehouse_id' => $this->warehouseHn->id,
            'quantity' => 15,
            'unit_price' => 5000000,
            'amount' => 75000000,
        ]);

        // Re-check stock report: End Qty should be -10 + 15 = 5
        $reportAfter = $this->stockReportService->generateReport($this->company->id, [
            'item_id' => $this->productItemB->id,
        ]);
        $rowAfter = collect($reportAfter)->firstWhere('item_id', $this->productItemB->id);
        $this->assertEquals(15, $rowAfter['in_qty']);
        $this->assertEquals(10, $rowAfter['out_qty']);
        $this->assertEquals(5, $rowAfter['end_qty']);
        $this->assertEquals(25000000, $rowAfter['end_amt']);
    }

    // =========================================================================
    // 5. GL & FINANCIAL REPORTS ADVERSARIAL STRESS TESTS
    // =========================================================================

    /**
     * GL Test 13: Multi-period closing preview and repeated unapproved close refusal
     */
    public function test_gl_multi_period_closing_preview_and_repeated_unapproved_execution_are_refused(): void
    {
        $january = Period::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period' => 1,
            'period_number' => 1,
            'name' => 'Tháng 01/2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
        $february = Period::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period' => 2,
            'period_number' => 2,
            'name' => 'Tháng 02/2026',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'status' => 'open',
            'is_closed' => false,
        ]);

        // Period 1 (Jan 2026): Revenue 100M, COGS 60M -> Net Profit = 40M
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-JAN-REV',
            'voucher_date' => '2026-01-15',
            'posting_date' => '2026-01-15',
            'description' => 'Doanh thu tháng 1',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '131', 'debit_amount' => 100000000, 'credit_amount' => 0],
                ['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 100000000],
            ],
        ]);

        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-JAN-EXP',
            'voucher_date' => '2026-01-20',
            'posting_date' => '2026-01-20',
            'description' => 'Giá vốn tháng 1',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '632', 'debit_amount' => 60000000, 'credit_amount' => 0],
                ['account_code' => '1561', 'debit_amount' => 0, 'credit_amount' => 60000000],
            ],
        ]);

        // Accounting math is independently observable before a close is
        // permitted.  The close gate must not be bypassed by this legacy E2E
        // fixture.
        $januaryPreview = $this->periodClosingService->preview(
            $this->company->id,
            '2026-01-01',
            '2026-01-31'
        );
        $this->assertSame('100000000.00', $januaryPreview['total_revenue']);
        $this->assertSame('60000000.00', $januaryPreview['total_expenses']);
        $this->assertSame('40000000.00', $januaryPreview['net_profit']);

        $closeJanResp = $this->postJson('/api/v1/gl/closing-entries/execute', [
            'company_id' => $this->company->id,
            'period_id' => $january->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'voucher_number' => 'KC-2026-01',
            'close_reason' => 'Đối chiếu cuối kỳ tháng 1',
        ]);
        $closeJanResp->assertStatus(422)->assertJsonValidationErrors('period_close_readiness');

        // Period 2 (Feb 2026): Revenue 200M, COGS 150M -> Net Profit = 50M
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-FEB-REV',
            'voucher_date' => '2026-02-15',
            'posting_date' => '2026-02-15',
            'description' => 'Doanh thu tháng 2',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '131', 'debit_amount' => 200000000, 'credit_amount' => 0],
                ['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 200000000],
            ],
        ]);

        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-FEB-EXP',
            'voucher_date' => '2026-02-20',
            'posting_date' => '2026-02-20',
            'description' => 'Giá vốn tháng 2',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '632', 'debit_amount' => 150000000, 'credit_amount' => 0],
                ['account_code' => '1561', 'debit_amount' => 0, 'credit_amount' => 150000000],
            ],
        ]);

        $februaryPreview = $this->periodClosingService->preview(
            $this->company->id,
            '2026-02-01',
            '2026-02-28'
        );
        $this->assertSame('200000000.00', $februaryPreview['total_revenue']);
        $this->assertSame('150000000.00', $februaryPreview['total_expenses']);
        $this->assertSame('50000000.00', $februaryPreview['net_profit']);

        $closeFebResp = $this->postJson('/api/v1/gl/closing-entries/execute', [
            'company_id' => $this->company->id,
            'period_id' => $february->id,
            'from_date' => '2026-02-01',
            'to_date' => '2026-02-28',
            'voucher_number' => 'KC-2026-02',
            'close_reason' => 'Đối chiếu cuối kỳ tháng 2',
        ]);
        $closeFebResp->assertStatus(422)->assertJsonValidationErrors('period_close_readiness');

        // TEST IDEMPOTENCY: Re-execute January closing
        $reCloseJanResp = $this->postJson('/api/v1/gl/closing-entries/execute', [
            'company_id' => $this->company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'voucher_number' => 'KC-2026-01',
            'close_reason' => 'Thử lại kết chuyển tháng 1',
        ]);
        $reCloseJanResp->assertStatus(422)->assertJsonValidationErrors('period_close_readiness');

        // No amount of retrying may create a closing voucher or mutate a period
        // before the controlled readiness workflow is approved.
        $janVouchers = JournalEntry::where('company_id', $this->company->id)
            ->where('voucher_number', 'KC-2026-01')
            ->get();
        $this->assertCount(0, $janVouchers);

        $this->assertDatabaseHas('periods', ['id' => $january->id, 'status' => 'open', 'is_closed' => false]);
        $this->assertDatabaseHas('periods', ['id' => $february->id, 'status' => 'open', 'is_closed' => false]);

        // The unclosed ledger must not contain a retained-earnings transfer.
        $balances = $this->reportService->getAccountBalances($this->company->id);
        $profit4212 = $this->reportService->getEndingBalance($balances, '4212', 'credit');
        $this->assertEquals(0, $profit4212);
    }

    /**
     * GL Test 14: Income Statement accuracy and close refusal before readiness approval
     */
    public function test_gl_income_statement_accuracy_and_unapproved_period_closing_refusal(): void
    {
        // 1. Post initial entries:
        // Revenue 5111: 300,000,000 VND
        // Financial Income 515: 20,000,000 VND
        // COGS 632: 180,000,000 VND
        // Admin Expense 642: 40,000,000 VND
        // Expected Net Profit = (300M + 20M) - (180M + 40M) = 100,000,000 VND.

        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-REV-IS',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'description' => 'Doanh thu bán hàng và tài chính',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '131', 'debit_amount' => 300000000, 'credit_amount' => 0],
                ['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 300000000],
                ['account_code' => '1121', 'debit_amount' => 20000000, 'credit_amount' => 0],
                ['account_code' => '515', 'debit_amount' => 0, 'credit_amount' => 20000000],
            ],
        ]);

        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-EXP-IS',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Giá vốn và chi phí quản lý',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '632', 'debit_amount' => 180000000, 'credit_amount' => 0],
                ['account_code' => '1561', 'debit_amount' => 0, 'credit_amount' => 180000000],
                ['account_code' => '642', 'debit_amount' => 40000000, 'credit_amount' => 0],
                ['account_code' => '1121', 'debit_amount' => 0, 'credit_amount' => 40000000],
            ],
        ]);

        // A. Check Income Statement BEFORE period closing
        $isBefore = $this->reportService->getIncomeStatement($this->company->id);
        $profitBefore = collect($isBefore)->firstWhere('code', '60');
        $this->assertEquals(100000000, (float) $profitBefore['this_period']);

        $august = Period::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'Tháng 08/2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
        $preview = $this->periodClosingService->preview($this->company->id, '2026-08-01', '2026-08-31');
        $this->assertSame('320000000.00', $preview['total_revenue']);
        $this->assertSame('220000000.00', $preview['total_expenses']);
        $this->assertSame('100000000.00', $preview['net_profit']);

        // B. An unapproved close is refused. This test must not establish a
        // fake readiness approval merely to preserve the old post-close path.
        $this->postJson('/api/v1/gl/closing-entries/execute', [
            'company_id' => $this->company->id,
            'period_id' => $august->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'voucher_number' => 'KC-2026-08',
            'close_reason' => 'Đối chiếu cuối kỳ tháng 8',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('period_close_readiness');

        $this->assertDatabaseHas('periods', ['id' => $august->id, 'status' => 'open', 'is_closed' => false]);
        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'KC-2026-08']);
    }

    /**
     * GL Test 15: Trial Balance debit=credit balance invariant across all detail accounts
     */
    public function test_gl_trial_balance_debit_credit_balance_invariant(): void
    {
        // 1. Initial Capital: 500M (Nợ 1121 / Có 4111)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-CAPITAL',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1121', 'debit_amount' => 500000000, 'credit_amount' => 0],
                ['account_code' => '4111', 'debit_amount' => 0, 'credit_amount' => 500000000],
            ],
        ]);

        // 2. Buy Goods on Credit: 200M + 20M VAT (Nợ 1561: 200M, Nợ 1331: 20M / Có 331: 220M)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-BUY',
            'voucher_date' => '2026-08-05',
            'posting_date' => '2026-08-05',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1561', 'debit_amount' => 200000000, 'credit_amount' => 0],
                ['account_code' => '1331', 'debit_amount' => 20000000, 'credit_amount' => 0],
                ['account_code' => '331', 'debit_amount' => 0, 'credit_amount' => 220000000],
            ],
        ]);

        // 3. Sell Goods on Credit: 300M + 30M VAT (Nợ 131: 330M / Có 5111: 300M, Có 33311: 30M)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-SELL',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '131', 'debit_amount' => 330000000, 'credit_amount' => 0],
                ['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 300000000],
                ['account_code' => '33311', 'debit_amount' => 0, 'credit_amount' => 30000000],
            ],
        ]);

        // 4. COGS: 150M (Nợ 632 / Có 1561)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-COGS',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '632', 'debit_amount' => 150000000, 'credit_amount' => 0],
                ['account_code' => '1561', 'debit_amount' => 0, 'credit_amount' => 150000000],
            ],
        ]);

        // 5. Operating Expense: 30M (Nợ 642 / Có 1121)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-EXP',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '642', 'debit_amount' => 30000000, 'credit_amount' => 0],
                ['account_code' => '1121', 'debit_amount' => 0, 'credit_amount' => 30000000],
            ],
        ]);

        // 6. VAT deduction entry (Nợ 33311: 20M / Có 1331: 20M)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-VAT',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-08-31',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '33311', 'debit_amount' => 20000000, 'credit_amount' => 0],
                ['account_code' => '1331', 'debit_amount' => 0, 'credit_amount' => 20000000],
            ],
        ]);

        // 7. The period must exist so the request reaches the mandatory close
        // readiness gate rather than failing only because no period can be found.
        $august = Period::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'Tháng 08/2026 - GL invariant',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        // The server-generated preview is still the authoritative closing
        // proposal. It must balance before an eligible approver can execute it.
        $preview = $this->periodClosingService->preview(
            $this->company->id,
            '2026-08-01',
            '2026-08-31'
        );
        $this->assertSame('120000000.00', $preview['net_profit']);
        $this->assertEquals(
            collect($preview['suggested_lines'])->sum('debit_amount'),
            collect($preview['suggested_lines'])->sum('credit_amount')
        );

        // 8. A valid period without controlled reconciliation evidence cannot
        // be closed. Do not create a synthetic readiness approval in this
        // adversarial test merely to retain the obsolete happy-path close.
        $this->postJson('/api/v1/gl/closing-entries/execute', [
            'company_id' => $this->company->id,
            'period_id' => $august->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'voucher_number' => 'KC-2026-08',
            'close_reason' => 'Kiểm tra điều kiện khóa kỳ',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('period_close_readiness');

        $this->assertDatabaseHas('periods', [
            'id' => $august->id,
            'status' => 'open',
            'is_closed' => false,
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $this->company->id,
            'voucher_number' => 'KC-2026-08',
        ]);

        // 9. Fetch the unclosed Trial Balance and verify the GL invariant is
        // unaffected by the rejected closing attempt.
        $trialBalance = $this->reportService->getTrialBalance($this->company->id);
        $leafAccounts = $trialBalance->where('is_parent', false);

        $totalArisingDebit = $leafAccounts->sum('arising_debit');
        $totalArisingCredit = $leafAccounts->sum('arising_credit');
        $this->assertEquals($totalArisingDebit, $totalArisingCredit);

        $totalEndingDebit = $leafAccounts->sum('ending_debit');
        $totalEndingCredit = $leafAccounts->sum('ending_credit');
        $this->assertEquals($totalEndingDebit, $totalEndingCredit);
    }
}
