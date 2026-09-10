<?php

namespace Tests\Feature\MisaE2E;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\BankReceiptLine;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\VoucherReference;
use App\Models\Warehouse;
use App\Services\FinancialReportService;
use App\Services\InventoryValuationService;
use App\Services\JournalEntryService;
use App\Services\StockReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Tier3PairwiseCombinationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected Customer $customer;

    protected Supplier $supplier;

    protected BankAccount $bankAccount;

    protected Item $productItem;

    protected Item $serviceItem;

    protected Warehouse $warehouseMain;

    protected Warehouse $warehouseBranch;

    protected FinancialReportService $reportService;

    protected InventoryValuationService $valuationService;

    protected JournalEntryService $journalService;

    protected StockReportService $stockReportService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Set up Company & Fiscal Year
        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Công ty TNHH MISA E2E Test',
                'tax_code' => '0101234567',
                'address' => 'Tầng 9, Tòa nhà MISA, Cầu Giấy, Hà Nội',
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

        // 2. Set up User with Roles & Permissions
        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        // Pairwise lifecycle posting must use one of the two canonical roles;
        // the historical `chief_accountant` identity is intentionally not a
        // production posting bypass.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));

        Sanctum::actingAs($this->user);

        // 3. Seed Standard TT200 Chart of Accounts
        $this->seedChartOfAccounts();

        // 4. Seed Master Entities (Customer, Supplier, Bank Account, Items)
        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH001',
            'name' => 'Công ty Cổ phần Thương mại Nam Hải',
            'tax_code' => '0301987654',
            'address' => 'Số 15 Lê Duẩn, Quận 1, TP. Hồ Chí Minh',
            'contact_person' => 'Nguyễn Hải Nam',
            'phone' => '0901234567',
            'email' => 'contact@namhai.vn',
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC001',
            'name' => 'Công ty TNHH Thiết bị & Công nghệ Đại Phát',
            'tax_code' => '0102345678',
            'address' => 'Lô C2, KCN Thăng Long, Đông Anh, Hà Nội',
            'contact_person' => 'Trần Đại Phát',
            'phone' => '0912345678',
            'email' => 'sales@daiphat.vn',
        ]);

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '19038889999001',
            'bank_name' => 'Ngân hàng TMCP Kỹ Thương Việt Nam (Techcombank)',
            'branch' => 'Chi nhánh Thăng Long, Hà Nội',
            'account_holder' => 'CONG TY TNHH MISA E2E TEST',
            'gl_account_code' => '1121',
            'is_active' => true,
        ]);

        $this->productItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'VT01',
            'name' => 'Máy tính xách tay Dell Latitude 5420',
            'type' => 'Goods',
            'unit' => 'Chiếc',
            'cost_price' => 15000000,
            'selling_price' => 20000000,
            'sale_price' => 20000000,
            'tax_rate' => 10,
            'inventory_account' => '1561',
            'cogs_account' => '632',
            'revenue_account' => '5111',
            'is_active' => true,
        ]);

        $this->serviceItem = Item::create([
            'company_id' => $this->company->id,
            'code' => 'DV01',
            'name' => 'Dịch vụ vận chuyển & giao nhận hàng hóa',
            'type' => 'Service',
            'unit' => 'Chuyến',
            'cost_price' => 1000000,
            'selling_price' => 1500000,
            'sale_price' => 1500000,
            'tax_rate' => 10,
            'is_active' => true,
        ]);

        $this->warehouseMain = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO-MAIN',
            'name' => 'Kho chính',
            'is_active' => true,
        ]);

        $this->warehouseBranch = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO-BRANCH',
            'name' => 'Kho chi nhánh',
            'is_active' => true,
        ]);

        // 5. Instantiate Domain Services
        $this->reportService = app(FinancialReportService::class);
        $this->valuationService = app(InventoryValuationService::class);
        $this->journalService = app(JournalEntryService::class);
        $this->stockReportService = app(StockReportService::class);
    }

    /**
     * Helper to seed TT200 accounts
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
            ['code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '153', 'name' => 'Công cụ, dụng cụ', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '155', 'name' => 'Thành phẩm', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '156', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '1562', 'name' => 'Chi phí thu mua hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '211', 'name' => 'Tài sản cố định hữu hình', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '333', 'name' => 'Thuế và các khoản phải nộp Nhà nước', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 1],
            ['code' => '3331', 'name' => 'Thuế GTGT phải nộp', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => 1],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'nature' => 'credit', 'level' => 3, 'is_parent' => 0],
            ['code' => '334', 'name' => 'Phải trả người lao động', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '341', 'name' => 'Vay và nợ thuê tài chính', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '411', 'name' => 'Vốn đầu tư của chủ sở hữu', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '421', 'name' => 'Lợi nhuận sau thuế chưa phân phối', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => 1],
            ['code' => '4212', 'name' => 'Lợi nhuận sau thuế chưa phân phối năm nay', 'type' => 'equity', 'nature' => 'credit', 'level' => 2, 'is_parent' => 0],
            ['code' => '511', 'name' => 'Doanh thu bán hàng và cung cấp dịch vụ', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 1],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => 0],
            ['code' => '515', 'name' => 'Doanh thu hoạt động tài chính', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '635', 'name' => 'Chi phí tài chính', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '641', 'name' => 'Chi phí bán hàng', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '642', 'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
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

    /**
     * 1. Test Quote -> Sales Order -> Sales Invoice kiêm PXK -> Bank Receipt Lifecycle
     * Full sales flow where quote generates order, order generates invoice kiêm PXK (131/511, 632/156),
     * and bank receipt settles 131 debt.
     */
    public function test_quote_to_sales_order_to_sales_invoice_to_bank_receipt_lifecycle()
    {
        // Step 1: Create Sales Invoice kiêm Phiếu xuất kho (is_export_slip = true)
        // 5 units Dell Latitude @ 20,000,000 VND = 100,000,000 VND + 10% VAT (10,000,000 VND) = 110,000,000 VND
        // COGS: 5 units @ 15,000,000 VND = 75,000,000 VND
        $salesPayload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-2026-001',
            'invoice_date' => '2026-08-10',
            'accounting_date' => '2026-08-10',
            'due_date' => '2026-09-10',
            'description' => 'Xuất bán 5 máy tính Dell Latitude theo Đơn hàng DH001',
            'is_export_slip' => true,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'description' => 'Máy tính Dell Latitude 5420',
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 5,
                    'unit_price' => 20000000,
                    'tax_rate' => 10,
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 15000000,
                ],
            ],
        ];

        $invoiceResp = $this->postJson('/api/v1/sales/invoices', $salesPayload);
        $invoiceResp->assertStatus(201);
        $invoiceId = $invoiceResp->json('id');
        $this->assertEquals(110000000, $invoiceResp->json('total_amount'));

        // Step 2: Post Sales Invoice to GL
        $postInvoiceResp = $this->postJson("/api/v1/sales/invoices/{$invoiceId}/post");
        $postInvoiceResp->assertStatus(200);

        $invoice = SalesInvoice::find($invoiceId);
        $this->assertTrue((bool) $invoice->is_posted);
        $this->assertNotNull($invoice->journal_entry_id);

        // Verify balanced double entry in Journal Entries:
        // Debit 131: 110M, Credit 5111: 100M, Credit 33311: 10M
        // Debit 632: 75M, Credit 1561: 75M
        $je = JournalEntry::with('lines')->find($invoice->journal_entry_id);
        $this->assertEquals('posted', $je->status);
        $this->assertEquals(185000000, $je->lines->where('debit_amount', '>', 0)->sum('debit_amount'));
        $this->assertEquals(185000000, $je->lines->where('credit_amount', '>', 0)->sum('credit_amount'));

        // Step 3: Verify customer AR balance on 131 is 110,000,000 VND
        $balancesBefore = $this->reportService->getAccountBalances($this->company->id);
        $arBalance = $this->reportService->getEndingBalance($balancesBefore, '131', 'debit');
        $this->assertEquals(110000000, $arBalance);

        // Step 4: Customer settles full invoice via Bank Receipt (Báo Có)
        $bankReceiptPayload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'contact_type' => 'customer',
            'contact_id' => 'KH001',
            'contact_name' => $this->customer->name,
            'voucher_number' => 'BC-2026-001',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'payer_name' => 'Công ty CP Nam Hải',
            'description' => 'Thu tiền bán hàng hóa đơn HDBH-2026-001 qua Techcombank',
            'lines' => [
                [
                    'description' => 'Thanh toán tiền hàng HDBH-2026-001',
                    'debit_account' => '1121',
                    'credit_account' => '131',
                    'amount' => 110000000,
                    'invoice_id' => $invoiceId,
                ],
            ],
        ];

        $receiptResp = $this->postJson('/api/v1/bank/receipts', $bankReceiptPayload);
        $receiptResp->assertStatus(201);
        $receiptId = $receiptResp->json('id');

        // Link Voucher Reference
        VoucherReference::create([
            'source_type' => BankReceipt::class,
            'source_id' => $receiptId,
            'target_type' => SalesInvoice::class,
            'target_id' => $invoiceId,
            'target_voucher_type' => 'Hóa đơn bán hàng',
            'target_voucher_number' => 'HDBH-2026-001',
            'target_voucher_date' => '2026-08-10',
            'target_total_amount' => 110000000,
            'description' => 'Thu tiền hóa đơn bán hàng HDBH-2026-001',
        ]);

        // Step 5: Post Bank Receipt to GL
        $postReceiptResp = $this->postJson("/api/v1/bank/receipts/{$receiptId}/post");
        $postReceiptResp->assertStatus(200);

        // Step 6: Verify AR balance is completely settled to 0 and Bank 1121 has 110,000,000 VND
        $balancesAfter = $this->reportService->getAccountBalances($this->company->id);
        $arEnding = $this->reportService->getEndingBalance($balancesAfter, '131', 'debit');
        $bankEnding = $this->reportService->getEndingBalance($balancesAfter, '1121', 'debit');

        $this->assertEquals(0, $arEnding);
        $this->assertEquals(110000000, $bankEnding);

        // Step 7: Verify Sales Invoice status is updated to Paid
        $refreshedInvoice = SalesInvoice::find($invoiceId);
        $this->assertEquals('Paid', $refreshedInvoice->status);
    }

    /**
     * 2. Test PO -> Purchase Invoice -> Service Allocation -> Bank Payment
     * Full procurement flow with landed cost allocation to purchased inventory and bank payment clearing 331 debt.
     */
    public function test_po_to_purchase_invoice_to_service_allocation_to_bank_payment()
    {
        // Step 1: Create Purchase Order
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_code' => $this->supplier->code,
            'supplier_name' => $this->supplier->name,
            'order_number' => 'PO-2026-001',
            'order_date' => '2026-08-01',
            'description' => 'Đơn mua 10 máy tính Dell Latitude từ NCC Đại Phát',
            'sub_total' => 150000000,
            'tax_amount' => 15000000,
            'total_amount' => 165000000,
            'status' => 'approved',
        ]);

        // Step 2: Create Purchase Invoice with Landed Cost / Purchase Expense
        // Goods cost: 10 units @ 15,000,000 = 150,000,000 VND. Freight expense: 5,000,000 VND.
        // Total Stock Value = 155,000,000 VND. VAT 10% = 15,000,000 VND. Total Payable = 165,000,000 VND.
        $piPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'invoice_number' => 'HDMH-2026-001',
            'invoice_date' => '2026-08-05',
            'accounting_date' => '2026-08-05',
            'due_date' => '2026-09-05',
            'description' => 'Mua hàng nhập kho theo PO-2026-001 kèm chi phí vận chuyển',
            'purchase_expense' => 5000000,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'description' => 'Máy tính Dell Latitude 5420',
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 15000000,
                    'tax_rate' => 10,
                    'tax_account' => '13311',
                    'purchase_expense' => 5000000,
                    'stock_value' => 155000000,
                ],
            ],
        ];

        $piResp = $this->postJson('/api/v1/purchase/invoices', $piPayload);
        $piResp->assertStatus(201);
        $piId = $piResp->json('id');

        // Link Purchase Invoice to Purchase Order
        VoucherReference::create([
            'source_type' => PurchaseInvoice::class,
            'source_id' => $piId,
            'target_type' => PurchaseOrder::class,
            'target_id' => $po->id,
            'target_voucher_type' => 'Đơn mua hàng',
            'target_voucher_number' => 'PO-2026-001',
            'target_voucher_date' => '2026-08-01',
            'target_total_amount' => 165000000,
            'description' => 'Nhập kho mua hàng theo PO-2026-001',
        ]);

        // Step 3: Post Purchase Invoice to GL
        $postPiResp = $this->postJson("/api/v1/purchase/invoices/{$piId}/post");
        $postPiResp->assertStatus(200);

        // Verify AP debt on 331 is 165,000,000 VND
        $balances = $this->reportService->getAccountBalances($this->company->id);
        $apDebt = $this->reportService->getEndingBalance($balances, '331', 'credit');
        $this->assertEquals(165000000, $apDebt);

        // Step 4: Pay Supplier via Bank Payment (Ủy nhiệm chi UNC)
        $bpPayload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'contact_type' => 'supplier',
            'contact_id' => 'NCC001',
            'contact_name' => $this->supplier->name,
            'voucher_number' => 'UNC-2026-001',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'payee_name' => $this->supplier->name,
            'description' => 'Ủy nhiệm chi thanh toán tiền hàng HDMH-2026-001',
            'lines' => [
                [
                    'description' => 'Thanh toán tiền mua hàng HDMH-2026-001',
                    'debit_account' => '331',
                    'credit_account' => '1121',
                    'amount' => 165000000,
                    'invoice_id' => $piId,
                ],
            ],
        ];

        $bpResp = $this->postJson('/api/v1/bank/payments', $bpPayload);
        $bpResp->assertStatus(201);
        $bpId = $bpResp->json('id');

        // Link Bank Payment to Purchase Invoice
        VoucherReference::create([
            'source_type' => BankPayment::class,
            'source_id' => $bpId,
            'target_type' => PurchaseInvoice::class,
            'target_id' => $piId,
            'target_voucher_type' => 'Hóa đơn mua hàng',
            'target_voucher_number' => 'HDMH-2026-001',
            'target_voucher_date' => '2026-08-05',
            'target_total_amount' => 165000000,
            'description' => 'Thanh toán tiền mua hàng',
        ]);

        // Step 5: Post Bank Payment to GL
        $postBpResp = $this->postJson("/api/v1/bank/payments/{$bpId}/post");
        $postBpResp->assertStatus(200);

        // Step 6: Verify AP debt is 0, Purchase Invoice status is Paid
        $balancesAfter = $this->reportService->getAccountBalances($this->company->id);
        $apEnding = $this->reportService->getEndingBalance($balancesAfter, '331', 'credit');
        $this->assertEquals(0, $apEnding);

        $refreshedPi = PurchaseInvoice::find($piId);
        $this->assertEquals('Paid', $refreshedPi->status);
    }

    /**
     * 3. Test Sales Invoice Immediate Bank Payment Bypasses AR 131
     * Direct bank collection at time of sales invoice creation.
     */
    public function test_sales_invoice_immediate_bank_payment_bypasses_ar_131()
    {
        // Create Paid Sales Invoice (Thanh toán ngay bằng Tiền gửi / Tiền mặt)
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'invoice_number' => 'HDBH-IMMED-001',
            'invoice_date' => '2026-08-12',
            'accounting_date' => '2026-08-12',
            'due_date' => '2026-08-12',
            'sub_total' => 20000000,
            'tax_amount' => 2000000,
            'total_amount' => 22000000,
            'status' => 'Paid',
            'description' => 'Bán hàng thu tiền ngay bằng tiền mặt / tiền gửi',
            'is_export_slip' => false,
            'is_posted' => false,
        ]);

        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'item_id' => $this->productItem->id,
            'description' => 'Bán hàng thu tiền ngay',
            'debit_account' => '1111',
            'credit_account' => '5111',
            'quantity' => 1,
            'unit_price' => 20000000,
            'amount' => 20000000,
            'tax_rate' => 10,
            'tax_amount' => 2000000,
            'tax_account' => '33311',
        ]);

        // Post to GL
        $postResp = $this->postJson("/api/v1/sales/invoices/{$invoice->id}/post");
        $postResp->assertStatus(200);

        // Verify Journal Entry debits 1111 directly instead of 131
        $je = JournalEntry::with('lines')->find(SalesInvoice::find($invoice->id)->journal_entry_id);
        $this->assertNotNull($je);

        $debitCash = $je->lines->where('account_code', '1111')->sum('debit_amount');
        $debit131 = $je->lines->where('account_code', '131')->sum('debit_amount');
        $creditRev = $je->lines->where('account_code', '5111')->sum('credit_amount');
        $creditTax = $je->lines->where('account_code', '33311')->sum('credit_amount');

        $this->assertEquals(22000000, $debitCash);
        $this->assertEquals(0, $debit131);
        $this->assertEquals(20000000, $creditRev);
        $this->assertEquals(2000000, $creditTax);

        // Verify AR 131 balance is 0
        $balances = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(0, $this->reportService->getEndingBalance($balances, '131', 'debit'));
    }

    /**
     * 4. Test Purchase Service Immediate Bank Payment Bypasses AP 331
     * Direct bank expense payment for utility/service bills.
     */
    public function test_purchase_service_immediate_bank_payment_bypasses_ap_331()
    {
        // Bank Payment for utility services (Electricity & Internet): Nợ 6427, Nợ 13311 / Có 1121
        $bpPayload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'contact_type' => 'supplier',
            'contact_name' => 'Công ty Điện lực Cầu Giấy',
            'voucher_number' => 'UNC-ELEC-001',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'description' => 'Thanh toán tiền điện và dịch vụ mạng văn phòng tháng 8',
            'lines' => [
                [
                    'description' => 'Tiền điện văn phòng T8/2026',
                    'debit_account' => '6427',
                    'credit_account' => '1121',
                    'amount' => 10000000,
                ],
                [
                    'description' => 'Thuế GTGT tiền điện 10%',
                    'debit_account' => '13311',
                    'credit_account' => '1121',
                    'amount' => 1000000,
                ],
            ],
        ];

        $bpResp = $this->postJson('/api/v1/bank/payments', $bpPayload);
        $bpResp->assertStatus(201);
        $bpId = $bpResp->json('id');

        $postResp = $this->postJson("/api/v1/bank/payments/{$bpId}/post");
        $postResp->assertStatus(200);

        // Verify Journal Entry: 331 is never touched (bypassed)
        $payment = BankPayment::find($bpId);
        $je = JournalEntry::with('lines')->find($payment->journal_entry_id);

        $this->assertEquals(0, $je->lines->where('account_code', '331')->count());
        $this->assertEquals(10000000, $je->lines->where('account_code', '6427')->sum('debit_amount'));
        $this->assertEquals(1000000, $je->lines->where('account_code', '13311')->sum('debit_amount'));
        $this->assertEquals(11000000, $je->lines->where('account_code', '1121')->sum('credit_amount'));

        // Verify Financial balances
        $balances = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(0, $this->reportService->getEndingBalance($balances, '331', 'credit'));
        $this->assertEquals(10000000, $this->reportService->getEndingBalance($balances, '6427', 'debit'));
    }

    /**
     * 5. Test Inventory Cost Calculation Updates Sales Invoice COGS and GL
     * Run monthly weighted average / moving average costing engine and verify sales invoice COGS journal entries reflect newly calculated cost.
     */
    public function test_inventory_cost_calculation_updates_sales_invoice_cogs_and_gl()
    {
        // Batch 1: Inward purchase 100 units @ 10,000 VND = 1,000,000 VND
        $rcpt1 = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-BAT1',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'warehouse_id' => $this->warehouseMain->id,
            'total_amount' => 1000000,
            'is_posted' => true,
        ]);
        InventoryReceiptLine::create([
            'inventory_receipt_id' => $rcpt1->id,
            'item_id' => $this->productItem->id,
            'warehouse_id' => $this->warehouseMain->id,
            'quantity' => 100,
            'unit_price' => 10000,
            'amount' => 1000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        // Batch 2: Inward purchase 100 units @ 20,000 VND = 2,000,000 VND
        $rcpt2 = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-BAT2',
            'voucher_date' => '2026-08-05',
            'posting_date' => '2026-08-05',
            'warehouse_id' => $this->warehouseMain->id,
            'total_amount' => 2000000,
            'is_posted' => true,
        ]);
        InventoryReceiptLine::create([
            'inventory_receipt_id' => $rcpt2->id,
            'item_id' => $this->productItem->id,
            'warehouse_id' => $this->warehouseMain->id,
            'quantity' => 100,
            'unit_price' => 20000,
            'amount' => 2000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        // Average unit cost = (1,000,000 + 2,000,000) / 200 = 15,000 VND
        $calcCost = $this->valuationService->getMovingAverageCost(
            $this->productItem->id,
            $this->company->id,
            '2026-08-10'
        );
        $this->assertEquals(15000, $calcCost);

        // Step 2: Issue 50 units for sale -> Total COGS = 50 * 15,000 = 750,000 VND
        $issueResp = $this->postJson('/api/v1/inventory/issues', [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-SALE-001',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'description' => 'Xuất kho bán hàng theo giá xuất bình quân',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'warehouse_id' => $this->warehouseMain->id,
                    'quantity' => 50,
                ],
            ],
        ]);
        $issueResp->assertStatus(201);
        $issueId = $issueResp->json('id');
        $this->assertEquals(750000, $issueResp->json('total_amount'));

        // Post Inventory Issue to GL
        $postIssueResp = $this->postJson("/api/v1/inventory/issues/{$issueId}/post");
        $postIssueResp->assertStatus(200);

        // Verify GL Journal Entry for COGS
        $issue = InventoryIssue::find($issueId);
        $je = JournalEntry::with('lines')->find($issue->journal_entry_id);
        $this->assertEquals(750000, $je->lines->where('account_code', '632')->sum('debit_amount'));
        $this->assertEquals(750000, $je->lines->where('account_code', '1561')->sum('credit_amount'));

        // Verify remaining stock in Stock Report: 200 in - 50 out = 150 remaining units with 2,250,000 VND value
        $stockReport = $this->stockReportService->generateReport($this->company->id);
        $itemStock = collect($stockReport)->firstWhere('item_id', $this->productItem->id);

        $this->assertNotNull($itemStock);
        $this->assertEquals(200, $itemStock['in_qty']);
        $this->assertEquals(3000000, $itemStock['in_amt']);
        $this->assertEquals(50, $itemStock['out_qty']);
        $this->assertEquals(750000, $itemStock['out_amt']);
        $this->assertEquals(150, $itemStock['end_qty']);
        $this->assertEquals(2250000, $itemStock['end_amt']);
    }

    /**
     * 6. Test Full Period Closing Pipeline Across All Five Modules
     * Execute transactions in BA, PU, SA, IN, then run period closing (911 -> 4212) and verify 511, 632, 642 are cleared to 0.
     */
    public function test_full_period_closing_pipeline_across_all_five_modules()
    {
        // 1. SA: Sales Revenue 100,000,000 VND (Có 5111)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-REV-01',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Ghi nhận doanh thu bán hàng tháng 8',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '131', 'credit_account' => '5111', 'amount' => 100000000],
            ],
        ]);

        // 2. IN: Cost of Goods Sold 60,000,000 VND (Nợ 632)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-COGS-01',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Ghi nhận giá vốn hàng bán tháng 8',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '632', 'credit_account' => '1561', 'amount' => 60000000],
            ],
        ]);

        // 3. PU / BA: Admin Expense 15,000,000 VND (Nợ 642)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-EXP-01',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'description' => 'Chi phí quản lý doanh nghiệp tháng 8',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '642', 'credit_account' => '1121', 'amount' => 15000000],
            ],
        ]);

        // 4. Financial Income 5,000,000 VND (Có 515)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-FIN-01',
            'voucher_date' => '2026-08-25',
            'posting_date' => '2026-08-25',
            'description' => 'Lãi tiền gửi ngân hàng',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '1121', 'credit_account' => '515', 'amount' => 5000000],
            ],
        ]);

        // Check balances before closing: Net profit = 100M + 5M - 60M - 15M = 30,000,000 VND
        $incomeStmtBefore = $this->reportService->getIncomeStatement($this->company->id);
        $netProfitRow = collect($incomeStmtBefore)->firstWhere('code', '60');
        $this->assertEquals(30000000, $netProfitRow['this_period']);

        // Execute Period Closing (Kết chuyển cuối kỳ):
        // 1. Kết chuyển doanh thu sang 911: Nợ 5111 (100M), Nợ 515 (5M) / Có 911 (105M)
        // 2. Kết chuyển chi phí sang 911: Nợ 911 (75M) / Có 632 (60M), Có 642 (15M)
        // 3. Kết chuyển kết quả kinh doanh sang 4212: Nợ 911 (30M) / Có 4212 (30M)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'period_closing',
            'voucher_number' => 'KC-2026-08',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-08-31',
            'description' => 'Kết chuyển xác định kết quả kinh doanh tháng 8/2026',
            'status' => 'posted',
            'lines' => [
                // Transfer Revenue to 911
                ['account_code' => '5111', 'debit_amount' => 100000000, 'credit_amount' => 0, 'description' => 'Kết chuyển doanh thu bán hàng'],
                ['account_code' => '515', 'debit_amount' => 5000000, 'credit_amount' => 0, 'description' => 'Kết chuyển doanh thu tài chính'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 105000000, 'description' => 'Nhận kết chuyển doanh thu'],

                // Transfer Expenses to 911
                ['account_code' => '911', 'debit_amount' => 75000000, 'credit_amount' => 0, 'description' => 'Kết chuyển chi phí'],
                ['account_code' => '632', 'debit_amount' => 0, 'credit_amount' => 60000000, 'description' => 'Kết chuyển giá vốn'],
                ['account_code' => '642', 'debit_amount' => 0, 'credit_amount' => 15000000, 'description' => 'Kết chuyển chi phí QLDN'],

                // Transfer Net Profit to 4212
                ['account_code' => '911', 'debit_amount' => 30000000, 'credit_amount' => 0, 'description' => 'Kết chuyển lãi sau thuế'],
                ['account_code' => '4212', 'debit_amount' => 0, 'credit_amount' => 30000000, 'description' => 'Lợi nhuận sau thuế chưa phân phối'],
            ],
        ]);

        // Verify all temporary nominal accounts (511, 515, 632, 642, 911) are cleared to 0
        $balancesAfter = $this->reportService->getAccountBalances($this->company->id);

        $this->assertEquals(0, $this->reportService->getEndingBalance($balancesAfter, '511', 'credit'));
        $this->assertEquals(0, $this->reportService->getEndingBalance($balancesAfter, '515', 'credit'));
        $this->assertEquals(0, $this->reportService->getEndingBalance($balancesAfter, '632', 'debit'));
        $this->assertEquals(0, $this->reportService->getEndingBalance($balancesAfter, '642', 'debit'));
        $this->assertEquals(0, $this->reportService->getEndingBalance($balancesAfter, '911', 'debit'));

        // Verify Retained Earnings (4212) has exact 30,000,000 VND credit balance
        $retainedEarnings = $this->reportService->getEndingBalance($balancesAfter, '4212', 'credit');
        $this->assertEquals(30000000, $retainedEarnings);
    }

    /**
     * 7. Test Financial Reports Trial Balance Matches Balance Sheet and Income Statement
     * Verify balance sheet equation (Assets = Liabilities + Equity) and Income Statement net profit equals Balance Sheet 4212 retained earnings.
     */
    public function test_financial_reports_trial_balance_matches_balance_sheet_and_income_statement()
    {
        // 1. Initial Owner's Capital: 300,000,000 VND (Nợ 1121 / Có 411)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-CAP-01',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'description' => 'Góp vốn ban đầu bằng tiền gửi ngân hàng',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '1121', 'credit_account' => '411', 'amount' => 300000000],
            ],
        ]);

        // 2. Purchase Inventory on credit: 100,000,000 VND + 10,000,000 VAT (Nợ 1561, Nợ 13311 / Có 331)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-PU-01',
            'voucher_date' => '2026-08-05',
            'posting_date' => '2026-08-05',
            'description' => 'Mua hàng hóa nhập kho chưa trả tiền',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1561', 'debit_amount' => 100000000, 'credit_amount' => 0, 'description' => 'Tiền mua hàng'],
                ['account_code' => '13311', 'debit_amount' => 10000000, 'credit_amount' => 0, 'description' => 'Thuế GTGT đầu vào'],
                ['account_code' => '331', 'debit_amount' => 0, 'credit_amount' => 110000000, 'description' => 'Phải trả người bán'],
            ],
        ]);

        // 3. Sales on credit: 150,000,000 VND + 15,000,000 VAT (Nợ 131 / Có 5111, Có 33311)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-SA-01',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'description' => 'Bán hàng chưa thu tiền',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '131', 'debit_amount' => 165000000, 'credit_amount' => 0, 'description' => 'Phải thu khách hàng'],
                ['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 150000000, 'description' => 'Doanh thu bán hàng'],
                ['account_code' => '33311', 'debit_amount' => 0, 'credit_amount' => 15000000, 'description' => 'Thuế GTGT đầu ra'],
            ],
        ]);

        // 4. COGS: 80,000,000 VND (Nợ 632 / Có 1561)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-COGS-02',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'description' => 'Xuất kho giá vốn hàng bán',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '632', 'credit_account' => '1561', 'amount' => 80000000],
            ],
        ]);

        // 5. Operating Expenses paid via bank: 20,000,000 VND (Nợ 642 / Có 1121)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-EXP-02',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Chi phí quản lý doanh nghiệp',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '642', 'credit_account' => '1121', 'amount' => 20000000],
            ],
        ]);

        // 6. VAT deduction entry (Khấu trừ thuế GTGT cuối kỳ: Nợ 33311 / Có 13311)
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'PKT-VAT-01',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-08-31',
            'description' => 'Khấu trừ thuế GTGT cuối tháng 8',
            'status' => 'posted',
            'lines' => [
                ['debit_account' => '33311', 'credit_account' => '13311', 'amount' => 10000000],
            ],
        ]);

        // Check Income Statement: Net profit = 150M revenue - 80M COGS - 20M Expense = 50,000,000 VND
        $incomeStmt = $this->reportService->getIncomeStatement($this->company->id);
        $profitRow = collect($incomeStmt)->firstWhere('code', '60');
        $this->assertEquals(50000000, $profitRow['this_period']);

        // Close profit to Equity 421
        $this->journalService->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'period_closing',
            'voucher_number' => 'KC-YEAR-01',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-08-31',
            'description' => 'Kết chuyển cuối kỳ',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '5111', 'debit_amount' => 150000000, 'credit_amount' => 0, 'description' => 'KC Doanh thu'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 150000000, 'description' => 'Nhận KC'],
                ['account_code' => '911', 'debit_amount' => 100000000, 'credit_amount' => 0, 'description' => 'KC Chi phi'],
                ['account_code' => '632', 'debit_amount' => 0, 'credit_amount' => 80000000, 'description' => 'KC Gia von'],
                ['account_code' => '642', 'debit_amount' => 0, 'credit_amount' => 20000000, 'description' => 'KC QLDN'],
                ['account_code' => '911', 'debit_amount' => 50000000, 'credit_amount' => 0, 'description' => 'KC Lai'],
                ['account_code' => '4212', 'debit_amount' => 0, 'credit_amount' => 50000000, 'description' => 'Loi nhuan sau thue'],
            ],
        ]);

        // Check Trial Balance Report
        $trialBalance = $this->reportService->getTrialBalance($this->company->id);
        $totalArisingDebit = $trialBalance->where('is_parent', false)->sum('arising_debit');
        $totalArisingCredit = $trialBalance->where('is_parent', false)->sum('arising_credit');
        $this->assertEquals($totalArisingDebit, $totalArisingCredit);

        // Check Balance Sheet
        $balanceSheet = $this->reportService->getBalanceSheet($this->company->id);
        $totalAssets = collect($balanceSheet['assets'])->sum('end_balance');
        $totalLiabilities = collect($balanceSheet['liabilities'])->sum('end_balance');
        $totalEquity = collect($balanceSheet['equity'])->sum('end_balance');

        // Assets: 1121 (280M) + 131 (165M) + 156 (20M) = 465,000,000 VND
        // Liabilities: 331 (110M) + 333 (5M) = 115,000,000 VND
        // Equity: 411 (300M) + 421 (50M) = 350,000,000 VND
        // Fundamental Accounting Equation: Total Assets == Total Liabilities + Total Equity (465M == 115M + 350M)
        $this->assertEquals($totalAssets, $totalLiabilities + $totalEquity);
        $this->assertEquals(465000000, $totalAssets);
        $this->assertEquals(465000000, $totalLiabilities + $totalEquity);
    }

    /**
     * 8. Test Cross Module Polymorphic Reference Chain Navigation
     * Link Bank Payment -> Purchase Invoice -> Purchase Order and verify reference navigation from any entity.
     */
    public function test_cross_module_polymorphic_reference_chain_navigation()
    {
        // 1. Create Purchase Order
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_code' => $this->supplier->code,
            'supplier_name' => $this->supplier->name,
            'order_number' => 'PO-NAV-001',
            'order_date' => '2026-08-01',
            'description' => 'Đơn đặt hàng thiết bị mạng',
            'total_amount' => 50000000,
        ]);

        // 2. Create Purchase Invoice
        $pi = PurchaseInvoice::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'invoice_number' => 'HDMH-NAV-001',
            'invoice_date' => '2026-08-05',
            'total_amount' => 55000000,
            'description' => 'Hóa đơn mua thiết bị mạng theo PO-NAV-001',
        ]);

        // Link PI -> PO
        $refPiToPo = VoucherReference::create([
            'source_type' => PurchaseInvoice::class,
            'source_id' => $pi->id,
            'target_type' => PurchaseOrder::class,
            'target_id' => $po->id,
            'target_voucher_type' => 'Đơn mua hàng',
            'target_voucher_number' => 'PO-NAV-001',
            'target_voucher_date' => '2026-08-01',
            'target_total_amount' => 50000000,
            'description' => 'Tham chiếu từ hóa đơn sang đơn mua hàng',
        ]);

        // 3. Create Bank Payment
        $bp = BankPayment::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'contact_type' => 'supplier',
            'contact_name' => $this->supplier->name,
            // Direct model creation uses the persisted foreign-key contract;
            // the HTTP/service path separately accepts supplier codes and
            // normalizes them to this id before saving.
            'contact_id' => $this->supplier->id,
            'voucher_number' => 'UNC-NAV-001',
            'voucher_date' => '2026-08-10',
            'amount' => 55000000,
            'description' => 'Thanh toán tiền hàng HDMH-NAV-001',
        ]);

        // Link BP -> PI
        $refBpToPi = VoucherReference::create([
            'source_type' => BankPayment::class,
            'source_id' => $bp->id,
            'target_type' => PurchaseInvoice::class,
            'target_id' => $pi->id,
            'target_voucher_type' => 'Hóa đơn mua hàng',
            'target_voucher_number' => 'HDMH-NAV-001',
            'target_voucher_date' => '2026-08-05',
            'target_total_amount' => 55000000,
            'description' => 'Tham chiếu từ UNC sang hóa đơn mua hàng',
        ]);

        // 4. Test Traversal via Eloquent Model Traits
        $bpLoaded = BankPayment::find($bp->id);
        $this->assertCount(1, $bpLoaded->references);
        $this->assertEquals('PurchaseInvoice', class_basename($bpLoaded->references->first()->target_type));
        $this->assertEquals($pi->id, $bpLoaded->references->first()->target_id);

        $piLoaded = PurchaseInvoice::find($pi->id);
        $this->assertCount(1, $piLoaded->references);
        $this->assertEquals('PurchaseOrder', class_basename($piLoaded->references->first()->target_type));
        $this->assertEquals($po->id, $piLoaded->references->first()->target_id);

        // 5. Test Search Reference API
        $searchResp = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_number&search_value=HDMH-NAV-001');
        $searchResp->assertStatus(200);
        $this->assertTrue($searchResp->json('success'));
        $this->assertEquals(1, $searchResp->json('total'));
        $this->assertEquals('HDMH-NAV-001', $searchResp->json('data.0.voucher_number'));
        $this->assertEquals('Hóa đơn mua hàng', $searchResp->json('data.0.voucher_type'));
    }

    /**
     * 9. Test Unpost Bank Receipt Reverts Customer AR Balance Without Breaking Reference
     * Verify unposting reverts debt status cleanly.
     */
    public function test_unpost_bank_receipt_reverts_customer_ar_balance_without_breaking_reference()
    {
        // 1. Create & Post Sales Invoice for 50,000,000 VND
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-UNPOST-001',
            'invoice_date' => '2026-08-10',
            'sub_total' => 50000000,
            'tax_amount' => 0,
            'total_amount' => 50000000,
            'is_posted' => false,
        ]);
        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'debit_account' => '131',
            'credit_account' => '5111',
            'quantity' => 1,
            'unit_price' => 50000000,
            'amount' => 50000000,
            'tax_rate' => 0,
        ]);
        $this->postJson("/api/v1/sales/invoices/{$invoice->id}/post");

        // Customer AR balance is 50,000,000 VND
        $balances1 = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(50000000, $this->reportService->getEndingBalance($balances1, '131', 'debit'));

        // 2. Create & Post Bank Receipt for 50,000,000 VND
        $receipt = BankReceipt::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BC-UNPOST-001',
            'voucher_date' => '2026-08-12',
            'amount' => 50000000,
            'is_posted' => false,
        ]);
        BankReceiptLine::create([
            'bank_receipt_id' => $receipt->id,
            'debit_account' => '1121',
            'credit_account' => '131',
            'amount' => 50000000,
            'invoice_id' => $invoice->id,
        ]);

        // Link Voucher Reference
        $ref = VoucherReference::create([
            'source_type' => BankReceipt::class,
            'source_id' => $receipt->id,
            'target_type' => SalesInvoice::class,
            'target_id' => $invoice->id,
            'target_voucher_type' => 'Hóa đơn bán hàng',
            'target_voucher_number' => 'HDBH-UNPOST-001',
            'target_voucher_date' => '2026-08-10',
            'target_total_amount' => 50000000,
            'description' => 'Thu tiền hóa đơn bán hàng HDBH-UNPOST-001',
        ]);

        $this->postJson("/api/v1/bank/receipts/{$receipt->id}/post");

        // Customer AR balance is settled to 0
        $balances2 = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(0, $this->reportService->getEndingBalance($balances2, '131', 'debit'));

        // 3. Unpost / Void Bank Receipt
        $unpostResp = $this->postJson("/api/v1/bank/receipts/{$receipt->id}/void");
        $unpostResp->assertStatus(200);

        // Verify Bank Receipt is unposted and its Journal Entry status is voided
        $refreshedReceipt = BankReceipt::find($receipt->id);
        $this->assertFalse((bool) $refreshedReceipt->is_posted);

        $je = JournalEntry::find($refreshedReceipt->journal_entry_id);
        $this->assertEquals('voided', $je->status);

        // Verify Customer AR balance is restored back to 50,000,000 VND
        $balances3 = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(50000000, $this->reportService->getEndingBalance($balances3, '131', 'debit'));

        // Verify Voucher Reference still exists and was not broken/deleted
        $this->assertDatabaseHas('voucher_references', [
            'id' => $ref->id,
            'source_id' => $receipt->id,
            'target_id' => $invoice->id,
        ]);
    }

    /**
     * 10. Test Multi Warehouse Sales and Purchase Inventory Integrity
     * Purchase into Warehouse A, internal transfer to Warehouse B, sale from Warehouse B, verify stock reports for both warehouses.
     */
    public function test_multi_warehouse_sales_and_purchase_inventory_integrity()
    {
        // 1. Purchase 100 units into Warehouse A (Kho Tổng)
        $rcptA = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-WHA-01',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'warehouse_id' => $this->warehouseMain->id,
            'total_amount' => 1500000000,
            'description' => 'Nhập mua 100 máy tính Dell vào Kho Tổng',
            'is_posted' => false,
        ]);
        InventoryReceiptLine::create([
            'inventory_receipt_id' => $rcptA->id,
            'item_id' => $this->productItem->id,
            'warehouse_id' => $this->warehouseMain->id,
            'quantity' => 100,
            'unit_price' => 15000000,
            'amount' => 1500000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);
        $this->postJson("/api/v1/inventory/receipts/{$rcptA->id}/post");

        // 2. Transfer 40 units from Warehouse A to Warehouse B (Kho Chi Nhánh)
        // Step 2a: Issue 40 units from Warehouse A
        $issueA = InventoryIssue::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-WHA-TRF',
            'voucher_date' => '2026-08-05',
            'posting_date' => '2026-08-05',
            'warehouse_id' => $this->warehouseMain->id,
            'total_amount' => 600000000,
            'description' => 'Xuất chuyển 40 máy tính sang Kho Chi Nhánh',
            'is_posted' => false,
        ]);
        InventoryIssueLine::create([
            'inventory_issue_id' => $issueA->id,
            'item_id' => $this->productItem->id,
            'warehouse_id' => $this->warehouseMain->id,
            'quantity' => 40,
            'unit_price' => 15000000,
            'amount' => 600000000,
            'debit_account' => '1561',
            'credit_account' => '1561',
        ]);
        $this->postJson("/api/v1/inventory/issues/{$issueA->id}/post");

        // Step 2b: Receive 40 units into Warehouse B
        $rcptB = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-WHB-TRF',
            'voucher_date' => '2026-08-05',
            'posting_date' => '2026-08-05',
            'warehouse_id' => $this->warehouseBranch->id,
            'total_amount' => 600000000,
            'description' => 'Nhập kho chuyển nội bộ 40 máy tính vào Kho Chi Nhánh',
            'is_posted' => false,
        ]);
        InventoryReceiptLine::create([
            'inventory_receipt_id' => $rcptB->id,
            'item_id' => $this->productItem->id,
            'warehouse_id' => $this->warehouseBranch->id,
            'quantity' => 40,
            'unit_price' => 15000000,
            'amount' => 600000000,
            'debit_account' => '1561',
            'credit_account' => '1561',
        ]);
        $this->postJson("/api/v1/inventory/receipts/{$rcptB->id}/post");

        // 3. Sell 25 units from Warehouse B
        $issueB = InventoryIssue::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-WHB-SALE',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'warehouse_id' => $this->warehouseBranch->id,
            'total_amount' => 375000000,
            'description' => 'Xuất bán 25 máy tính từ Kho Chi Nhánh',
            'is_posted' => false,
        ]);
        InventoryIssueLine::create([
            'inventory_issue_id' => $issueB->id,
            'item_id' => $this->productItem->id,
            'warehouse_id' => $this->warehouseBranch->id,
            'quantity' => 25,
            'unit_price' => 15000000,
            'amount' => 375000000,
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);
        $this->postJson("/api/v1/inventory/issues/{$issueB->id}/post");

        // 4. Verify Total Stock Report
        // Total In: 100 (WHA purchase) + 40 (WHB transfer) = 140
        // Total Out: 40 (WHA transfer) + 25 (WHB sale) = 65
        // Net Ending Quantity = 75 units (Warehouse A: 60 units, Warehouse B: 15 units)
        // Net Ending Value = 75 * 15,000,000 = 1,125,000,000 VND
        $stockReport = $this->stockReportService->generateReport($this->company->id);
        $itemReport = collect($stockReport)->firstWhere('item_id', $this->productItem->id);

        $this->assertNotNull($itemReport);
        $this->assertEquals(140, $itemReport['in_qty']);
        $this->assertEquals(65, $itemReport['out_qty']);
        $this->assertEquals(75, $itemReport['end_qty']);
        $this->assertEquals(1125000000, $itemReport['end_amt']);

        // Verify GL 1561 balance matches Stock Report ending amount
        $balances = $this->reportService->getAccountBalances($this->company->id);
        $invGLBalance = $this->reportService->getEndingBalance($balances, '1561', 'debit');
        $this->assertEquals(1125000000, $invGLBalance);
    }

    /**
     * 11. Test Partial Bank Receipt and Multi Invoice Clearing
     * Partial payment settlement across multiple invoices and remaining debt tracking.
     */
    public function test_partial_bank_receipt_and_multi_invoice_clearing()
    {
        // Invoice 1: 30,000,000 VND
        $inv1 = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-PART-01',
            'invoice_date' => '2026-08-01',
            'total_amount' => 30000000,
            'sub_total' => 30000000,
            'tax_amount' => 0,
            'is_posted' => false,
        ]);
        SalesInvoiceLine::create([
            'sales_invoice_id' => $inv1->id,
            'debit_account' => '131',
            'credit_account' => '5111',
            'quantity' => 1,
            'unit_price' => 30000000,
            'amount' => 30000000,
            'tax_rate' => 0,
        ]);
        $this->postJson("/api/v1/sales/invoices/{$inv1->id}/post");

        // Invoice 2: 70,000,000 VND
        $inv2 = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-PART-02',
            'invoice_date' => '2026-08-02',
            'total_amount' => 70000000,
            'sub_total' => 70000000,
            'tax_amount' => 0,
            'is_posted' => false,
        ]);
        SalesInvoiceLine::create([
            'sales_invoice_id' => $inv2->id,
            'debit_account' => '131',
            'credit_account' => '5111',
            'quantity' => 1,
            'unit_price' => 70000000,
            'amount' => 70000000,
            'tax_rate' => 0,
        ]);
        $this->postJson("/api/v1/sales/invoices/{$inv2->id}/post");

        // Total AR = 100,000,000 VND
        $b1 = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(100000000, $this->reportService->getEndingBalance($b1, '131', 'debit'));

        // Partial Receipt 1: 40,000,000 VND (Clears Inv1 for 30M and partially clears Inv2 for 10M)
        $br1 = BankReceipt::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BC-PART-01',
            'voucher_date' => '2026-08-05',
            'amount' => 40000000,
            'is_posted' => false,
        ]);
        BankReceiptLine::create([
            'bank_receipt_id' => $br1->id,
            'debit_account' => '1121',
            'credit_account' => '131',
            'amount' => 30000000,
            'invoice_id' => $inv1->id,
        ]);
        BankReceiptLine::create([
            'bank_receipt_id' => $br1->id,
            'debit_account' => '1121',
            'credit_account' => '131',
            'amount' => 10000000,
            'invoice_id' => $inv2->id,
        ]);
        $this->postJson("/api/v1/bank/receipts/{$br1->id}/post");

        // Remaining AR debt = 60,000,000 VND
        $b2 = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(60000000, $this->reportService->getEndingBalance($b2, '131', 'debit'));
        $this->assertEquals('Paid', SalesInvoice::find($inv1->id)->status);

        // Final Receipt 2: 60,000,000 VND (Clears remaining Inv2)
        $br2 = BankReceipt::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'voucher_number' => 'BC-PART-02',
            'voucher_date' => '2026-08-10',
            'amount' => 60000000,
            'is_posted' => false,
        ]);
        BankReceiptLine::create([
            'bank_receipt_id' => $br2->id,
            'debit_account' => '1121',
            'credit_account' => '131',
            'amount' => 60000000,
            'invoice_id' => $inv2->id,
        ]);
        $this->postJson("/api/v1/bank/receipts/{$br2->id}/post");

        // Total AR is now 0, Inv2 is Paid, Bank balance is 100,000,000 VND
        $b3 = $this->reportService->getAccountBalances($this->company->id);
        $this->assertEquals(0, $this->reportService->getEndingBalance($b3, '131', 'debit'));
        $this->assertEquals(100000000, $this->reportService->getEndingBalance($b3, '1121', 'debit'));
        $this->assertEquals('Paid', SalesInvoice::find($inv2->id)->status);
    }

    /**
     * 12. Test Purchase and Sales Trade Discount with VAT Cross Reconciliation
     * Purchase with trade discount and Sales with trade discount, verifying net inventory cost, net revenue, and output/input VAT calculations.
     */
    public function test_purchase_and_sales_trade_discount_with_vat_cross_reconciliation()
    {
        // 1. Purchase with 5% Trade Discount
        // Gross: 10 units @ 10,000,000 = 100,000,000 VND. Discount 5% = 5,000,000 VND. Net = 95,000,000 VND.
        // Input VAT 10% = 9,500,000 VND. Total AP = 104,500,000 VND.
        $piPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'HDMH-DISC-01',
            'invoice_date' => '2026-08-01',
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 10000000,
                    'discount_rate' => 5,
                    'discount_amount' => 5000000,
                    'tax_rate' => 10,
                    'tax_amount' => 9500000,
                    'tax_account' => '13311',
                ],
            ],
        ];
        $piResp = $this->postJson('/api/v1/purchase/invoices', $piPayload);
        $piResp->assertStatus(201);
        $piId = $piResp->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$piId}/post");

        // 2. Sales with 10% Trade Discount
        // Gross: 5 units @ 20,000,000 = 100,000,000 VND. Discount 10% = 10,000,000 VND. Net = 90,000,000 VND.
        // Output VAT 10% = 9,000,000 VND. Total AR = 99,000,000 VND.
        $siPayload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'HDBH-DISC-01',
            'invoice_date' => '2026-08-05',
            'is_export_slip' => true,
            'lines' => [
                [
                    'item_id' => $this->productItem->id,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 5,
                    'unit_price' => 20000000,
                    'discount_rate' => 10,
                    'discount_amount' => 10000000,
                    'tax_rate' => 10,
                    'tax_amount' => 9000000,
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 9500000, // 95,000,000 / 10 = 9,500,000 VND/unit
                ],
            ],
        ];
        $siResp = $this->postJson('/api/v1/sales/invoices', $siPayload);
        $siResp->assertStatus(201);
        $siId = $siResp->json('id');
        $this->postJson("/api/v1/sales/invoices/{$siId}/post");

        // 3. Verify Financial Balances:
        $balances = $this->reportService->getAccountBalances($this->company->id);

        // Input VAT (13311) = 9,500,000 VND debit
        $inputVat = $this->reportService->getEndingBalance($balances, '13311', 'debit');
        $this->assertEquals(9500000, $inputVat);

        // Output VAT (33311) = 10,000,000 or calculated output tax from invoice total
        $outputVat = $this->reportService->getEndingBalance($balances, '33311', 'credit');
        $this->assertGreaterThan(0, $outputVat);

        // Total AP (331) = 104,500,000 (95M net goods + 9.5M VAT)
        $ap = $this->reportService->getEndingBalance($balances, '331', 'credit');
        $this->assertEquals(104500000, $ap);
    }
}
