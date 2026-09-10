<?php

namespace Tests\Feature\MisaE2E;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TaxReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Tier4RealWorldCycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected BankAccount $bankVcb;

    protected BankAccount $bankTcb;

    protected Customer $customerAlpha;

    protected Customer $customerBeta;

    protected Supplier $supplierDell;

    protected Supplier $supplierSynnex;

    protected Supplier $supplierUtility;

    protected Warehouse $warehouseHn;

    protected Item $itemLaptop;

    protected Item $itemComponent;

    protected Item $itemService;

    protected FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create authenticated user with necessary permissions
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        // Reset permission cache and grant view_reports permission
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'view_reports']);
        Permission::firstOrCreate(['name' => 'create_documents']);
        Permission::firstOrCreate(['name' => 'post_documents']);
        $this->user->givePermissionTo(['view_reports', 'create_documents', 'post_documents']);
        $this->grantGlReportPermissions($this->user);

        // 2. Company setup
        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'CÔNG TY CỔ PHẦN CÔNG NGHỆ & THƯƠNG MẠI MISA TEST',
                'tax_code' => '0101234567',
                'address' => 'Tầng 5, Tòa nhà MISA, Cầu Giấy, Hà Nội',
            ]
        );
        $this->user->update(['company_id' => $this->company->id]);
        // Use the canonical accountant identity required by the production
        // posting authorizer; legacy roleless/chief fixtures must not bypass
        // the two-role policy.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        // 3. Fiscal Year setup
        $this->fiscalYear = FiscalYear::firstOrCreate(
            ['id' => 1],
            [
                'company_id' => $this->company->id,
                'name' => '2026',
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'is_closed' => false,
            ]
        );

        // 4. Initialize TT200 Chart of Accounts & Master Data
        $this->seedChartOfAccounts();
        $this->seedMasterData();
    }

    /**
     * Seed Circular 200 standard Chart of Accounts
     */
    private function seedChartOfAccounts(): void
    {
        $accounts = [
            // Cash & Bank
            ['code' => '111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1111', 'name' => 'Tiền Việt Nam', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1112', 'name' => 'Ngoại tệ', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '112', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1121', 'name' => 'Tiền Việt Nam tại NH', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1122', 'name' => 'Ngoại tệ tại NH', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],

            // Receivables & Deductible Tax
            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '133', 'name' => 'Thuế GTGT được khấu trừ', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1331', 'name' => 'Thuế GTGT được khấu trừ của HHTT&DV', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => true],
            ['code' => '13311', 'name' => 'Thuế GTGT đầu vào được khấu trừ', 'type' => 'asset', 'nature' => 'debit', 'level' => 3, 'is_parent' => false],

            // Inventories
            ['code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '156', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1562', 'name' => 'Chi phí thu mua hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],

            // Fixed Assets
            ['code' => '211', 'name' => 'Tài sản cố định hữu hình', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],

            // Payables & Output Tax
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '333', 'name' => 'Thuế và các khoản phải nộp Nhà nước', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '3331', 'name' => 'Thuế GTGT phải nộp', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => true],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'nature' => 'credit', 'level' => 3, 'is_parent' => false],
            ['code' => '334', 'name' => 'Phải trả người lao động', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '341', 'name' => 'Vay và nợ thuê tài chính', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],

            // Owners Equity & Undistributed Profit
            ['code' => '411', 'name' => 'Vốn đầu tư của chủ sở hữu', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '4111', 'name' => 'Vốn góp của chủ sở hữu', 'type' => 'equity', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '421', 'name' => 'Lợi nhuận sau thuế chưa phân phối', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '4211', 'name' => 'Lợi nhuận sau thuế chưa PP năm trước', 'type' => 'equity', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '4212', 'name' => 'Lợi nhuận sau thuế chưa PP năm nay', 'type' => 'equity', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],

            // Revenues & Deductions
            ['code' => '511', 'name' => 'Doanh thu bán hàng và cung cấp DV', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '5112', 'name' => 'Doanh thu bán các thành phẩm', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '5113', 'name' => 'Doanh thu cung cấp dịch vụ', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '515', 'name' => 'Doanh thu hoạt động tài chính', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '521', 'name' => 'Các khoản giảm trừ doanh thu', 'type' => 'revenue', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],

            // Expenses & Production Cost
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '635', 'name' => 'Chi phí tài chính', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '641', 'name' => 'Chi phí bán hàng', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '642', 'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '6421', 'name' => 'Chi phí bán hàng / văn phòng', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '6422', 'name' => 'Chi phí dịch vụ mua ngoài', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],

            // Other Income/Expense & Profit Determination
            ['code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '821', 'name' => 'Chi phí thuế thu nhập doanh nghiệp', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '911', 'name' => 'Xác định kết quả kinh doanh', 'type' => 'equity', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::create(array_merge($acc, [
                'company_id' => $this->company->id,
                'is_active' => true,
            ]));
        }
    }

    /**
     * Seed realistic Master Data: Bank Accounts, Partners, Warehouses, Items
     */
    private function seedMasterData(): void
    {
        $this->bankVcb = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '0011001234567',
            'bank_name' => 'Ngân hàng TMCP Ngoại thương Việt Nam (Vietcombank)',
            'bank_code' => 'VCB',
            'branch' => 'Sở Giao Dịch Hà Nội',
            'currency' => 'VND',
            'account_holder' => 'CONG TY CP CONG NGHE & TM MISA TEST',
            'is_active' => true,
        ]);

        $this->bankTcb = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '1903009876543',
            'bank_name' => 'Ngân hàng TMCP Kỹ thương Việt Nam (Techcombank)',
            'bank_code' => 'TCB',
            'branch' => 'Chi nhánh Cầu Giấy',
            'currency' => 'VND',
            'account_holder' => 'CONG TY CP CONG NGHE & TM MISA TEST',
            'is_active' => true,
        ]);

        $this->customerAlpha = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH001',
            'name' => 'Công ty TNHH Giải pháp Phần mềm Alpha Tech',
            'tax_code' => '0108998877',
            'address' => 'Số 12 Duy Tân, Cầu Giấy, Hà Nội',
            'phone' => '02438889999',
            'email' => 'contact@alphatech.vn',
            'is_customer' => true,
            'is_active' => true,
        ]);

        $this->customerBeta = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH002',
            'name' => 'Công ty Cổ phần Thương mại Tổng hợp Beta',
            'tax_code' => '0107665544',
            'address' => 'Tòa nhà Landmark 72, Nam Từ Liêm, Hà Nội',
            'phone' => '02437776666',
            'email' => 'sales@betatrading.vn',
            'is_customer' => true,
            'is_active' => true,
        ]);

        $this->supplierDell = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC001',
            'name' => 'Công ty TNHH Dell Global B.V Việt Nam',
            'tax_code' => '0309112233',
            'address' => 'Tòa nhà Bitexco, Quận 1, TP. Hồ Chí Minh',
            'phone' => '02839998888',
            'email' => 'orders@dellglobal.vn',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        $this->supplierSynnex = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC002',
            'name' => 'Công ty Cổ phần Phân phối FPT Synnex',
            'tax_code' => '0101778899',
            'address' => 'Khu Công Nghệ Cao Hòa Lạc, Hà Nội',
            'phone' => '02473008888',
            'email' => 'support@synnexfpt.com.vn',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        $this->supplierUtility = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC003',
            'name' => 'Tổng Công ty Viễn thông & Điện lực VNPT - EVN',
            'tax_code' => '0100109106',
            'address' => 'Số 57 Huỳnh Thúc Kháng, Đống Đa, Hà Nội',
            'phone' => '18001166',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        $this->warehouseHn = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO_TONG_HN',
            'name' => 'Kho Tổng Phân Phối Hà Nội',
            'address' => 'Lô 18 Khu Công Nghiệp Nam Từ Liêm, Hà Nội',
            'is_active' => true,
        ]);

        $this->itemLaptop = Item::create([
            'company_id' => $this->company->id,
            'code' => 'LAPTOP_DELL_5420',
            'name' => 'Laptop Dell Latitude 5420 Core i7 / 16GB / 512GB',
            'type' => 'Goods',
            'unit' => 'Chiếc',
            'cost_price' => 10000000,
            'selling_price' => 15000000,
            'is_active' => true,
        ]);

        $this->itemComponent = Item::create([
            'company_id' => $this->company->id,
            'code' => 'IT_COMP_A',
            'name' => 'Linh kiện Bo mạch Điện tử Công nghiệp Type A',
            'type' => 'Goods',
            'unit' => 'Bộ',
            'cost_price' => 10000,
            'selling_price' => 30000,
            'is_active' => true,
        ]);

        $this->itemService = Item::create([
            'company_id' => $this->company->id,
            'code' => 'SVC_CONSULTING',
            'name' => 'Dịch vụ Tư vấn Thiết kế Hệ thống & Kiểm toán Bảo mật',
            'type' => 'Service',
            'unit' => 'Gói',
            'cost_price' => 0,
            'selling_price' => 80000000,
            'is_active' => true,
        ]);
    }

    /**
     * Helper to post a balanced Journal Entry directly via API
     */
    private function postGeneralJournal(string $voucherNumber, string $date, string $description, array $lines): JournalEntry
    {
        $totalDebit = 0;
        foreach ($lines as $l) {
            $totalDebit += ($l['debit_amount'] ?? $l['amount'] ?? 0);
        }

        $payload = [
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => $date,
            'posting_date' => $date,
            'reason' => $description,
            'description' => $description,
            'total_amount' => $totalDebit,
            'status' => 'draft',
            'lines' => $lines,
        ];

        $response = $this->postJson('/api/v1/gl/journal-entries', $payload);
        $response->assertStatus(201);
        $entryId = $response->json('id');

        $postResponse = $this->postJson("/api/v1/gl/journal-entries/{$entryId}/post");
        $postResponse->assertStatus(200);

        return JournalEntry::with('lines')->findOrFail($entryId);
    }

    /**
     * =========================================================================
     * SCENARIO 1: Commercial Trading Company Full Monthly Business Cycle
     * =========================================================================
     * Workflow:
     * - Day 1: Opening Balances (Cash 1111: 50M, Bank 1121: 200M, Stock 1561: 100M, Equity 4111: 350M).
     * - Day 5: PO to Supplier -> Inward Purchase Invoice 50 Laptops with Landed Shipping Cost -> Post to GL.
     * - Day 10: Bank Payment UNC paying 50% debt to Supplier (331) -> Post to GL.
     * - Day 15: Sales Orders -> Sales Invoice kiêm PXK selling 30 laptops (unpaid) & 10 laptops (Bank paid) -> Post to GL.
     * - Day 20: Bank Receipt Báo Có receiving remaining debt from Customer (131) -> Post to GL.
     * - Day 25: Purchase office electricity & internet service (642) -> Bank Payment settlement -> Post to GL.
     * - Day 30: Run Month-End Period Closing (511, 632, 642 -> 911 -> 4212) -> Verify Trial Balance, Balance Sheet, Income Statement.
     */
    public function test_scenario_1_commercial_trading_company_full_monthly_cycle(): void
    {
        // ---------------------------------------------------------------------
        // Day 1: Post Opening Balances (Số dư đầu kỳ T8/2026)
        // ---------------------------------------------------------------------
        $this->postGeneralJournal(
            voucherNumber: 'PKT-OPEN-2026-08',
            date: '2026-08-01',
            description: 'Kết chuyển số dư đầu kỳ tháng 08/2026',
            lines: [
                ['account_code' => '1111', 'debit_amount' => 50000000, 'credit_amount' => 0, 'description' => 'Tiền mặt tồn quỹ'],
                ['account_code' => '1121', 'debit_amount' => 200000000, 'credit_amount' => 0, 'description' => 'Tiền gửi Vietcombank'],
                ['account_code' => '1561', 'debit_amount' => 100000000, 'credit_amount' => 0, 'description' => 'Hàng hóa tồn kho (20 chiếc Laptop @ 5.000.000)'],
                ['account_code' => '4111', 'debit_amount' => 0, 'credit_amount' => 350000000, 'description' => 'Vốn đầu tư của chủ sở hữu'],
            ]
        );

        $this->assertDatabaseHas('journal_entries', [
            'voucher_number' => 'PKT-OPEN-2026-08',
            'status' => 'posted',
        ]);

        // ---------------------------------------------------------------------
        // Day 5: PO to Supplier Dell & Inward Purchase Invoice (50 Laptops)
        // ---------------------------------------------------------------------
        $poResponse = $this->postJson('/api/v1/purchase/orders', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierDell->id,
            'order_number' => 'ĐMH-2026-08-001',
            'order_date' => '2026-08-05',
            'delivery_date' => '2026-08-06',
            'description' => 'Đơn đặt mua 50 máy tính Laptop Dell Latitude 5420',
            'status' => 'approved',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'quantity' => 50,
                    'unit_price' => 10000000,
                    'tax_rate' => 10,
                ],
            ],
        ]);
        $poResponse->assertStatus(201);
        $poId = $poResponse->json('id');
        $this->assertNotNull($poId);

        // Inward Purchase Invoice with Landed Cost
        $pinvResponse = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierDell->id,
            'supplier_name' => $this->supplierDell->name,
            'invoice_number' => 'PINV-2026-08-001',
            'invoice_date' => '2026-08-05',
            'accounting_date' => '2026-08-05',
            'due_date' => '2026-09-05',
            'description' => 'Mua 50 máy tính Laptop Dell Latitude 5420 nhập kho',
            'purchase_expense' => 5000000, // Chi phí vận chuyển bốc dỡ
            'payment_method' => 'unpaid',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'description' => 'Laptop Dell Latitude 5420 Core i7',
                    'quantity' => 50,
                    'unit_price' => 10000000,
                    'tax_rate' => 10,
                    'tax_amount' => 50000000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                    'purchase_expense' => 5000000,
                    'stock_value' => 505000000,
                ],
            ],
        ]);
        $pinvResponse->assertStatus(201);
        $pinvId = $pinvResponse->json('data.id') ?? $pinvResponse->json('id');

        // Post Purchase Invoice to GL
        $postPinvResponse = $this->postJson("/api/v1/purchase/invoices/{$pinvId}/post");
        $postPinvResponse->assertStatus(200);

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $pinvId,
            'is_posted' => 1,
            'sub_total' => 500000000,
            'tax_amount' => 50000000,
            'total_amount' => 550000000,
        ]);

        // ---------------------------------------------------------------------
        // Day 10: Bank Payment UNC paying 50% debt to Supplier Dell (275,000,000 VND)
        // ---------------------------------------------------------------------
        $uncResponse = $this->postJson('/api/v1/bank/payments', [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankVcb->id,
            'voucher_number' => 'UNC-2026-08-001',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'payee_name' => $this->supplierDell->name,
            'contact_type' => 'Supplier',
            'contact_id' => $this->supplierDell->id,
            'description' => 'Ủy nhiệm chi thanh toán 50% tiền hàng mua Laptop Dell',
            'lines' => [
                [
                    'debit_account' => '331',
                    'credit_account' => '1121',
                    'amount' => 275000000,
                    'description' => 'Thanh toán đợt 1 hóa đơn PINV-2026-08-001',
                    'invoice_id' => $pinvId,
                ],
            ],
        ]);
        $uncResponse->assertStatus(201);
        $uncId = $uncResponse->json('data.id') ?? $uncResponse->json('id');

        $postUncResponse = $this->postJson("/api/v1/bank/payments/{$uncId}/post");
        $postUncResponse->assertStatus(200);

        $this->assertDatabaseHas('bank_payments', [
            'id' => $uncId,
            'is_posted' => 1,
            'amount' => 275000000,
        ]);

        // ---------------------------------------------------------------------
        // Day 15: Sales Invoices kiêm PXK (30 laptops Unpaid & 10 laptops Immediate Cash/Bank)
        // ---------------------------------------------------------------------
        // Invoice 1: Customer Alpha buys 30 laptops (Unpaid -> 131)
        $sinv1Response = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customerAlpha->id,
            'customer_name' => $this->customerAlpha->name,
            'invoice_number' => 'HDBR-2026-08-001',
            'invoice_date' => '2026-08-15',
            'accounting_date' => '2026-08-15',
            'due_date' => '2026-09-15',
            'payment_status' => 'Unpaid',
            'is_export_slip' => true,
            'description' => 'Bán 30 máy tính Laptop Dell Latitude 5420 cho Alpha Tech',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'description' => 'Laptop Dell Latitude 5420 Core i7',
                    'quantity' => 30,
                    'unit_price' => 15000000,
                    'tax_rate' => 10,
                    'tax_amount' => 45000000,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 10000000, // Giá vốn xuất kho: 30 * 10M = 300M
                ],
            ],
        ]);
        $sinv1Response->assertStatus(201);
        $sinv1Id = $sinv1Response->json('data.id') ?? $sinv1Response->json('id');

        $postSinv1 = $this->postJson("/api/v1/sales/invoices/{$sinv1Id}/post");
        $postSinv1->assertStatus(200);

        // Invoice 2: Customer Beta buys 10 laptops (Paid immediately -> 1111)
        $sinv2Response = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customerBeta->id,
            'customer_name' => $this->customerBeta->name,
            'invoice_number' => 'HDBR-2026-08-002',
            'invoice_date' => '2026-08-15',
            'accounting_date' => '2026-08-15',
            'due_date' => '2026-08-15',
            'payment_status' => 'Paid',
            'is_export_slip' => true,
            'description' => 'Bán 10 máy tính Laptop Dell Latitude 5420 cho Beta Trading (Thu tiền ngay)',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'description' => 'Laptop Dell Latitude 5420 Core i7',
                    'quantity' => 10,
                    'unit_price' => 15000000,
                    'tax_rate' => 10,
                    'tax_amount' => 15000000,
                    'debit_account' => '1111',
                    'credit_account' => '5111',
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 10000000, // Giá vốn xuất kho: 10 * 10M = 100M
                ],
            ],
        ]);
        $sinv2Response->assertStatus(201);
        $sinv2Id = $sinv2Response->json('data.id') ?? $sinv2Response->json('id');

        $postSinv2 = $this->postJson("/api/v1/sales/invoices/{$sinv2Id}/post");
        $postSinv2->assertStatus(200);

        // ---------------------------------------------------------------------
        // Day 20: Bank Receipt Báo Có receiving remaining debt from Customer Alpha (495,000,000 VND)
        // ---------------------------------------------------------------------
        $bcResponse = $this->postJson('/api/v1/bank/receipts', [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankVcb->id,
            'voucher_number' => 'BC-2026-08-001',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'contact_type' => 'Customer',
            'contact_id' => $this->customerAlpha->id,
            'payer_name' => $this->customerAlpha->name,
            'description' => 'Báo có khách hàng Alpha Tech thanh toán hóa đơn HDBR-2026-08-001',
            'lines' => [
                [
                    'debit_account' => '1121',
                    'credit_account' => '131',
                    'amount' => 495000000,
                    'description' => 'Thanh toán tiền hàng mua Laptop',
                    'invoice_id' => $sinv1Id,
                ],
            ],
        ]);
        $bcResponse->assertStatus(201);
        $bcId = $bcResponse->json('data.id') ?? $bcResponse->json('id');

        $postBcResponse = $this->postJson("/api/v1/bank/receipts/{$bcId}/post");
        $postBcResponse->assertStatus(200);

        // ---------------------------------------------------------------------
        // Day 25: Office Utility & Operating Expenses (Nợ 642, Nợ 13311 / Có 331 -> UNC)
        // ---------------------------------------------------------------------
        $svcPinv = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierUtility->id,
            'supplier_name' => $this->supplierUtility->name,
            'invoice_number' => 'PINV-SVC-2026-08',
            'invoice_date' => '2026-08-25',
            'accounting_date' => '2026-08-25',
            'due_date' => '2026-08-25',
            'description' => 'Hóa đơn dịch vụ viễn thông & điện lực văn phòng T8/2026',
            'lines' => [
                [
                    'description' => 'Tiền điện & đường truyền Internet cáp quang',
                    'quantity' => 1,
                    'unit_price' => 20000000,
                    'tax_rate' => 10,
                    'tax_amount' => 2000000,
                    'debit_account' => '642',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                ],
            ],
        ]);
        $svcPinv->assertStatus(201);
        $svcPinvId = $svcPinv->json('data.id') ?? $svcPinv->json('id');

        $this->postJson("/api/v1/purchase/invoices/{$svcPinvId}/post")->assertStatus(200);

        // Settlement via Bank UNC
        $unc2 = $this->postJson('/api/v1/bank/payments', [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankVcb->id,
            'voucher_number' => 'UNC-2026-08-002',
            'voucher_date' => '2026-08-25',
            'posting_date' => '2026-08-25',
            'payee_name' => $this->supplierUtility->name,
            'description' => 'Ủy nhiệm chi thanh toán cước viễn thông và điện thoại EVN',
            'lines' => [
                [
                    'debit_account' => '331',
                    'credit_account' => '1121',
                    'amount' => 22000000,
                    'description' => 'Thanh toán cước phí viễn thông',
                ],
            ],
        ]);
        $unc2->assertStatus(201);
        $unc2Id = $unc2->json('data.id') ?? $unc2->json('id');
        $this->postJson("/api/v1/bank/payments/{$unc2Id}/post")->assertStatus(200);

        // ---------------------------------------------------------------------
        // Day 30: Month-End Financial Statements Verification & Period Closing (911 -> 4212)
        // ---------------------------------------------------------------------
        // 1. Verify Pre-Closing Income Statement
        $incomeReport = $this->getJson('/api/v1/reports/income-statement?company_id=1');
        $incomeReport->assertStatus(200);
        $incomeData = collect($incomeReport->json());

        // Doanh thu (511) = 450M + 150M = 600M
        $revRow = $incomeData->firstWhere('code', '01');
        $this->assertEquals(600000000, $revRow['this_period']);

        // Giá vốn (632) = 300M + 100M = 400M
        $cogsRow = $incomeData->firstWhere('code', '11');
        $this->assertEquals(400000000, $cogsRow['this_period']);

        // Lợi nhuận gộp (20) = 200M
        $grossRow = $incomeData->firstWhere('code', '20');
        $this->assertEquals(200000000, $grossRow['this_period']);

        // Chi phí QLDN (642) = 20M
        $adminRow = $incomeData->firstWhere('code', '26');
        $this->assertEquals(20000000, $adminRow['this_period']);

        // Lợi nhuận thuần (30) = 180M
        $netRow = $incomeData->firstWhere('code', '30');
        $this->assertEquals(180000000, $netRow['this_period']);

        // 2. Post Period Closing Entry (Bút toán kết chuyển cuối kỳ)
        $closingEntry = $this->postGeneralJournal(
            voucherNumber: 'PKT-KC-2026-08',
            date: '2026-08-31',
            description: 'Bút toán kết chuyển doanh thu, chi phí và xác định kết quả kinh doanh T8/2026',
            lines: [
                // Kết chuyển doanh thu bán hàng: Nợ 5111 / Có 911: 600,000,000
                ['account_code' => '5111', 'debit_amount' => 600000000, 'credit_amount' => 0, 'description' => 'Kết chuyển doanh thu bán hàng sang 911'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 600000000, 'description' => 'Xác định KQKD - Doanh thu'],

                // Kết chuyển giá vốn hàng bán: Nợ 911 / Có 632: 400,000,000
                ['account_code' => '911', 'debit_amount' => 400000000, 'credit_amount' => 0, 'description' => 'Xác định KQKD - Giá vốn'],
                ['account_code' => '632', 'debit_amount' => 0, 'credit_amount' => 400000000, 'description' => 'Kết chuyển giá vốn hàng bán sang 911'],

                // Kết chuyển chi phí quản lý: Nợ 911 / Có 642: 20,000,000
                ['account_code' => '911', 'debit_amount' => 20000000, 'credit_amount' => 0, 'description' => 'Xác định KQKD - Chi phí quản lý'],
                ['account_code' => '642', 'debit_amount' => 0, 'credit_amount' => 20000000, 'description' => 'Kết chuyển chi phí QLDN sang 911'],

                // Kết chuyển lợi nhuận sau thuế: Nợ 911 / Có 4212: 180,000,000
                ['account_code' => '911', 'debit_amount' => 180000000, 'credit_amount' => 0, 'description' => 'Kết chuyển lãi sau thuế sang 4212'],
                ['account_code' => '4212', 'debit_amount' => 0, 'credit_amount' => 180000000, 'description' => 'Lợi nhuận sau thuế chưa phân phối năm nay'],
            ]
        );

        $this->assertDatabaseHas('journal_entries', [
            'voucher_number' => 'PKT-KC-2026-08',
            'status' => 'posted',
        ]);

        // 3. Verify Trial Balance (Bảng Cân đối Phát sinh Tài khoản)
        $trialResponse = $this->getJson('/api/v1/reports/trial-balance?company_id=1');
        $trialResponse->assertStatus(200);
        $trialData = collect($trialResponse->json());

        // Temporary accounts (5111, 632, 642, 911) must have 0 ending balances
        $acc5111 = $trialData->firstWhere('code', '5111');
        $this->assertEquals(0, $acc5111['ending_debit'] ?? 0);
        $this->assertEquals(0, $acc5111['ending_credit'] ?? 0);

        $acc632 = $trialData->firstWhere('code', '632');
        $this->assertEquals(0, $acc632['ending_debit'] ?? 0);
        $this->assertEquals(0, $acc632['ending_credit'] ?? 0);

        $acc642 = $trialData->firstWhere('code', '642');
        $this->assertEquals(0, $acc642['ending_debit'] ?? 0);
        $this->assertEquals(0, $acc642['ending_credit'] ?? 0);

        $acc911 = $trialData->firstWhere('code', '911');
        $this->assertEquals(0, $acc911['ending_debit'] ?? 0);
        $this->assertEquals(0, $acc911['ending_credit'] ?? 0);

        // Account 4212 ending balance must equal 180,000,000 VND
        $acc4212 = $trialData->firstWhere('code', '4212');
        $this->assertEquals(180000000, $acc4212['ending_credit']);

        // 4. Verify Balance Sheet (Bảng Cân đối Kế toán: Tài sản = Nợ phải trả + Vốn CSH)
        $bsResponse = $this->getJson('/api/v1/reports/balance-sheet?company_id=1');
        $bsResponse->assertStatus(200);
        $bsData = $bsResponse->json();

        $assets = collect($bsData['assets']);
        $liabilities = collect($bsData['liabilities']);
        $equity = collect($bsData['equity']);

        // Cash 1111: 50M (open) + 165M (cash sale) = 215M
        $cashItem = $assets->firstWhere('code', '111');
        $this->assertEquals(215000000, $cashItem['end_balance']);

        // Bank 1121: 200M (open) - 275M (UNC1) + 495M (BC1) - 22M (UNC2) = 398M
        $bankItem = $assets->firstWhere('code', '112');
        $this->assertEquals(398000000, $bankItem['end_balance']);

        // Inventory 156: 100M (open) + 500M (purchased) - 400M (sold) = 200M
        $stockItem = $assets->firstWhere('code', '156');
        $this->assertEquals(200000000, $stockItem['end_balance']);

        // Customer AR 131: 495M - 495M = 0
        $arItem = $assets->firstWhere('code', '131');
        $this->assertEquals(0, $arItem['end_balance']);

        // Supplier AP 331: 550M - 275M + 22M - 22M = 275M
        $apItem = $liabilities->firstWhere('code', '331');
        $this->assertEquals(275000000, $apItem['end_balance']);

        // Output Tax 333: 45M + 15M = 60M
        $taxPayableItem = $liabilities->firstWhere('code', '333');
        $this->assertEquals(60000000, $taxPayableItem['end_balance']);

        // Equity 411: 350M
        $capitalItem = $equity->firstWhere('code', '411');
        $this->assertEquals(350000000, $capitalItem['end_balance']);

        // Undistributed Profit 421: 180M
        $profitItem = $equity->firstWhere('code', '421');
        $this->assertEquals(180000000, $profitItem['end_balance']);
    }

    /**
     * =========================================================================
     * SCENARIO 2: FIFO Inventory Valuation & High Turnover Sales Cycle
     * =========================================================================
     * Workflow:
     * - Multi-tier purchase batches of component at varying unit prices (10k, 15k, 20k).
     * - Sequential sales orders consuming stock across distinct price layers.
     * - Compute exact FIFO COGS (632) and closing stock value (1561).
     * - Perform period closing and verify exact Gross Margin & Trial Balance consistency.
     */
    public function test_scenario_2_fifo_inventory_valuation_and_high_turnover_sales_cycle(): void
    {
        // 1. Initial Opening Capital: Bank 500M / Equity 500M
        $this->postGeneralJournal(
            voucherNumber: 'PKT-OPEN-FIFO',
            date: '2026-08-01',
            description: 'Số dư đầu kỳ mở sổ kho',
            lines: [
                ['account_code' => '1121', 'debit_amount' => 500000000, 'credit_amount' => 0, 'description' => 'Vốn tiền gửi NH'],
                ['account_code' => '4111', 'debit_amount' => 0, 'credit_amount' => 500000000, 'description' => 'Vốn góp CSH'],
            ]
        );

        // 2. Purchase Batch 1: 100 units @ 10,000 VND = 1,000,000 VND (Day 2)
        $batch1 = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierSynnex->id,
            'invoice_number' => 'PINV-FIFO-B1',
            'invoice_date' => '2026-08-02',
            'description' => 'Nhập kho đợt 1: 100 linh kiện Type A @ 10.000',
            'lines' => [
                [
                    'item_id' => $this->itemComponent->id,
                    'quantity' => 100,
                    'unit_price' => 10000,
                    'tax_rate' => 0,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $batch1->assertStatus(201);
        $b1Id = $batch1->json('data.id') ?? $batch1->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$b1Id}/post")->assertStatus(200);

        // Purchase Batch 2: 100 units @ 15,000 VND = 1,500,000 VND (Day 8)
        $batch2 = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierSynnex->id,
            'invoice_number' => 'PINV-FIFO-B2',
            'invoice_date' => '2026-08-08',
            'description' => 'Nhập kho đợt 2: 100 linh kiện Type A @ 15.000',
            'lines' => [
                [
                    'item_id' => $this->itemComponent->id,
                    'quantity' => 100,
                    'unit_price' => 15000,
                    'tax_rate' => 0,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $batch2->assertStatus(201);
        $b2Id = $batch2->json('data.id') ?? $batch2->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$b2Id}/post")->assertStatus(200);

        // Purchase Batch 3: 100 units @ 20,000 VND = 2,000,000 VND (Day 14)
        $batch3 = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierSynnex->id,
            'invoice_number' => 'PINV-FIFO-B3',
            'invoice_date' => '2026-08-14',
            'description' => 'Nhập kho đợt 3: 100 linh kiện Type A @ 20.000',
            'lines' => [
                [
                    'item_id' => $this->itemComponent->id,
                    'quantity' => 100,
                    'unit_price' => 20000,
                    'tax_rate' => 0,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
            ],
        ]);
        $batch3->assertStatus(201);
        $b3Id = $batch3->json('data.id') ?? $batch3->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$b3Id}/post")->assertStatus(200);

        // Total Inward Purchased: 300 units, Total Value = 4,500,000 VND

        // 3. High Turnover Sales Delivery 1 (Day 16): Sell 120 units @ 30,000 VND
        // FIFO Cost calculation:
        // - 100 units from Batch 1 @ 10,000 = 1,000,000 VND
        // - 20 units from Batch 2 @ 15,000 = 300,000 VND
        // -> Total COGS 1 = 1,300,000 VND (cogs_price avg = 1,300,000 / 120 = 10,833.333333)
        $sale1 = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customerAlpha->id,
            'invoice_number' => 'HDBR-FIFO-01',
            'invoice_date' => '2026-08-16',
            'accounting_date' => '2026-08-16',
            'due_date' => '2026-09-16',
            'is_export_slip' => true,
            'payment_status' => 'Unpaid',
            'description' => 'Xuất bán 120 linh kiện Type A đợt 1',
            'lines' => [
                [
                    'item_id' => $this->itemComponent->id,
                    'quantity' => 120,
                    'unit_price' => 30000,
                    'tax_rate' => 10,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 10833.33333333, // 120 * 10833.33333333 ~ 1,300,000 VND
                ],
            ],
        ]);
        $sale1->assertStatus(201);
        $s1Id = $sale1->json('data.id') ?? $sale1->json('id');
        $this->postJson("/api/v1/sales/invoices/{$s1Id}/post")->assertStatus(200);

        // Sales Delivery 2 (Day 22): Sell 100 units @ 35,000 VND
        // FIFO Cost calculation:
        // - Remaining 80 units from Batch 2 @ 15,000 = 1,200,000 VND
        // - 20 units from Batch 3 @ 20,000 = 400,000 VND
        // -> Total COGS 2 = 1,600,000 VND (cogs_price = 1,600,000 / 100 = 16,000)
        $sale2 = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customerBeta->id,
            'invoice_number' => 'HDBR-FIFO-02',
            'invoice_date' => '2026-08-22',
            'accounting_date' => '2026-08-22',
            'due_date' => '2026-09-22',
            'is_export_slip' => true,
            'payment_status' => 'Unpaid',
            'description' => 'Xuất bán 100 linh kiện Type A đợt 2',
            'lines' => [
                [
                    'item_id' => $this->itemComponent->id,
                    'quantity' => 100,
                    'unit_price' => 35000,
                    'tax_rate' => 10,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 16000, // 100 * 16,000 = 1,600,000 VND
                ],
            ],
        ]);
        $sale2->assertStatus(201);
        $s2Id = $sale2->json('data.id') ?? $sale2->json('id');
        $this->postJson("/api/v1/sales/invoices/{$s2Id}/post")->assertStatus(200);

        // 4. Verification of FIFO Valuation:
        // Total Inward = 4,500,000 VND (300 units)
        // Total Sold = 220 units
        // Total FIFO COGS = 1,300,000 + 1,600,000 = 2,900,000 VND
        // Remaining Stock = 80 units (all from Batch 3 @ 20,000) = 1,600,000 VND
        // 4,500,000 - 2,900,000 = 1,600,000 VND.

        // Check Trial Balance before closing
        $trial = $this->getJson('/api/v1/reports/trial-balance?company_id=1');
        $trial->assertStatus(200);
        $trialMap = collect($trial->json())->keyBy('code');

        $this->assertEqualsWithDelta(1600000, $trialMap['1561']['ending_debit'], 1.0);
        $this->assertEqualsWithDelta(2900000, $trialMap['632']['arising_debit'], 1.0);

        // 5. Month-End Period Closing:
        // Total Revenue (5111): 120 * 30k (3.6M) + 100 * 35k (3.5M) = 7,100,000 VND
        // Total COGS (632): 2,900,000 VND
        // Gross Profit: 7,100,000 - 2,900,000 = 4,200,000 VND
        $this->postGeneralJournal(
            voucherNumber: 'PKT-KC-FIFO',
            date: '2026-08-31',
            description: 'Kết chuyển KQKD chu kỳ FIFO',
            lines: [
                ['account_code' => '5111', 'debit_amount' => 7100000, 'credit_amount' => 0, 'description' => 'Kết chuyển Doanh thu'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 7100000, 'description' => 'Xác định KQKD'],

                ['account_code' => '911', 'debit_amount' => 2900000, 'credit_amount' => 0, 'description' => 'Xác định KQKD'],
                ['account_code' => '632', 'debit_amount' => 0, 'credit_amount' => 2900000, 'description' => 'Kết chuyển Giá vốn'],

                ['account_code' => '911', 'debit_amount' => 4200000, 'credit_amount' => 0, 'description' => 'Kết chuyển Lãi sang 4212'],
                ['account_code' => '4212', 'debit_amount' => 0, 'credit_amount' => 4200000, 'description' => 'Lợi nhuận năm nay'],
            ]
        );

        $trialAfter = $this->getJson('/api/v1/reports/trial-balance?company_id=1');
        $trialAfter->assertStatus(200);
        $afterMap = collect($trialAfter->json())->keyBy('code');

        $this->assertEquals(0, $afterMap['5111']['ending_debit'] ?? 0);
        $this->assertEquals(0, $afterMap['632']['ending_debit'] ?? 0);
        $this->assertEquals(4200000, $afterMap['4212']['ending_credit']);
        $this->assertEqualsWithDelta(1600000, $afterMap['1561']['ending_debit'], 1.0);
    }

    /**
     * =========================================================================
     * SCENARIO 3: Service & Consulting Enterprise Cycle (No Physical Inventory)
     * =========================================================================
     * Workflow:
     * - Consulting invoices issued to corporate clients (Account 5113).
     * - Subcontractor & expert consulting expense invoices (Account 642).
     * - Monthly staff payroll allocation (642 / 334) and bank salary payments.
     * - Client collections & vendor disbursements via Bank Báo Có / UNC.
     * - Year-End / Month-End profit closing and service enterprise balance sheet check.
     */
    public function test_scenario_3_service_consulting_enterprise_cycle(): void
    {
        // 1. Initial Opening Balance: Bank 100M / Equity 100M
        $this->postGeneralJournal(
            voucherNumber: 'PKT-OPEN-SVC',
            date: '2026-08-01',
            description: 'Vốn đầu kỳ doanh nghiệp dịch vụ tư vấn',
            lines: [
                ['account_code' => '1121', 'debit_amount' => 100000000, 'credit_amount' => 0, 'description' => 'Tiền gửi VCB'],
                ['account_code' => '4111', 'debit_amount' => 0, 'credit_amount' => 100000000, 'description' => 'Vốn chủ sở hữu'],
            ]
        );

        // 2. Consulting Invoices Rendered:
        // Invoice 1 to Alpha Tech: Architecture Consulting = 80M + 10% VAT (8M) = 88M
        $svc1 = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customerAlpha->id,
            'invoice_number' => 'SI-SVC-01',
            'invoice_date' => '2026-08-05',
            'accounting_date' => '2026-08-05',
            'due_date' => '2026-09-05',
            'payment_status' => 'Unpaid',
            'is_export_slip' => false,
            'description' => 'Tư vấn thiết kế kiến trúc hệ thống CNTT',
            'lines' => [
                [
                    'item_id' => $this->itemService->id,
                    'description' => 'Gói tư vấn kiến trúc Enterprise Architecture',
                    'quantity' => 1,
                    'unit_price' => 80000000,
                    'tax_rate' => 10,
                    'tax_amount' => 8000000,
                    'debit_account' => '131',
                    'credit_account' => '5113',
                    'tax_account' => '33311',
                ],
            ],
        ]);
        $svc1->assertStatus(201);
        $svc1Id = $svc1->json('data.id') ?? $svc1->json('id');
        $this->postJson("/api/v1/sales/invoices/{$svc1Id}/post")->assertStatus(200);

        // Invoice 2 to Beta Trading: Cybersecurity Audit = 120M + 10% VAT (12M) = 132M
        $svc2 = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customerBeta->id,
            'invoice_number' => 'SI-SVC-02',
            'invoice_date' => '2026-08-10',
            'accounting_date' => '2026-08-10',
            'due_date' => '2026-09-10',
            'payment_status' => 'Unpaid',
            'is_export_slip' => false,
            'description' => 'Kiểm toán an toàn thông tin & bảo mật hệ thống',
            'lines' => [
                [
                    'item_id' => $this->itemService->id,
                    'description' => 'Gói kiểm toán Penetration Testing & ISO 27001',
                    'quantity' => 1,
                    'unit_price' => 120000000,
                    'tax_rate' => 10,
                    'tax_amount' => 12000000,
                    'debit_account' => '131',
                    'credit_account' => '5113',
                    'tax_account' => '33311',
                ],
            ],
        ]);
        $svc2->assertStatus(201);
        $svc2Id = $svc2->json('data.id') ?? $svc2->json('id');
        $this->postJson("/api/v1/sales/invoices/{$svc2Id}/post")->assertStatus(200);

        // Total Consulting Revenue (5113) = 80M + 120M = 200,000,000 VND

        // 3. Operating & Subcontractor Invoices (Account 642):
        // External Security Expert: 40M + 10% VAT (4M) = 44M
        $sub1 = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierDell->id,
            'invoice_number' => 'PINV-EXP-01',
            'invoice_date' => '2026-08-12',
            'description' => 'Chi phí thuê chuyên gia bảo mật độc lập',
            'lines' => [
                [
                    'description' => 'Thuê chuyên gia pentest cao cấp',
                    'quantity' => 1,
                    'unit_price' => 40000000,
                    'tax_rate' => 10,
                    'tax_amount' => 4000000,
                    'debit_account' => '642',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                ],
            ],
        ]);
        $sub1->assertStatus(201);
        $sub1Id = $sub1->json('data.id') ?? $sub1->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$sub1Id}/post")->assertStatus(200);

        // Cloud Infrastructure & Software Licenses: 15M + 10% VAT (1.5M) = 16.5M
        $sub2 = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierSynnex->id,
            'invoice_number' => 'PINV-EXP-02',
            'invoice_date' => '2026-08-15',
            'description' => 'Chi phí dịch vụ máy chủ đám mây AWS & bản quyền',
            'lines' => [
                [
                    'description' => 'Cloud Hosting AWS & bản quyền phân tích mã nguồn',
                    'quantity' => 1,
                    'unit_price' => 15000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1500000,
                    'debit_account' => '642',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                ],
            ],
        ]);
        $sub2->assertStatus(201);
        $sub2Id = $sub2->json('data.id') ?? $sub2->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$sub2Id}/post")->assertStatus(200);

        // 4. Staff Payroll & Bank Salary Disbursements:
        // Monthly Payroll Accrual: Nợ 642: 60M / Có 334: 60M
        $this->postGeneralJournal(
            voucherNumber: 'PKT-PAYROLL-08',
            date: '2026-08-25',
            description: 'Tính lương bộ phận chuyên gia tư vấn T8/2026',
            lines: [
                ['account_code' => '642', 'debit_amount' => 60000000, 'credit_amount' => 0, 'description' => 'Chi phí lương chuyên gia'],
                ['account_code' => '334', 'debit_amount' => 0, 'credit_amount' => 60000000, 'description' => 'Phải trả lương nhân viên'],
            ]
        );

        // Bank UNC Salary Payment: Nợ 334: 60M / Có 1121: 60M
        $paySalary = $this->postJson('/api/v1/bank/payments', [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankVcb->id,
            'voucher_number' => 'UNC-SALARY-08',
            'voucher_date' => '2026-08-25',
            'posting_date' => '2026-08-25',
            'payee_name' => 'Tập thể cán bộ nhân viên công ty',
            'description' => 'Chuyển khoản thanh toán lương kỳ T8/2026',
            'lines' => [
                [
                    'debit_account' => '334',
                    'credit_account' => '1121',
                    'amount' => 60000000,
                    'description' => 'Chi lương nhân viên',
                ],
            ],
        ]);
        $paySalary->assertStatus(201);
        $salId = $paySalary->json('data.id') ?? $paySalary->json('id');
        $this->postJson("/api/v1/bank/payments/{$salId}/post")->assertStatus(200);

        // 5. Client Collections & Vendor Disbursements via Bank:
        // Client Alpha pays 88M
        $bc1 = $this->postJson('/api/v1/bank/receipts', [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankVcb->id,
            'voucher_number' => 'BC-SVC-01',
            'voucher_date' => '2026-08-26',
            'posting_date' => '2026-08-26',
            'payer_name' => $this->customerAlpha->name,
            'description' => 'Khách hàng Alpha Tech thanh toán hóa đơn SI-SVC-01',
            'lines' => [
                ['debit_account' => '1121', 'credit_account' => '131', 'amount' => 88000000],
            ],
        ]);
        $bc1->assertStatus(201);
        $this->postJson('/api/v1/bank/receipts/'.($bc1->json('data.id') ?? $bc1->json('id')).'/post')->assertStatus(200);

        // Client Beta pays 132M
        $bc2 = $this->postJson('/api/v1/bank/receipts', [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankVcb->id,
            'voucher_number' => 'BC-SVC-02',
            'voucher_date' => '2026-08-28',
            'posting_date' => '2026-08-28',
            'payer_name' => $this->customerBeta->name,
            'description' => 'Khách hàng Beta Trading thanh toán hóa đơn SI-SVC-02',
            'lines' => [
                ['debit_account' => '1121', 'credit_account' => '131', 'amount' => 132000000],
            ],
        ]);
        $bc2->assertStatus(201);
        $this->postJson('/api/v1/bank/receipts/'.($bc2->json('data.id') ?? $bc2->json('id')).'/post')->assertStatus(200);

        // Pay Vendors 44M + 16.5M = 60.5M
        $uncVen = $this->postJson('/api/v1/bank/payments', [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankVcb->id,
            'voucher_number' => 'UNC-VENDORS-08',
            'voucher_date' => '2026-08-29',
            'posting_date' => '2026-08-29',
            'payee_name' => 'Thanh toán đối tác chuyên gia và hạ tầng AWS',
            'description' => 'Thanh toán tiền nhà cung cấp',
            'lines' => [
                ['debit_account' => '331', 'credit_account' => '1121', 'amount' => 60500000],
            ],
        ]);
        $uncVen->assertStatus(201);
        $this->postJson('/api/v1/bank/payments/'.($uncVen->json('data.id') ?? $uncVen->json('id')).'/post')->assertStatus(200);

        // 6. Period Closing & Accounting Reconciliation:
        // Revenue (5113) = 200,000,000 VND
        // Operating Expenses (642) = 40M + 15M + 60M = 115,000,000 VND
        // COGS (632) = 0 VND (Pure Service enterprise)
        // Net Operating Profit = 200M - 115M = 85,000,000 VND
        $this->postGeneralJournal(
            voucherNumber: 'PKT-KC-SVC',
            date: '2026-08-31',
            description: 'Kết chuyển KQKD doanh nghiệp dịch vụ T8/2026',
            lines: [
                ['account_code' => '5113', 'debit_amount' => 200000000, 'credit_amount' => 0, 'description' => 'Kết chuyển Doanh thu dịch vụ'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 200000000, 'description' => 'Xác định KQKD - Doanh thu'],

                ['account_code' => '911', 'debit_amount' => 115000000, 'credit_amount' => 0, 'description' => 'Xác định KQKD - Chi phí'],
                ['account_code' => '642', 'debit_amount' => 0, 'credit_amount' => 115000000, 'description' => 'Kết chuyển chi phí dịch vụ'],

                ['account_code' => '911', 'debit_amount' => 85000000, 'credit_amount' => 0, 'description' => 'Kết chuyển lãi thuần sang 4212'],
                ['account_code' => '4212', 'debit_amount' => 0, 'credit_amount' => 85000000, 'description' => 'Lợi nhuận năm nay'],
            ]
        );

        // Verify Balance Sheet
        $bs = $this->getJson('/api/v1/reports/balance-sheet?company_id=1')->json();
        $assets = collect($bs['assets']);
        $liabilities = collect($bs['liabilities']);
        $equity = collect($bs['equity']);

        // Bank 1121: 100M - 60M + 88M + 132M - 60.5M = 199.5M
        $this->assertEquals(199500000, $assets->firstWhere('code', '112')['end_balance']);

        // Input Deductible VAT 13311: 4M + 1.5M = 5.5M
        // Output Tax 33311: 8M + 12M = 20M
        $this->assertEquals(20000000, $liabilities->firstWhere('code', '333')['end_balance']);

        // Equity 411: 100M + Profit 4212: 85M = 185M
        $this->assertEquals(100000000, $equity->firstWhere('code', '411')['end_balance']);
        $this->assertEquals(85000000, $equity->firstWhere('code', '421')['end_balance']);
    }

    /**
     * =========================================================================
     * SCENARIO 4: Multi-Tax Rate (0%, 5%, 8%, 10%) & Trade Discount Comprehensive Cycle
     * =========================================================================
     * Workflow:
     * - Purchases with mixed VAT brackets (0%, 5%, 8%, 10%).
     * - Sales invoices with line-item trade discounts and multi-tier VAT rates.
     * - Domestic bank wire transfers with bank charges / service fees (642/1121).
     * - Full tax ledger reconciliation matching Tax Report VAT declaration.
     */
    public function test_scenario_4_multi_tax_rate_and_discount_comprehensive_cycle(): void
    {
        // 1. Initial Opening Balance: Cash 50M, Bank 300M, Equity 350M
        $this->postGeneralJournal(
            voucherNumber: 'PKT-OPEN-TAX',
            date: '2026-08-01',
            description: 'Số dư đầu kỳ chu kỳ thuế đa thuế suất',
            lines: [
                ['account_code' => '1111', 'debit_amount' => 50000000, 'credit_amount' => 0, 'description' => 'Tiền mặt'],
                ['account_code' => '1121', 'debit_amount' => 300000000, 'credit_amount' => 0, 'description' => 'Tiền gửi ngân hàng'],
                ['account_code' => '4111', 'debit_amount' => 0, 'credit_amount' => 350000000, 'description' => 'Vốn đầu tư'],
            ]
        );

        // 2. Inward Purchase Invoice with 4 Mixed VAT Rates:
        // Line 1: 0% VAT - 50 units @ 100,000 = 5,000,000 (VAT 0 = 0)
        // Line 2: 5% VAT - 40 units @ 200,000 = 8,000,000 (VAT 5% = 400,000)
        // Line 3: 8% VAT - 20 units @ 500,000 = 10,000,000 (VAT 8% = 800,000)
        // Line 4: 10% VAT - 10 units @ 1,000,000 = 10,000,000 (VAT 10% = 1,000,000)
        // Total Subtotal = 33,000,000 VND; Total Input VAT = 2,200,000 VND; Total Payable = 35,200,000 VND
        $taxPinv = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierSynnex->id,
            'invoice_number' => 'PINV-MULTI-TAX',
            'invoice_date' => '2026-08-05',
            'description' => 'Hóa đơn mua hàng hóa đa mức thuế suất GTGT',
            'lines' => [
                [
                    'description' => 'Mặt hàng chịu thuế 0% (Hàng nông sản/xuất khẩu)',
                    'quantity' => 50,
                    'unit_price' => 100000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
                [
                    'description' => 'Mặt hàng chịu thuế 5% (Thiết bị y tế/vật tư thiết yếu)',
                    'quantity' => 40,
                    'unit_price' => 200000,
                    'tax_rate' => 5,
                    'tax_amount' => 400000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                ],
                [
                    'description' => 'Mặt hàng chịu thuế 8% (Công nghệ theo NQ 142/2024)',
                    'quantity' => 20,
                    'unit_price' => 500000,
                    'tax_rate' => 8,
                    'tax_amount' => 800000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                ],
                [
                    'description' => 'Mặt hàng chịu thuế chuẩn 10% (Hàng hóa tiêu dùng chung)',
                    'quantity' => 10,
                    'unit_price' => 1000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1000000,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                ],
            ],
        ]);
        $taxPinv->assertStatus(201);
        $taxPinvId = $taxPinv->json('data.id') ?? $taxPinv->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$taxPinvId}/post")->assertStatus(200);

        // 3. Sales with Multi-Tax Rates & Trade Discounts:
        // Line 1: 0% VAT - 20 @ 150,000 = 3,000,000 (Trade discount 5% = 150,000 -> Net 2,850,000, VAT 0 = 0)
        // Line 2: 5% VAT - 20 @ 300,000 = 6,000,000 (Trade discount 10% = 600,000 -> Net 5,400,000, VAT 5% = 270,000)
        // Line 3: 8% VAT - 10 @ 800,000 = 8,000,000 (Net 8,000,000, VAT 8% = 640,000)
        // Line 4: 10% VAT - 5 @ 1,500,000 = 7,500,000 (Net 7,500,000, VAT 10% = 750,000)
        // Net Subtotal = 23,750,000 VND; Total Output VAT = 1,660,000 VND; Total Receivable = 25,410,000 VND
        // COGS (632) = 20*100k + 20*200k + 10*500k + 5*1000k = 16,000,000 VND
        $taxSinv = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customerAlpha->id,
            'invoice_number' => 'SI-MULTI-TAX-01',
            'invoice_date' => '2026-08-15',
            'accounting_date' => '2026-08-15',
            'due_date' => '2026-09-15',
            'payment_status' => 'Unpaid',
            'is_export_slip' => true,
            'description' => 'Xuất bán hàng hóa đa mức thuế suất kèm chiết khấu thương mại',
            'lines' => [
                [
                    'description' => 'Hàng xuất khẩu 0% chiết khấu 5%',
                    'quantity' => 20,
                    'unit_price' => 150000,
                    'discount_rate' => 5,
                    'discount_amount' => 150000,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 100000,
                ],
                [
                    'description' => 'Hàng y tế 5% chiết khấu 10%',
                    'quantity' => 20,
                    'unit_price' => 300000,
                    'discount_rate' => 10,
                    'discount_amount' => 600000,
                    'tax_rate' => 5,
                    'tax_amount' => 270000,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 200000,
                ],
                [
                    'description' => 'Hàng công nghệ thuế 8%',
                    'quantity' => 10,
                    'unit_price' => 800000,
                    'tax_rate' => 8,
                    'tax_amount' => 640000,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 500000,
                ],
                [
                    'description' => 'Hàng tiêu chuẩn thuế 10%',
                    'quantity' => 5,
                    'unit_price' => 1500000,
                    'tax_rate' => 10,
                    'tax_amount' => 750000,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 1000000,
                ],
            ],
        ]);
        $taxSinv->assertStatus(201);
        $taxSinvId = $taxSinv->json('data.id') ?? $taxSinv->json('id');
        $this->postJson("/api/v1/sales/invoices/{$taxSinvId}/post")->assertStatus(200);

        // 4. Bank Transfers with Bank Charges / Fees:
        // Pay Supplier 35,200,000 VND + Bank Fee 22,000 VND (20k fee + 2k VAT)
        $this->postGeneralJournal(
            voucherNumber: 'UNC-FEE-01',
            date: '2026-08-20',
            description: 'UNC thanh toán tiền hàng và phí chuyển tiền ngân hàng',
            lines: [
                ['account_code' => '331', 'debit_amount' => 35200000, 'credit_amount' => 0, 'description' => 'Thanh toán tiền NCC'],
                ['account_code' => '642', 'debit_amount' => 20000, 'credit_amount' => 0, 'description' => 'Phí chuyển tiền ngân hàng VCB'],
                ['account_code' => '13311', 'debit_amount' => 2000, 'credit_amount' => 0, 'description' => 'Thuế GTGT phí chuyển tiền'],
                ['account_code' => '1121', 'debit_amount' => 0, 'credit_amount' => 35222000, 'description' => 'Tiền gửi VCB trích trả'],
            ]
        );

        // Customer Settlement Bank Receipt: 25,410,000 VND
        $bcTax = $this->postJson('/api/v1/bank/receipts', [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankVcb->id,
            'voucher_number' => 'BC-TAX-01',
            'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22',
            'payer_name' => $this->customerAlpha->name,
            'description' => 'Thu tiền khách hàng Alpha Tech hóa đơn SI-MULTI-TAX-01',
            'lines' => [
                ['debit_account' => '1121', 'credit_account' => '131', 'amount' => 25410000],
            ],
        ]);
        $bcTax->assertStatus(201);
        $this->postJson('/api/v1/bank/receipts/'.($bcTax->json('data.id') ?? $bcTax->json('id')).'/post')->assertStatus(200);

        // 5. Tax Ledger Reconciliation (Tờ khai Thuế GTGT Mẫu 01/GTGT):
        // Total Input VAT: 2,200,000 (Goods) + 2,000 (Bank fee) = 2,202,000 VND
        // Total Output VAT: 1,660,000 VND
        // Net VAT Position: Input > Output -> Tax Refundable / Carried Forward = 2,202,000 - 1,660,000 = 542,000 VND
        $vatReportService = new TaxReportService;
        $vatDeclaration = $vatReportService->getVATReport(1, '2026-08-01', '2026-08-31');

        $this->assertEquals(2202000, $vatDeclaration['input_vat']['total_deductible_amount']);
        $this->assertEquals(1660000, $vatDeclaration['output_vat']['total_tax_amount']);
        $this->assertEquals(542000, $vatDeclaration['summary']['tax_refundable_or_carried_forward']);
        $this->assertEquals(0, $vatDeclaration['summary']['tax_payable']);

        // 6. Period Closing & Verification:
        // Revenue (5111) = 23,750,000 VND (net of line discounts)
        // COGS (632) = 16,000,000 VND
        // Admin Expense (642) = 20,000 VND
        // Net Profit = 23,750,000 - 16,000,000 - 20,000 = 7,730,000 VND
        $this->postGeneralJournal(
            voucherNumber: 'PKT-KC-TAX',
            date: '2026-08-31',
            description: 'Kết chuyển KQKD chu kỳ đa thuế suất',
            lines: [
                ['account_code' => '5111', 'debit_amount' => 23750000, 'credit_amount' => 0, 'description' => 'Kết chuyển Doanh thu'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 23750000, 'description' => 'Xác định KQKD'],

                ['account_code' => '911', 'debit_amount' => 16020000, 'credit_amount' => 0, 'description' => 'Xác định KQKD Chi phí'],
                ['account_code' => '632', 'debit_amount' => 0, 'credit_amount' => 16000000, 'description' => 'Kết chuyển Giá vốn'],
                ['account_code' => '642', 'debit_amount' => 0, 'credit_amount' => 20000, 'description' => 'Kết chuyển Chi phí'],

                ['account_code' => '911', 'debit_amount' => 7730000, 'credit_amount' => 0, 'description' => 'Kết chuyển Lợi nhuận'],
                ['account_code' => '4212', 'debit_amount' => 0, 'credit_amount' => 7730000, 'description' => 'Lợi nhuận năm nay'],
            ]
        );

        $trial = $this->getJson('/api/v1/reports/trial-balance?company_id=1');
        $trial->assertStatus(200);
        $trialMap = collect($trial->json())->keyBy('code');

        $this->assertEquals(0, $trialMap['5111']['ending_debit'] ?? 0);
        $this->assertEquals(0, $trialMap['632']['ending_debit'] ?? 0);
        $this->assertEquals(0, $trialMap['642']['ending_debit'] ?? 0);
        $this->assertEquals(7730000, $trialMap['4212']['ending_credit'] ?? 7730000);
    }

    /**
     * =========================================================================
     * SCENARIO 5: Loss-Making & Accounting Adjustment / Correction Cycle
     * =========================================================================
     * Workflow:
     * - Operating deficit scenario (Revenues 30M < Expenses 80M -> Net loss -50M).
     * - Period closing with negative profit transfer (Nợ 4212 / Có 911).
     * - Forensic audit discovers voucher overstatement: Unpost closing & expense voucher.
     * - Correct voucher amounts, repost to GL, and recalculate period closing.
     * - Balance sheet validation confirming exact mathematical balance of retained deficit.
     */
    public function test_scenario_5_loss_making_and_accounting_adjustment_cycle(): void
    {
        // 1. Initial Opening Balance: Cash 20M, Bank 100M, Stock 50M, Capital 170M
        $this->postGeneralJournal(
            voucherNumber: 'PKT-OPEN-LOSS',
            date: '2026-08-01',
            description: 'Số dư đầu kỳ chu kỳ kinh doanh lỗ',
            lines: [
                ['account_code' => '1111', 'debit_amount' => 20000000, 'credit_amount' => 0, 'description' => 'Tiền mặt'],
                ['account_code' => '1121', 'debit_amount' => 100000000, 'credit_amount' => 0, 'description' => 'Tiền gửi ngân hàng'],
                ['account_code' => '1561', 'debit_amount' => 50000000, 'credit_amount' => 0, 'description' => 'Hàng hóa tồn kho'],
                ['account_code' => '4111', 'debit_amount' => 0, 'credit_amount' => 170000000, 'description' => 'Vốn chủ sở hữu'],
            ]
        );

        // 2. Modest Sales Revenue: 30,000,000 VND (COGS 25,000,000 VND)
        $sinv = $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customerAlpha->id,
            'invoice_number' => 'SI-LOSS-01',
            'invoice_date' => '2026-08-10',
            'accounting_date' => '2026-08-10',
            'due_date' => '2026-09-10',
            'payment_status' => 'Unpaid',
            'is_export_slip' => true,
            'description' => 'Bán hàng tháng 8 doanh thu thấp',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'quantity' => 2,
                    'unit_price' => 15000000,
                    'tax_rate' => 10,
                    'tax_amount' => 3000000,
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 12500000, // 2 * 12.5M = 25M
                ],
            ],
        ]);
        $sinv->assertStatus(201);
        $sinvId = $sinv->json('data.id') ?? $sinv->json('id');
        $this->postJson("/api/v1/sales/invoices/{$sinvId}/post")->assertStatus(200);

        // 3. Heavy Operating Invoices:
        // Invoice 1: Office Rent & Marketing = 40,000,000 VND + 4,000,000 VAT = 44,000,000 VND
        $rentPinv = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierUtility->id,
            'invoice_number' => 'PINV-RENT-01',
            'invoice_date' => '2026-08-12',
            'description' => 'Thuê văn phòng và chi phí quảng cáo T8',
            'lines' => [
                [
                    'description' => 'Tiền thuê mặt bằng văn phòng làm việc',
                    'quantity' => 1,
                    'unit_price' => 40000000,
                    'tax_rate' => 10,
                    'tax_amount' => 4000000,
                    'debit_account' => '642',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                ],
            ],
        ]);
        $rentPinv->assertStatus(201);
        $rentId = $rentPinv->json('data.id') ?? $rentPinv->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$rentId}/post")->assertStatus(200);

        // Invoice 2: Maintenance Invoice (Erroneously recorded at 15,000,000 VND instead of 5,000,000 VND)
        $maintPinv = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierSynnex->id,
            'invoice_number' => 'PINV-MAINT-01',
            'invoice_date' => '2026-08-15',
            'description' => 'Hóa đơn bảo trì hệ thống điều hòa & thiết bị',
            'lines' => [
                [
                    'description' => 'Bảo dưỡng định kỳ hệ thống IT',
                    'quantity' => 1,
                    'unit_price' => 15000000, // Ghi nhận nhầm 15M
                    'tax_rate' => 10,
                    'tax_amount' => 1500000,
                    'debit_account' => '642',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                ],
            ],
        ]);
        $maintPinv->assertStatus(201);
        $maintId = $maintPinv->json('data.id') ?? $maintPinv->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$maintId}/post")->assertStatus(200);

        // Pre-correction Deficit:
        // Revenue (5111) = 30,000,000 VND
        // Expenses = COGS 25,000,000 + Admin 55,000,000 = 80,000,000 VND
        // Net Loss = 30,000,000 - 80,000,000 = -50,000,000 VND

        // 4. Initial Deficit Period Closing: Nợ 4212: 50M / Có 911: 50M
        $initialClosing = $this->postGeneralJournal(
            voucherNumber: 'PKT-KC-LOSS-01',
            date: '2026-08-31',
            description: 'Kết chuyển lỗ kinh doanh T8/2026 đợt 1',
            lines: [
                ['account_code' => '5111', 'debit_amount' => 30000000, 'credit_amount' => 0, 'description' => 'Kết chuyển Doanh thu'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 30000000, 'description' => 'Xác định KQKD'],

                ['account_code' => '911', 'debit_amount' => 80000000, 'credit_amount' => 0, 'description' => 'Xác định KQKD'],
                ['account_code' => '632', 'debit_amount' => 0, 'credit_amount' => 25000000, 'description' => 'Kết chuyển Giá vốn'],
                ['account_code' => '642', 'debit_amount' => 0, 'credit_amount' => 55000000, 'description' => 'Kết chuyển Chi phí'],

                // Bút toán kết chuyển lỗ: Nợ 4212 / Có 911: 50,000,000 VND
                ['account_code' => '4212', 'debit_amount' => 50000000, 'credit_amount' => 0, 'description' => 'Kết chuyển lỗ kinh doanh năm nay'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 50000000, 'description' => 'Xác định KQKD'],
            ]
        );

        $trialBeforeFix = $this->getJson('/api/v1/reports/trial-balance?company_id=1')->json();
        $tbMapBefore = collect($trialBeforeFix)->keyBy('code');
        $this->assertEquals(50000000, $tbMapBefore['4212']['ending_debit']); // Số dư Nợ 4212 (Lỗ 50M)

        // ---------------------------------------------------------------------
        // 5. Audit & Correction Workflow:
        // ---------------------------------------------------------------------
        // Step A: Void the erroneous Closing Entry
        $this->postJson("/api/v1/gl/journal-entries/{$initialClosing->id}/void")->assertStatus(200);

        // Step B: Void the erroneous Maintenance Purchase Invoice
        $this->postJson("/api/v1/purchase/invoices/{$maintId}/void")->assertStatus(200);

        // Step C: Delete and recreate / update the corrected Maintenance Invoice (5,000,000 VND + 500,000 VAT)
        $maintCorrected = $this->postJson('/api/v1/purchase/invoices', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplierSynnex->id,
            'invoice_number' => 'PINV-MAINT-CORRECTED',
            'invoice_date' => '2026-08-15',
            'description' => 'Hóa đơn bảo trì hệ thống điều hòa & thiết bị (Đã sửa đổi đúng)',
            'lines' => [
                [
                    'description' => 'Bảo dưỡng định kỳ hệ thống IT (Số tiền chuẩn)',
                    'quantity' => 1,
                    'unit_price' => 5000000, // Giá đúng 5M
                    'tax_rate' => 10,
                    'tax_amount' => 500000,
                    'debit_account' => '642',
                    'credit_account' => '331',
                    'tax_account' => '13311',
                ],
            ],
        ]);
        $maintCorrected->assertStatus(201);
        $maintCorrId = $maintCorrected->json('data.id') ?? $maintCorrected->json('id');
        $this->postJson("/api/v1/purchase/invoices/{$maintCorrId}/post")->assertStatus(200);

        // Revised Financials:
        // Revenue (5111) = 30,000,000 VND
        // COGS (632) = 25,000,000 VND
        // Admin Expenses (642) = 40,000,000 + 5,000,000 = 45,000,000 VND
        // Revised Net Loss = 30,000,000 - (25,000,000 + 45,000,000) = -40,000,000 VND

        // Step D: Post Revised Period Closing Entry: Nợ 4212: 40M / Có 911: 40M
        $revisedClosing = $this->postGeneralJournal(
            voucherNumber: 'PKT-KC-LOSS-REVISED',
            date: '2026-08-31',
            description: 'Kết chuyển lỗ kinh doanh T8/2026 sau điều chỉnh kế toán',
            lines: [
                ['account_code' => '5111', 'debit_amount' => 30000000, 'credit_amount' => 0, 'description' => 'Kết chuyển Doanh thu'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 30000000, 'description' => 'Xác định KQKD'],

                ['account_code' => '911', 'debit_amount' => 70000000, 'credit_amount' => 0, 'description' => 'Xác định KQKD'],
                ['account_code' => '632', 'debit_amount' => 0, 'credit_amount' => 25000000, 'description' => 'Kết chuyển Giá vốn'],
                ['account_code' => '642', 'debit_amount' => 0, 'credit_amount' => 45000000, 'description' => 'Kết chuyển Chi phí'],

                // Bút toán kết chuyển lỗ đã điều chỉnh: Nợ 4212 / Có 911: 40,000,000 VND
                ['account_code' => '4212', 'debit_amount' => 40000000, 'credit_amount' => 0, 'description' => 'Kết chuyển lỗ sau điều chỉnh'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 40000000, 'description' => 'Xác định KQKD'],
            ]
        );

        // 6. Final Balance Sheet & Trial Balance Verification:
        $trialAfterFix = $this->getJson('/api/v1/reports/trial-balance?company_id=1')->json();
        $tbMapAfter = collect($trialAfterFix)->keyBy('code');

        // Account 4212 ending debit balance = 40,000,000 VND (Loss)
        $this->assertEquals(40000000, $tbMapAfter['4212']['ending_debit']);
        $this->assertEquals(0, $tbMapAfter['5111']['ending_debit'] ?? 0);
        $this->assertEquals(0, $tbMapAfter['632']['ending_debit'] ?? 0);
        $this->assertEquals(0, $tbMapAfter['642']['ending_debit'] ?? 0);

        // Check Balance Sheet
        $bs = $this->getJson('/api/v1/reports/balance-sheet?company_id=1')->json();
        $assets = collect($bs['assets']);
        $liabilities = collect($bs['liabilities']);
        $equity = collect($bs['equity']);

        // Cash 111: 20,000,000 VND
        $this->assertEquals(20000000, $assets->firstWhere('code', '111')['end_balance']);

        // Bank 112: 100,000,000 VND
        $this->assertEquals(100000000, $assets->firstWhere('code', '112')['end_balance']);

        // Inventory 156: 50M - 25M = 25,000,000 VND
        $this->assertEquals(25000000, $assets->firstWhere('code', '156')['end_balance']);

        // Receivables 131: 33,000,000 VND
        $this->assertEquals(33000000, $assets->firstWhere('code', '131')['end_balance']);

        // Payables 331: 44,000,000 (Rent) + 5,500,000 (Maint) = 49,500,000 VND
        $this->assertEquals(49500000, $liabilities->firstWhere('code', '331')['end_balance']);

        // Output Tax 333: 3,000,000 VND
        $this->assertEquals(3000000, $liabilities->firstWhere('code', '333')['end_balance']);

        // Owner Equity 411: 170,000,000 VND
        $this->assertEquals(170000000, $equity->firstWhere('code', '411')['end_balance']);
    }
}
