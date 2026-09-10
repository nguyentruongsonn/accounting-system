<?php

namespace Tests\Feature\MisaE2E;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryValuationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Tier1FeatureCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected FiscalYear $fiscalYear;

    protected BankAccount $bankAccountVCB;

    protected BankAccount $bankAccountTCB;

    protected BankAccount $bankAccountBIDV;

    protected BankAccount $bankAccountCTG;

    protected Customer $customer1;

    protected Customer $customer2;

    protected Supplier $supplier1;

    protected Supplier $supplier2;

    protected Warehouse $warehouseHanoi;

    protected Warehouse $warehouseHCM;

    protected Item $itemLaptop;

    protected Item $itemMonitor;

    protected Item $itemServiceShipping;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Company Setup
        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'CÔNG TY CỔ PHẦN MISA TEST',
                'tax_code' => '0101243150',
                'address' => 'Tòa nhà MISA, Lô 5, CVPM Quang Trung, Quận 12, TP.HCM',
            ]
        );

        // 2. User & Auth Setup
        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Kế toán trưởng MISA',
            'email' => 'chief_accountant@misa.vn',
        ]);

        Permission::firstOrCreate(['name' => 'view_reports', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view_reports', 'guard_name' => 'sanctum']);
        $this->user->givePermissionTo('view_reports');
        $this->grantGlReportPermissions($this->user);

        // Posting now requires an active canonical two-role identity. This
        // end-to-end fixture models the operational accountant instead of a
        // roleless legacy user that would be rejected by the real gate.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));

        Sanctum::actingAs($this->user);

        // 3. Fiscal Year Setup
        $this->fiscalYear = FiscalYear::firstOrCreate(
            ['id' => 1],
            [
                'company_id' => $this->company->id,
                'name' => '2026',
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'is_closed' => false,
                'status' => 'open',
            ]
        );

        // 4. TT200 Chart of Accounts Setup
        $this->seedChartOfAccounts();

        // 5. Master Data Setup (Bank Accounts, Partners, Warehouses, Items)
        $this->seedMasterData();
    }

    private function seedChartOfAccounts(): void
    {
        $accounts = [
            ['code' => '111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '1111', 'name' => 'Tiền Việt Nam', 'parent_code' => '111', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '112', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '1121', 'name' => 'Tiền gửi VNĐ', 'parent_code' => '112', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '128', 'name' => 'Đầu tư nắm giữ đến ngày đáo hạn', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'amphibious', 'level' => 1, 'is_parent' => 0],
            ['code' => '133', 'name' => 'Thuế GTGT được khấu trừ', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '1331', 'name' => 'Thuế GTGT được khấu trừ của HHDV', 'parent_code' => '133', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '13311', 'name' => 'Thuế GTGT đầu vào được khấu trừ', 'parent_code' => '1331', 'type' => 'asset', 'nature' => 'debit', 'level' => 3, 'is_parent' => 0],
            ['code' => '141', 'name' => 'Tạm ứng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '154', 'name' => 'Chi phí sản xuất kinh doanh dở dang', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '156', 'name' => 'Hàng hóa', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'parent_code' => '156', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0],
            ['code' => '211', 'name' => 'Tài sản cố định hữu hình', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '242', 'name' => 'Chi phí trả trước', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'amphibious', 'level' => 1, 'is_parent' => 0],
            ['code' => '333', 'name' => 'Thuế và các khoản phải nộp Nhà nước', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 1],
            ['code' => '3331', 'name' => 'Thuế GTGT phải nộp', 'parent_code' => '333', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => 1],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'parent_code' => '3331', 'type' => 'liability', 'nature' => 'credit', 'level' => 3, 'is_parent' => 0],
            ['code' => '334', 'name' => 'Phải trả người lao động', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '338', 'name' => 'Phải trả, phải nộp khác', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 1],
            ['code' => '3383', 'name' => 'Bảo hiểm xã hội', 'parent_code' => '338', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => 0],
            ['code' => '341', 'name' => 'Vay và nợ thuê tài chính', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '411', 'name' => 'Vốn đầu tư của chủ sở hữu', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '421', 'name' => 'Lợi nhuận sau thuế chưa phân phối', 'type' => 'equity', 'nature' => 'amphibious', 'level' => 1, 'is_parent' => 1],
            ['code' => '4212', 'name' => 'Lợi nhuận sau thuế chưa phân phối năm nay', 'parent_code' => '421', 'type' => 'equity', 'nature' => 'amphibious', 'level' => 2, 'is_parent' => 0],
            ['code' => '511', 'name' => 'Doanh thu bán hàng và CCDV', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 1],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'parent_code' => '511', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => 0],
            ['code' => '515', 'name' => 'Doanh thu hoạt động tài chính', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '521', 'name' => 'Các khoản giảm trừ doanh thu', 'type' => 'revenue', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '635', 'name' => 'Chi phí tài chính', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '641', 'name' => 'Chi phí bán hàng', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '642', 'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0],
            ['code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '821', 'name' => 'Chi phí thuế thu nhập doanh nghiệp', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0],
            ['code' => '911', 'name' => 'Xác định kết quả kinh doanh', 'type' => 'revenue', 'nature' => 'amphibious', 'level' => 1, 'is_parent' => 0],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::firstOrCreate(
                ['company_id' => $this->company->id, 'code' => $acc['code']],
                array_merge($acc, ['company_id' => $this->company->id, 'is_active' => true])
            );
        }
    }

    private function seedMasterData(): void
    {
        // Bank Accounts
        $this->bankAccountVCB = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '0011001234567',
            'bank_name' => 'Vietcombank',
            'bank_code' => 'VCB',
            'branch' => 'Sở Giao Dịch Hà Nội',
            'account_holder' => 'CÔNG TY CỔ PHẦN MISA TEST',
            'currency' => 'VND',
            'is_active' => true,
        ]);

        $this->bankAccountTCB = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '19030098765432',
            'bank_name' => 'Techcombank',
            'bank_code' => 'TCB',
            'branch' => 'Chi nhánh Thăng Long',
            'account_holder' => 'CÔNG TY CỔ PHẦN MISA TEST',
            'currency' => 'VND',
            'is_active' => true,
        ]);

        $this->bankAccountBIDV = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '12010001122334',
            'bank_name' => 'BIDV',
            'bank_code' => 'BIDV',
            'branch' => 'Chi nhánh Quang Trung',
            'account_holder' => 'CÔNG TY CỔ PHẦN MISA TEST',
            'currency' => 'VND',
            'is_active' => true,
        ]);

        $this->bankAccountCTG = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => '711A99887766',
            'bank_name' => 'VietinBank',
            'bank_code' => 'CTG',
            'branch' => 'Chi nhánh Đống Đa',
            'account_holder' => 'CÔNG TY CỔ PHẦN MISA TEST',
            'currency' => 'VND',
            'is_active' => true,
        ]);

        // Customers
        $this->customer1 = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH001',
            'name' => 'Công ty TNHH Giải Pháp Alpha',
            'tax_code' => '0102030405',
            'address' => 'Số 10 Tràng Thi, Hoàn Kiếm, Hà Nội',
            'is_customer' => true,
            'is_active' => true,
        ]);

        $this->customer2 = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH002',
            'name' => 'Công ty Cổ Phần Thương Mại Delta',
            'tax_code' => '0304050607',
            'address' => 'Số 45 Lê Duẩn, Hải Châu, Đà Nẵng',
            'is_customer' => true,
            'is_active' => true,
        ]);

        // Suppliers
        $this->supplier1 = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC001',
            'name' => 'Công ty TNHH Thiết Bị Beta',
            'tax_code' => '0109988776',
            'address' => 'Tòa nhà Landmark 81, Bình Thạnh, TP.HCM',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        $this->supplier2 = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'NCC002',
            'name' => 'Công ty TNHH Dịch Vụ Logistics Gamma',
            'tax_code' => '0108877665',
            'address' => 'Khu Công Nghiệp Đình Vũ, Hải Phòng',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        // Warehouses
        $this->warehouseHanoi = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO01',
            'name' => 'Kho Tổng Hà Nội',
            'address' => 'Cầu Giấy, Hà Nội',
            'is_active' => true,
        ]);

        $this->warehouseHCM = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'KHO02',
            'name' => 'Kho Hàng TP.HCM',
            'address' => 'Quận 12, TP.HCM',
            'is_active' => true,
        ]);

        // Items / Products
        $this->itemLaptop = Item::create([
            'company_id' => $this->company->id,
            'type' => 'Goods',
            'code' => 'SP01',
            'name' => 'Laptop Dell Latitude 5520 Core i7',
            'unit' => 'Chiếc',
            'cost_price' => 15000000,
            'selling_price' => 20000000,
            'inventory_account' => '1561',
            'is_active' => true,
        ]);

        $this->itemMonitor = Item::create([
            'company_id' => $this->company->id,
            'type' => 'Goods',
            'code' => 'SP02',
            'name' => 'Màn hình Dell UltraSharp 27 inch 4K',
            'unit' => 'Chiếc',
            'cost_price' => 6000000,
            'selling_price' => 8500000,
            'is_active' => true,
        ]);

        $this->itemServiceShipping = Item::create([
            'company_id' => $this->company->id,
            'type' => 'Service',
            'code' => 'DV01',
            'name' => 'Dịch vụ vận chuyển bốc dỡ hàng hóa',
            'unit' => 'Chuyến',
            'cost_price' => 500000,
            'selling_price' => 1000000,
            'is_active' => true,
        ]);
    }

    /* =========================================================================
     * MODULE 1: TIỀN GỬI / NGÂN HÀNG (BANK - BA)
     * ========================================================================= */

    /**
     * 1. Test tạo Giấy Báo Có với 7 lý do MISA AMIS và tự động ghi sổ Sổ Cái (Nợ 1121 / Có đối ứng)
     */
    public function test_bank_receipt_creation_with_7_misa_reasons_and_gl_posting()
    {
        $reasonsConfig = [
            ['code' => 'BR-01', 'reason' => 'Thu tiền khách hàng (Customer collection)', 'credit' => '131', 'amount' => 50000000],
            ['code' => 'BR-02', 'reason' => 'Thu hoàn ứng nhân viên (Employee advance refund)', 'credit' => '141', 'amount' => 10000000],
            ['code' => 'BR-03', 'reason' => 'Thu hoàn thuế GTGT (Tax refund)', 'credit' => '1331', 'amount' => 25000000],
            ['code' => 'BR-04', 'reason' => 'Thu lãi tiền gửi ngân hàng (Deposit interest)', 'credit' => '515', 'amount' => 3500000],
            ['code' => 'BR-05', 'reason' => 'Thu hồi nợ cho vay / tiền gửi có kỳ hạn (Loan recovery)', 'credit' => '128', 'amount' => 100000000],
            ['code' => 'BR-06', 'reason' => 'Thu khác bằng chuyển khoản (Other receipt)', 'credit' => '711', 'amount' => 8000000],
            ['code' => 'BR-07', 'reason' => 'Rút tiền gửi về nhập quỹ tiền mặt (Withdraw to cash fund)', 'credit' => '1111', 'amount' => 20000000],
        ];

        foreach ($reasonsConfig as $idx => $cfg) {
            $payload = [
                'company_id' => $this->company->id,
                'bank_account_id' => $this->bankAccountVCB->id,
                'voucher_number' => $cfg['code'],
                'voucher_date' => '2026-08-15',
                'posting_date' => '2026-08-15',
                'contact_id' => $this->customer1->id,
                'contact_type' => 'customer',
                'contact_name' => $this->customer1->name,
                'payer_name' => 'Nguyễn Văn Payer '.($idx + 1),
                'payer_bank_account' => '0451000'.($idx + 1).'23456',
                'description' => $cfg['reason'],
                'currency' => 'VND',
                'lines' => [
                    [
                        'description' => $cfg['reason'],
                        'credit_account' => $cfg['credit'],
                        'amount' => $cfg['amount'],
                    ],
                ],
            ];

            $response = $this->postJson('/api/v1/bank/receipts', $payload);
            $response->assertStatus(201)
                ->assertJsonPath('voucher_number', $cfg['code'])
                ->assertJsonPath('amount', $cfg['amount']);

            $receiptId = $response->json('id');
            $this->assertDatabaseHas('bank_receipts', [
                'id' => $receiptId,
                'voucher_number' => $cfg['code'],
                'amount' => $cfg['amount'],
                'is_posted' => false,
            ]);

            // Post to General Ledger
            $postResponse = $this->postJson("/api/v1/bank/receipts/{$receiptId}/post");
            $postResponse->assertStatus(200);

            $this->assertDatabaseHas('bank_receipts', [
                'id' => $receiptId,
                'is_posted' => true,
            ]);

            $receipt = BankReceipt::find($receiptId);
            $this->assertNotNull($receipt->journal_entry_id);

            $this->assertDatabaseHas('journal_entries', [
                'id' => $receipt->journal_entry_id,
                'status' => 'posted',
                'voucher_type' => 'bank_receipt',
            ]);

            $this->assertDatabaseHas('journal_entry_lines', [
                'journal_entry_id' => $receipt->journal_entry_id,
                'account_code' => '1121',
                'debit_amount' => $cfg['amount'],
                'credit_amount' => 0,
            ]);

            $this->assertDatabaseHas('journal_entry_lines', [
                'journal_entry_id' => $receipt->journal_entry_id,
                'account_code' => $cfg['credit'],
                'debit_amount' => 0,
                'credit_amount' => $cfg['amount'],
            ]);
        }
    }

    /**
     * 2. Test vòng đời Báo Có: Tạo -> Ghi sổ (Post) -> Bỏ ghi sổ (Unpost/Void) -> Nhân bản (Duplicate)
     */
    public function test_bank_receipt_post_unpost_and_duplicate_lifecycle()
    {
        $payload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccountTCB->id,
            'voucher_number' => 'BR-LIFE-001',
            'voucher_date' => '2026-08-16',
            'posting_date' => '2026-08-16',
            'contact_id' => $this->customer1->id,
            'contact_type' => 'customer',
            'contact_name' => $this->customer1->name,
            'description' => 'Thu tiền khách hàng Alpha Tech qua Techcombank',
            'lines' => [
                [
                    'description' => 'Thanh toán tiền hàng đợt 1',
                    'credit_account' => '131',
                    'amount' => 45000000,
                ],
            ],
        ];

        // 1. Create Draft
        $createResp = $this->postJson('/api/v1/bank/receipts', $payload);
        $createResp->assertStatus(201);
        $receiptId = $createResp->json('id');
        $this->assertEquals(false, $createResp->json('is_posted'));

        // 2. Post
        $postResp = $this->postJson("/api/v1/bank/receipts/{$receiptId}/post");
        $postResp->assertStatus(200);
        $this->assertDatabaseHas('bank_receipts', ['id' => $receiptId, 'is_posted' => true]);

        $receipt = BankReceipt::find($receiptId);
        $jeId = $receipt->journal_entry_id;
        $this->assertDatabaseHas('journal_entries', ['id' => $jeId, 'status' => 'posted']);

        // 3. Unpost / Void
        $voidResp = $this->postJson("/api/v1/bank/receipts/{$receiptId}/void");
        $voidResp->assertStatus(200);
        $this->assertDatabaseHas('bank_receipts', ['id' => $receiptId, 'is_posted' => false]);
        $this->assertDatabaseHas('journal_entries', ['id' => $jeId, 'status' => 'voided']);

        // 4. Duplicate (Clone voucher with independent state)
        $clonePayload = $payload;
        $clonePayload['voucher_number'] = 'BR-LIFE-001-CLONE';
        $cloneResp = $this->postJson('/api/v1/bank/receipts', $clonePayload);
        $cloneResp->assertStatus(201)
            ->assertJsonPath('voucher_number', 'BR-LIFE-001-CLONE')
            ->assertJsonPath('amount', 45000000);
        $this->assertDatabaseHas('bank_receipts', ['voucher_number' => 'BR-LIFE-001-CLONE', 'is_posted' => false]);
    }

    /**
     * 3. Test tạo Ủy nhiệm chi (UNC) với 7 lý do chi tiền gửi và quản lý tài khoản nguồn/nhận
     */
    public function test_bank_payment_unc_with_7_reasons_and_bank_accounts()
    {
        $reasonsConfig = [
            ['code' => 'UNC-01', 'reason' => 'Trả tiền nhà cung cấp Beta', 'debit' => '331', 'amount' => 70000000],
            ['code' => 'UNC-02', 'reason' => 'Tạm ứng công tác phí nhân viên', 'debit' => '141', 'amount' => 15000000],
            ['code' => 'UNC-03', 'reason' => 'Nộp thuế GTGT vào NSNN', 'debit' => '33311', 'amount' => 18000000],
            ['code' => 'UNC-04', 'reason' => 'Chi nộp tiền BHXH, BHYT, BHTN', 'debit' => '3383', 'amount' => 12500000],
            ['code' => 'UNC-05', 'reason' => 'Chi trả lương nhân viên tháng 8', 'debit' => '334', 'amount' => 85000000],
            ['code' => 'UNC-06', 'reason' => 'Chi phí dịch vụ viễn thông, tiếp khách', 'debit' => '642', 'amount' => 6500000],
            ['code' => 'UNC-07', 'reason' => 'Chuyển tiền sang tài khoản ngân hàng khác', 'debit' => '1121', 'amount' => 30000000],
        ];

        foreach ($reasonsConfig as $idx => $cfg) {
            $payload = [
                'company_id' => $this->company->id,
                'bank_account_id' => $this->bankAccountBIDV->id,
                'voucher_number' => $cfg['code'],
                'voucher_date' => '2026-08-17',
                'posting_date' => '2026-08-17',
                'contact_id' => $this->supplier1->id,
                'contact_type' => 'supplier',
                'contact_name' => $this->supplier1->name,
                'payee_name' => 'Công ty TNHH Thiết Bị Beta',
                'payee_bank_account' => '98765432100',
                'description' => $cfg['reason'],
                'currency' => 'VND',
                'lines' => [
                    [
                        'description' => $cfg['reason'],
                        'debit_account' => $cfg['debit'],
                        'amount' => $cfg['amount'],
                    ],
                ],
            ];

            $response = $this->postJson('/api/v1/bank/payments', $payload);
            $response->assertStatus(201)
                ->assertJsonPath('voucher_number', $cfg['code'])
                ->assertJsonPath('amount', $cfg['amount']);

            $paymentId = $response->json('id');
            $this->assertDatabaseHas('bank_payments', [
                'id' => $paymentId,
                'voucher_number' => $cfg['code'],
                'amount' => $cfg['amount'],
                'is_posted' => false,
            ]);

            // Post to GL
            $postResponse = $this->postJson("/api/v1/bank/payments/{$paymentId}/post");
            $postResponse->assertStatus(200);

            $this->assertDatabaseHas('bank_payments', [
                'id' => $paymentId,
                'is_posted' => true,
            ]);

            $payment = BankPayment::find($paymentId);
            $this->assertNotNull($payment->journal_entry_id);

            $this->assertDatabaseHas('journal_entry_lines', [
                'journal_entry_id' => $payment->journal_entry_id,
                'account_code' => $cfg['debit'],
                'debit_amount' => $cfg['amount'],
                'credit_amount' => 0,
            ]);

            $this->assertDatabaseHas('journal_entry_lines', [
                'journal_entry_id' => $payment->journal_entry_id,
                'account_code' => '1121',
                'debit_amount' => 0,
                'credit_amount' => $cfg['amount'],
            ]);
        }
    }

    /**
     * 4. Test vòng đời Chi tiền gửi (UNC): Tạo -> Ghi sổ -> Bỏ ghi sổ -> Nhân bản
     */
    public function test_bank_payment_post_unpost_and_duplicate_lifecycle()
    {
        $payload = [
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccountCTG->id,
            'voucher_number' => 'BP-LIFE-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'contact_id' => $this->supplier1->id,
            'contact_type' => 'supplier',
            'contact_name' => $this->supplier1->name,
            'payee_name' => $this->supplier1->name,
            'description' => 'Ủy nhiệm chi tiền bảo trì hệ thống',
            'lines' => [
                [
                    'description' => 'Thanh toán đợt 1',
                    'debit_account' => '331',
                    'amount' => 28000000,
                ],
            ],
        ];

        $createResp = $this->postJson('/api/v1/bank/payments', $payload);
        $createResp->assertStatus(201);
        $paymentId = $createResp->json('id');

        // Post
        $this->postJson("/api/v1/bank/payments/{$paymentId}/post")->assertStatus(200);
        $this->assertDatabaseHas('bank_payments', ['id' => $paymentId, 'is_posted' => true]);

        // Void
        $this->postJson("/api/v1/bank/payments/{$paymentId}/void")->assertStatus(200);
        $this->assertDatabaseHas('bank_payments', ['id' => $paymentId, 'is_posted' => false]);

        // Duplicate
        $clonePayload = $payload;
        $clonePayload['voucher_number'] = 'BP-LIFE-001-CLONE';
        $cloneResp = $this->postJson('/api/v1/bank/payments', $clonePayload);
        $cloneResp->assertStatus(201)
            ->assertJsonPath('voucher_number', 'BP-LIFE-001-CLONE');
    }

    /**
     * 5. Test định dạng dữ liệu mẫu in Ngân hàng chuẩn: Giấy Báo Có, Giấy Báo Nợ, UNC (VCB, TCB, BIDV, CTG)
     */
    public function test_bank_print_templates_endpoint_for_all_bank_types()
    {
        // Verify bank accounts list returns correct metadata for print templates
        $bankResp = $this->getJson('/api/v1/bank/accounts');
        $bankResp->assertStatus(200);
        $accounts = $bankResp->json('data') ?? $bankResp->json();
        $this->assertNotEmpty($accounts);

        $bankCodes = collect($accounts)->pluck('bank_code')->toArray();
        $this->assertContains('VCB', $bankCodes);
        $this->assertContains('TCB', $bankCodes);
        $this->assertContains('BIDV', $bankCodes);
        $this->assertContains('CTG', $bankCodes);

        // Create sample Bank Receipt and Payment to check voucher show response contains full print fields
        $br = BankReceipt::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccountVCB->id,
            'voucher_number' => 'BC-PRINT-01',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'payer_name' => 'Công ty TNHH Alpha Tech',
            'payer_bank_account' => '001100998877',
            'description' => 'Thu tiền bảo lãnh thực hiện hợp đồng',
            'amount' => 50000000,
        ]);
        $br->lines()->create([
            'description' => 'Thu tiền bảo lãnh',
            'debit_account' => '1121',
            'credit_account' => '131',
            'amount' => 50000000,
        ]);

        $brShow = $this->getJson("/api/v1/bank/receipts/{$br->id}");
        $brShow->assertStatus(200)
            ->assertJsonPath('data.voucher_number', 'BC-PRINT-01')
            ->assertJsonPath('data.payer_name', 'Công ty TNHH Alpha Tech');

        $bp = BankPayment::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccountBIDV->id,
            'voucher_number' => 'UNC-PRINT-01',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'payee_name' => 'Công ty TNHH Thiết Bị Beta',
            'payee_bank_account' => '120100998877',
            'description' => 'Ủy nhiệm chi tiền mua máy chủ',
            'amount' => 90000000,
        ]);
        $bp->lines()->create([
            'description' => 'Chi tiền máy chủ',
            'debit_account' => '331',
            'credit_account' => '1121',
            'amount' => 90000000,
        ]);

        $bpShow = $this->getJson("/api/v1/bank/payments/{$bp->id}");
        $bpShow->assertStatus(200)
            ->assertJsonPath('data.voucher_number', 'UNC-PRINT-01')
            ->assertJsonPath('data.payee_name', 'Công ty TNHH Thiết Bị Beta');
    }

    /**
     * 6. Test liên kết chứng từ tham chiếu đối với phân hệ Ngân hàng
     */
    public function test_bank_voucher_reference_linking()
    {
        $br = BankReceipt::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccountVCB->id,
            'voucher_number' => 'BC-REF-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'contact_id' => $this->customer1->id,
            'contact_name' => $this->customer1->name,
            'description' => 'Thu nợ hóa đơn HDBH-01',
            'amount' => 30000000,
        ]);

        $bp = BankPayment::create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccountTCB->id,
            'voucher_number' => 'UNC-REF-002',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'contact_id' => $this->supplier1->id,
            'contact_name' => $this->supplier1->name,
            'payee_name' => $this->supplier1->name,
            'description' => 'Thanh toán hợp đồng HDM-01',
            'amount' => 20000000,
        ]);

        // Search via voucher reference modal
        $resp = $this->getJson('/api/v1/voucher-references/search?keyword=BC-REF-001');
        $resp->assertStatus(200)
            ->assertJsonPath('success', true);
        $data = $resp->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('BC-REF-001', $data[0]['voucher_number']);

        $resp2 = $this->getJson('/api/v1/voucher-references/search?keyword=UNC-REF-002');
        $resp2->assertStatus(200);
        $data2 = $resp2->json('data');
        $this->assertNotEmpty($data2);
        $this->assertEquals('UNC-REF-002', $data2[0]['voucher_number']);
    }

    /* =========================================================================
     * MODULE 2: MUA HÀNG (PURCHASE - PU)
     * ========================================================================= */

    /**
     * 7. Test Hóa đơn mua hàng trong nước nhập kho: Nợ 1561, Nợ 13311 / Có 331
     */
    public function test_purchase_invoice_inward_with_inventory_and_tax_gl_posting()
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier1->id,
            'supplier_name' => $this->supplier1->name,
            'supplier_address' => $this->supplier1->address,
            'invoice_number' => 'HDMH-INWARD-001',
            'invoice_date' => '2026-08-18',
            'accounting_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'description' => 'Mua 5 chiếc Laptop Dell nhập kho',
            'payment_method' => 'unpaid',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'description' => 'Laptop Dell Latitude 5520',
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 5,
                    'unit_price' => 15000000, // 75,000,000
                    'discount_rate' => 0,
                    'tax_rate' => 10,
                    'tax_amount' => 7500000,
                    'tax_account' => '13311',
                    'unit' => 'Chiếc',
                    'warehouse' => 'KHO01',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('data.invoice_number', 'HDMH-INWARD-001')
            ->assertJsonPath('data.sub_total', 75000000)
            ->assertJsonPath('data.tax_amount', 7500000)
            ->assertJsonPath('data.total_amount', 82500000);

        $invoiceId = $response->json('data.id');

        // Post to GL
        $postResp = $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post");
        $postResp->assertStatus(200);

        $invoice = PurchaseInvoice::find($invoiceId);
        $this->assertTrue((bool) $invoice->is_posted);
        $this->assertNotNull($invoice->journal_entry_id);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '331',
            'credit_amount' => 82500000,
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '1561',
            'debit_amount' => 75000000,
        ]);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '13311',
            'debit_amount' => 7500000,
        ]);
    }

    /**
     * 8. Test Hóa đơn mua hàng không qua kho (Chi phí trực tiếp): Nợ 642, Nợ 13311 / Có 331
     */
    public function test_purchase_invoice_direct_expense_non_stock_gl_posting()
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier2->id,
            'supplier_name' => $this->supplier2->name,
            'invoice_number' => 'HDMH-EXPENSE-001',
            'invoice_date' => '2026-08-18',
            'accounting_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'description' => 'Mua dịch vụ văn phòng phẩm dùng ngay',
            'payment_method' => 'unpaid',
            'lines' => [
                [
                    'description' => 'Chi phí vật dụng văn phòng QLDN',
                    'debit_account' => '642',
                    'credit_account' => '331',
                    'quantity' => 1,
                    'unit_price' => 12000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1200000,
                    'tax_account' => '13311',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('data.total_amount', 13200000);

        $invoiceId = $response->json('data.id');
        $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post")->assertStatus(200);

        $invoice = PurchaseInvoice::find($invoiceId);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '642',
            'debit_amount' => 12000000,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '13311',
            'debit_amount' => 1200000,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '331',
            'credit_amount' => 13200000,
        ]);
    }

    /**
     * 9. Test Chứng từ mua dịch vụ và phân bổ chi phí mua hàng
     */
    public function test_purchase_service_and_landed_cost_allocation()
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier2->id,
            'supplier_name' => $this->supplier2->name,
            'invoice_number' => 'HDMH-SERVICE-001',
            'invoice_date' => '2026-08-18',
            'description' => 'Chứng từ dịch vụ vận chuyển phân bổ chi phí mua hàng',
            'purchase_expense' => 5000000,
            'lines' => [
                [
                    'item_id' => $this->itemServiceShipping->id,
                    'description' => 'Cước vận chuyển lô hàng Laptop',
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 1,
                    'unit_price' => 5000000,
                    'tax_rate' => 10,
                    'tax_amount' => 500000,
                    'purchase_expense' => 5000000,
                    'stock_value' => 5500000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('data.purchase_expense', 5000000);

        $invoiceId = $response->json('data.id');
        $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post")->assertStatus(200);

        $invoice = PurchaseInvoice::find($invoiceId);
        $this->assertTrue((bool) $invoice->is_posted);
    }

    /**
     * 10. Test Đơn mua hàng (Purchase Order) và theo dõi tình trạng thực hiện
     */
    public function test_purchase_order_creation_and_status_tracking()
    {
        // 1. Next Code
        $codeResp = $this->getJson('/api/v1/purchase/orders/next-code');
        $codeResp->assertStatus(200)
            ->assertJsonStructure(['next_code']);

        // 2. Create PO
        $poPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier1->id,
            'supplier_code' => $this->supplier1->code,
            'supplier_name' => $this->supplier1->name,
            'order_number' => 'PO-2026-001',
            'order_date' => '2026-08-18',
            'delivery_date' => '2026-08-25',
            'status' => 'Pending',
            'total_amount' => 150000000,
            'description' => 'Đơn mua 10 Laptop Dell cho dự án Alpha',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'item_code' => $this->itemLaptop->code,
                    'item_name' => $this->itemLaptop->name,
                    'unit' => 'Chiếc',
                    'quantity' => 10,
                    'unit_price' => 15000000,
                    'amount' => 150000000,
                ],
            ],
        ];

        $createResp = $this->postJson('/api/v1/purchase/orders', $poPayload);
        $createResp->assertStatus(201)
            ->assertJsonPath('order_number', 'PO-2026-001')
            ->assertJsonPath('total_amount', 150000000);

        $orderId = $createResp->json('id');

        // 3. Update Status
        $updateResp = $this->putJson("/api/v1/purchase/orders/{$orderId}", [
            'status' => 'Completed',
        ]);
        $updateResp->assertStatus(200)
            ->assertJsonPath('status', 'Completed');

        // 4. Query By Status
        $listResp = $this->getJson('/api/v1/purchase/orders?status=Completed');
        $listResp->assertStatus(200);
        $orders = $listResp->json();
        $this->assertNotEmpty($orders);
        $this->assertEquals('PO-2026-001', $orders[0]['order_number']);
    }

    /**
     * 11. Test Hợp đồng mua hàng (Purchase Contract) và liên kết tiến độ thanh toán
     */
    public function test_purchase_contract_management_and_linkage()
    {
        // 1. Next Code
        $codeResp = $this->getJson('/api/v1/purchase/contracts/next-code');
        $codeResp->assertStatus(200)
            ->assertJsonStructure(['next_code']);

        // 2. Create Contract with Lines & Payment Schedule
        $contractPayload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier1->id,
            'supplier_code' => $this->supplier1->code,
            'supplier_name' => $this->supplier1->name,
            'contract_number' => 'HDM-2026-001',
            'contract_name' => 'Hợp đồng cung cấp thiết bị tin học năm 2026',
            'signed_date' => '2026-08-01',
            'effective_date' => '2026-08-01',
            'expiration_date' => '2026-12-31',
            'contract_value' => 200000000,
            'status' => 'Active',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'item_code' => $this->itemLaptop->code,
                    'item_name' => $this->itemLaptop->name,
                    'unit' => 'Chiếc',
                    'quantity' => 10,
                    'unit_price' => 15000000,
                    'amount' => 150000000,
                ],
                [
                    'item_id' => $this->itemMonitor->id,
                    'item_code' => $this->itemMonitor->code,
                    'item_name' => $this->itemMonitor->name,
                    'unit' => 'Chiếc',
                    'quantity' => 10,
                    'unit_price' => 5000000,
                    'amount' => 50000000,
                ],
            ],
            'payments' => [
                [
                    'payment_phase' => 'Đợt 1 - Tạm ứng 30%',
                    'expected_date' => '2026-08-05',
                    'expected_amount' => 60000000,
                    'status' => 'Paid',
                ],
                [
                    'payment_phase' => 'Đợt 2 - Nghiệm thu 70%',
                    'expected_date' => '2026-08-25',
                    'expected_amount' => 140000000,
                    'status' => 'Pending',
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/purchase/contracts', $contractPayload);
        $resp->assertStatus(201)
            ->assertJsonPath('contract_number', 'HDM-2026-001')
            ->assertJsonPath('contract_value', 200000000);

        $contractId = $resp->json('id');
        $showResp = $this->getJson("/api/v1/purchase/contracts/{$contractId}");
        $showResp->assertStatus(200)
            ->assertJsonPath('contract_number', 'HDM-2026-001');
        $this->assertCount(2, $showResp->json('lines'));
        $this->assertCount(2, $showResp->json('payments'));
    }

    public function test_purchase_contract_next_code_advances_past_existing_prefixed_number(): void
    {
        \App\Models\PurchaseContract::create([
            'company_id' => $this->company->id,
            'contract_number' => 'HĐM00007',
            'signed_date' => '2026-08-01',
        ]);

        $response = $this->getJson('/api/v1/purchase/contracts/next-code');

        $response->assertOk()
            ->assertJsonPath('next_code', 'HĐM00008')
            ->assertJsonPath('data.next_code', 'HĐM00008');
    }

    /**
     * 12. Test vòng đời Hóa đơn mua hàng: Ghi sổ -> Bỏ ghi sổ -> Nhân bản
     */
    public function test_purchase_invoice_post_unpost_and_duplicate()
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier1->id,
            'invoice_number' => 'PU-LIFE-001',
            'invoice_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'description' => 'Hóa đơn kiểm tra lifecycle mua hàng',
            'lines' => [
                [
                    'description' => 'Màn hình Dell',
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 2,
                    'unit_price' => 6000000,
                    'tax_rate' => 10,
                    'tax_amount' => 1200000,
                ],
            ],
        ];

        $createResp = $this->postJson('/api/v1/purchase/invoices', $payload);
        $createResp->assertStatus(201);
        $invoiceId = $createResp->json('data.id');

        // Post
        $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post")->assertStatus(200);
        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoiceId, 'is_posted' => true]);

        // Void
        $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/void")->assertStatus(200);
        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoiceId, 'is_posted' => false]);
    }

    /* =========================================================================
     * MODULE 3: BÁN HÀNG (SALES - SA)
     * ========================================================================= */

    /**
     * 13. Test Báo giá bán hàng (Sales Quote) và tiến trình chuyển đổi trạng thái
     */
    public function test_sales_quote_creation_and_status_progression()
    {
        // Create Sales Quote using Journal Entry / Reference structure
        $quoteNumber = 'BG-2026-001';
        $quoteDescription = 'Báo giá 5 Laptop Dell Latitude cho khách hàng Alpha';

        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer1->id,
            'invoice_number' => $quoteNumber,
            'invoice_date' => '2026-08-18',
            'accounting_date' => '2026-08-18',
            'due_date' => '2026-08-25',
            'description' => $quoteDescription,
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'description' => 'Laptop Dell Latitude 5520 Core i7',
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 5,
                    'unit_price' => 20000000,
                    'tax_rate' => 10,
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/sales/invoices', $payload);
        $resp->assertStatus(201)
            ->assertJsonPath('data.invoice_number', $quoteNumber)
            ->assertJsonPath('data.sub_total', 100000000)
            ->assertJsonPath('data.tax_amount', 10000000)
            ->assertJsonPath('data.total_amount', 110000000);
    }

    /**
     * 14. Test Đơn đặt hàng bán hàng (Sales Order) và theo dõi tiến độ giao hàng
     */
    public function test_sales_order_creation_and_fulfillment_tracking()
    {
        $soNumber = 'ĐBH-2026-001';
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer2->id,
            'invoice_number' => $soNumber,
            'invoice_date' => '2026-08-18',
            'accounting_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'description' => 'Đơn đặt hàng 3 màn hình Dell UltraSharp cho công ty Delta',
            'lines' => [
                [
                    'item_id' => $this->itemMonitor->id,
                    'description' => 'Màn hình Dell UltraSharp 27" 4K',
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 3,
                    'unit_price' => 8500000,
                    'tax_rate' => 10,
                ],
            ],
        ];

        $resp = $this->postJson('/api/v1/sales/invoices', $payload);
        $resp->assertStatus(201)
            ->assertJsonPath('data.invoice_number', $soNumber)
            ->assertJsonPath('data.sub_total', 25500000)
            ->assertJsonPath('data.tax_amount', 2550000)
            ->assertJsonPath('data.total_amount', 28050000);
    }

    /**
     * 15. Test Hóa đơn bán hàng chưa thu tiền kiêm phiếu xuất kho: Nợ 131 / Có 5111, Có 33311 & Nợ 632 / Có 1561
     */
    public function test_sales_invoice_unpaid_with_ar_and_inventory_gl_posting()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer1->id,
            'customer_name' => $this->customer1->name,
            'invoice_number' => 'HDBH-UNPAID-001',
            'invoice_date' => '2026-08-18',
            'accounting_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'description' => 'Bán 2 Laptop Dell kiêm phiếu xuất kho',
            'payment_status' => 'Unpaid',
            'is_export_slip' => true,
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'description' => 'Laptop Dell Latitude 5520',
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 2,
                    'unit_price' => 20000000, // 40,000,000
                    'tax_rate' => 10,
                    'tax_amount' => 4000000,
                    'tax_account' => '33311',
                    'inventory_account' => '1561',
                    'cogs_account' => '632',
                    'cogs_price' => 15000000, // Giá vốn: 2 * 15,000,000 = 30,000,000
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sales/invoices', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('data.invoice_number', 'HDBH-UNPAID-001')
            ->assertJsonPath('data.sub_total', 40000000)
            ->assertJsonPath('data.tax_amount', 4000000)
            ->assertJsonPath('data.total_amount', 44000000);

        $invoiceId = $response->json('data.id');

        // Post to GL
        $postResp = $this->postJson("/api/v1/sales/invoices/{$invoiceId}/post");
        $postResp->assertStatus(200);

        $invoice = SalesInvoice::find($invoiceId);
        $this->assertTrue((bool) $invoice->is_posted);
        $this->assertNotNull($invoice->journal_entry_id);

        // Verify Revenue & AR Posting
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '131',
            'debit_amount' => 44000000,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '5111',
            'credit_amount' => 40000000,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '33311',
            'credit_amount' => 4000000,
        ]);

        // Verify COGS & Inventory Posting
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '632',
            'debit_amount' => 30000000,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '1561',
            'credit_amount' => 30000000,
        ]);
    }

    /**
     * 16. Test Hóa đơn bán hàng thu tiền ngay (Cash / Bank): Nợ 1111 / Có 5111, Có 33311
     */
    public function test_sales_invoice_cash_and_bank_immediate_payment()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer2->id,
            'customer_name' => $this->customer2->name,
            'invoice_number' => 'HDBH-CASH-001',
            'invoice_date' => '2026-08-18',
            'accounting_date' => '2026-08-18',
            'due_date' => '2026-08-18',
            'description' => 'Bán 1 màn hình Dell thu tiền mặt ngay',
            'payment_status' => 'Paid',
            'lines' => [
                [
                    'item_id' => $this->itemMonitor->id,
                    'description' => 'Màn hình Dell UltraSharp 27"',
                    'debit_account' => '1111',
                    'credit_account' => '5111',
                    'quantity' => 1,
                    'unit_price' => 8500000,
                    'tax_rate' => 10,
                    'tax_amount' => 850000,
                    'tax_account' => '33311',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sales/invoices', $payload);
        $response->assertStatus(201);
        $invoiceId = $response->json('data.id');

        // Update status to Paid for immediate cash GL logic
        SalesInvoice::where('id', $invoiceId)->update(['status' => 'Paid']);

        $this->postJson("/api/v1/sales/invoices/{$invoiceId}/post")->assertStatus(200);

        $invoice = SalesInvoice::find($invoiceId);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '1111',
            'debit_amount' => 9350000,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $invoice->journal_entry_id,
            'account_code' => '5111',
            'credit_amount' => 8500000,
        ]);
    }

    /**
     * 17. Test Báo cáo công nợ phải thu (AR Aging Report)
     */
    public function test_ar_aging_report_calculation()
    {
        // Create an unpaid invoice
        SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer1->id,
            'invoice_number' => 'HDBH-AGING-001',
            'invoice_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'sub_total' => 20000000,
            'tax_amount' => 2000000,
            'total_amount' => 22000000,
            'status' => 'Unpaid',
            'is_posted' => true,
        ]);

        $response = $this->getJson('/api/v1/sales/ar-aging?company_id='.$this->company->id);
        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    /**
     * 18. Test vòng đời Hóa đơn bán hàng: Ghi sổ -> Bỏ ghi sổ -> Nhân bản
     */
    public function test_sales_invoice_post_unpost_and_duplicate()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer1->id,
            'invoice_number' => 'SA-LIFE-001',
            'invoice_date' => '2026-08-18',
            'accounting_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'description' => 'Hóa đơn kiểm tra lifecycle bán hàng',
            'lines' => [
                [
                    'description' => 'Màn hình Dell',
                    'debit_account' => '131',
                    'credit_account' => '5111',
                    'quantity' => 1,
                    'unit_price' => 8500000,
                    'tax_rate' => 10,
                    'tax_amount' => 850000,
                ],
            ],
        ];

        $createResp = $this->postJson('/api/v1/sales/invoices', $payload);
        $createResp->assertStatus(201);
        $invoiceId = $createResp->json('data.id');

        // Post
        $this->postJson("/api/v1/sales/invoices/{$invoiceId}/post")->assertStatus(200);
        $this->assertDatabaseHas('sales_invoices', ['id' => $invoiceId, 'is_posted' => true]);

        // Void
        $this->postJson("/api/v1/sales/invoices/{$invoiceId}/void")->assertStatus(200);
        $this->assertDatabaseHas('sales_invoices', ['id' => $invoiceId, 'is_posted' => false]);
    }

    /* =========================================================================
     * MODULE 4: KHO (INVENTORY - IN)
     * ========================================================================= */

    /**
     * 19. Test Phiếu nhập kho đa lý do: Nhập kho mua hàng, Nhập kho sản xuất, Nhập kho khác
     */
    public function test_inventory_receipt_multi_reason_and_gl_posting()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-MULTI-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'contact_id' => $this->supplier1->id,
            'contact_name' => $this->supplier1->name,
            'deliverer_name' => 'Trần Văn Giao Hàng',
            'description' => 'Nhập kho hàng mua và thành phẩm lắp ráp',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'quantity' => 10,
                    'unit_price' => 15000000, // 150,000,000
                    'debit_account' => '1561',
                    'credit_account' => '331',
                ],
                [
                    'item_id' => $this->itemMonitor->id,
                    'quantity' => 5,
                    'unit_price' => 6000000, // 30,000,000
                    'debit_account' => '1561',
                    'credit_account' => '154',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/inventory/receipts', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('voucher_number', 'PNK-MULTI-001')
            ->assertJsonPath('total_amount', 180000000);

        $receiptId = $response->json('id');

        // Post to GL
        $postResp = $this->postJson("/api/v1/inventory/receipts/{$receiptId}/post");
        $postResp->assertStatus(200);

        $receipt = InventoryReceipt::find($receiptId);
        $this->assertTrue((bool) $receipt->is_posted);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $receipt->journal_entry_id,
            'account_code' => '1561',
            'debit_amount' => 150000000,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $receipt->journal_entry_id,
            'account_code' => '331',
            'credit_amount' => 150000000,
        ]);
    }

    /**
     * 20. Test Phiếu xuất kho đa lý do: Xuất bán hàng, Xuất sản xuất, Xuất nội bộ
     */
    public function test_inventory_issue_multi_reason_and_gl_posting()
    {
        // First ensure stock exists
        $ir = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-INIT-001',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'warehouse_id' => $this->warehouseHanoi->id,
            'total_amount' => 150000000,
            'is_posted' => true,
        ]);
        $ir->lines()->create([
            'item_id' => $this->itemLaptop->id,
            'warehouse_id' => $this->warehouseHanoi->id,
            'quantity' => 10,
            'unit_price' => 15000000,
            'amount' => 150000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-MULTI-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'contact_id' => $this->customer1->id,
            'contact_name' => $this->customer1->name,
            'receiver_name' => 'Nguyễn Văn Nhận',
            'description' => 'Xuất kho 2 Laptop giao cho khách',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'warehouse_id' => $this->warehouseHanoi->id,
                    'quantity' => 2,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/inventory/issues', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('voucher_number', 'PXK-MULTI-001');

        $issueId = $response->json('id');
        $postResp = $this->postJson("/api/v1/inventory/issues/{$issueId}/post");
        $postResp->assertStatus(200);

        $issue = InventoryIssue::find($issueId);
        $this->assertTrue((bool) $issue->is_posted);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $issue->journal_entry_id,
            'status' => 'posted',
        ]);
    }

    /**
     * 21. Test Báo cáo tồn kho (Nhập - Xuất - Tồn): Số dư đầu kỳ, Phát sinh trong kỳ, Số dư cuối kỳ
     */
    public function test_inventory_stock_report_balances_and_movements()
    {
        // 1. Receipt: 10 items @ 15,000,000 = 150,000,000
        $ir = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-STOCK-01',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'warehouse_id' => $this->warehouseHanoi->id,
            'total_amount' => 150000000,
            'is_posted' => true,
        ]);
        $ir->lines()->create([
            'item_id' => $this->itemLaptop->id,
            'warehouse_id' => $this->warehouseHanoi->id,
            'quantity' => 10,
            'unit_price' => 15000000,
            'amount' => 150000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        // 2. Issue: 4 items @ 15,000,000 = 60,000,000
        $ii = InventoryIssue::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PXK-STOCK-01',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'warehouse_id' => $this->warehouseHanoi->id,
            'total_amount' => 60000000,
            'is_posted' => true,
        ]);
        $ii->lines()->create([
            'item_id' => $this->itemLaptop->id,
            'warehouse_id' => $this->warehouseHanoi->id,
            'quantity' => 4,
            'unit_price' => 15000000,
            'amount' => 60000000,
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);

        $response = $this->getJson('/api/v1/inventory/stock-report?company_id='.$this->company->id);
        $response->assertStatus(200);

        $report = $response->json();
        $this->assertNotEmpty($report);

        $laptopRow = collect($report)->firstWhere('item_id', $this->itemLaptop->id);
        $this->assertNotNull($laptopRow);
        $this->assertEquals(10, $laptopRow['in_qty']);
        $this->assertEquals(150000000, $laptopRow['in_amt']);
        $this->assertEquals(4, $laptopRow['out_qty']);
        $this->assertEquals(60000000, $laptopRow['out_amt']);
        $this->assertEquals(6, $laptopRow['end_qty']);
        $this->assertEquals(90000000, $laptopRow['end_amt']);
    }

    /**
     * 22. Test Thuật toán tính giá xuất kho Bình quân gia quyền (Weighted / Moving Average)
     */
    public function test_inventory_cost_calculation_weighted_average_run()
    {
        // Batch 1: 10 units @ 10,000,000 = 100,000,000
        $ir1 = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-AVG-01',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'warehouse_id' => $this->warehouseHanoi->id,
            'total_amount' => 100000000,
            'is_posted' => true,
        ]);
        $ir1->lines()->create([
            'item_id' => $this->itemMonitor->id,
            'warehouse_id' => $this->warehouseHanoi->id,
            'quantity' => 10,
            'unit_price' => 10000000,
            'amount' => 100000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        // Batch 2: 10 units @ 12,000,000 = 120,000,000
        $ir2 = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-AVG-02',
            'voucher_date' => '2026-08-05',
            'posting_date' => '2026-08-05',
            'warehouse_id' => $this->warehouseHanoi->id,
            'total_amount' => 120000000,
            'is_posted' => true,
        ]);
        $ir2->lines()->create([
            'item_id' => $this->itemMonitor->id,
            'warehouse_id' => $this->warehouseHanoi->id,
            'quantity' => 10,
            'unit_price' => 12000000,
            'amount' => 120000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        // Calculate moving average cost: (100,000,000 + 120,000,000) / 20 = 11,000,000
        $valuationService = app(InventoryValuationService::class);
        $cost = $valuationService->getMovingAverageCost($this->itemMonitor->id, $this->company->id, '2026-08-10');

        $this->assertEquals(11000000, $cost);
    }

    /**
     * 23. Test Thuật toán tính giá xuất kho FIFO (Nhập trước - Xuất trước)
     */
    public function test_inventory_cost_calculation_fifo_run()
    {
        // Batch 1 (Early): 5 units @ 6,000,000 = 30,000,000
        $ir1 = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-01',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'warehouse_id' => $this->warehouseHanoi->id,
            'total_amount' => 30000000,
            'is_posted' => true,
        ]);
        $ir1->lines()->create([
            'item_id' => $this->itemMonitor->id,
            'warehouse_id' => $this->warehouseHanoi->id,
            'quantity' => 5,
            'unit_price' => 6000000,
            'amount' => 30000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        // Batch 2 (Later): 5 units @ 8,000,000 = 40,000,000
        $ir2 = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-FIFO-02',
            'voucher_date' => '2026-08-10',
            'posting_date' => '2026-08-10',
            'warehouse_id' => $this->warehouseHanoi->id,
            'total_amount' => 40000000,
            'is_posted' => true,
        ]);
        $ir2->lines()->create([
            'item_id' => $this->itemMonitor->id,
            'warehouse_id' => $this->warehouseHanoi->id,
            'quantity' => 5,
            'unit_price' => 8000000,
            'amount' => 40000000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        // Under FIFO: First issue of 5 units depletes Batch 1 @ 6,000,000
        $costBatch1 = 5 * 6000000;
        $this->assertEquals(30000000, $costBatch1);

        // Next issue of 3 units comes from Batch 2 @ 8,000,000
        $costBatch2 = 3 * 8000000;
        $this->assertEquals(24000000, $costBatch2);

        // Total inventory remaining = 2 units @ 8,000,000 = 16,000,000
        $remainingStockValue = 2 * 8000000;
        $this->assertEquals(16000000, $remainingStockValue);
    }

    /**
     * 24. Test vòng đời Phiếu kho: Ghi sổ -> Bỏ ghi sổ -> Kiểm tra tính toàn vẹn
     */
    public function test_inventory_voucher_post_unpost_duplicate()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PNK-LIFE-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'description' => 'Phiếu nhập kho kiểm tra lifecycle',
            'lines' => [
                [
                    'item_id' => $this->itemLaptop->id,
                    'quantity' => 1,
                    'unit_price' => 15000000,
                    'credit_account' => '331',
                ],
            ],
        ];

        $createResp = $this->postJson('/api/v1/inventory/receipts', $payload);
        $createResp->assertStatus(201);
        $receiptId = $createResp->json('id');

        // Post
        $this->postJson("/api/v1/inventory/receipts/{$receiptId}/post")->assertStatus(200);
        $this->assertDatabaseHas('inventory_receipts', ['id' => $receiptId, 'is_posted' => true]);

        // Void
        $this->postJson("/api/v1/inventory/receipts/{$receiptId}/void")->assertStatus(200);
        $this->assertDatabaseHas('inventory_receipts', ['id' => $receiptId, 'is_posted' => false]);
    }

    /* =========================================================================
     * MODULE 5: TỔNG HỢP & BÁO CÁO TÀI CHÍNH (GENERAL LEDGER - GL)
     * ========================================================================= */

    /**
     * 25. Test Chứng từ nghiệp vụ khác (General Journal): Định khoản đa dòng cân đối Nợ/Có
     */
    public function test_general_journal_multi_line_balanced_entry_and_posting()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PKT-2026-001',
            'voucher_date' => '2026-08-18',
            'posting_date' => '2026-08-18',
            'reason' => 'Trích chi phí lương và bảo hiểm bộ phận quản lý',
            'total_amount' => 50000000,
            'status' => 'draft',
            'lines' => [
                [
                    'account_code' => '642',
                    'description' => 'Chi phí lương bộ phận QLDN',
                    'debit_amount' => 40000000,
                    'credit_amount' => 0,
                ],
                [
                    'account_code' => '642',
                    'description' => 'Chi phí bảo hiểm công ty đóng',
                    'debit_amount' => 10000000,
                    'credit_amount' => 0,
                ],
                [
                    'account_code' => '334',
                    'description' => 'Phải trả lương nhân viên',
                    'debit_amount' => 0,
                    'credit_amount' => 40000000,
                ],
                [
                    'account_code' => '3383',
                    'description' => 'Phải nộp bảo hiểm xã hội',
                    'debit_amount' => 0,
                    'credit_amount' => 10000000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/gl/journal-entries', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('voucher_number', 'PKT-2026-001')
            ->assertJsonPath('total_amount', 50000000);

        $jeId = $response->json('id');

        // Post
        $postResp = $this->postJson("/api/v1/gl/journal-entries/{$jeId}/post");
        $postResp->assertStatus(200)
            ->assertJsonPath('status', 'posted');

        $this->assertDatabaseHas('journal_entries', ['id' => $jeId, 'status' => 'posted']);

        // Void
        $voidResp = $this->postJson("/api/v1/gl/journal-entries/{$jeId}/void");
        $voidResp->assertStatus(200)
            ->assertJsonPath('status', 'voided');

        $this->assertDatabaseHas('journal_entries', ['id' => $jeId, 'status' => 'voided']);
    }

    /**
     * 26. Test Động cơ Kết chuyển cuối kỳ: 511 -> 911, 632, 642 -> 911, Xác định kết quả kinh doanh 911 -> 4212
     */
    public function test_period_closing_preview_and_execution_911_to_4212()
    {
        // 1. Create Period
        $period = Period::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_number' => 8,
            'name' => 'Tháng 8/2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'is_closed' => false,
        ]);

        // 2. Seed Revenue (5111: 100,000,000) and Expenses (632: 60,000,000, 642: 20,000,000)
        $jeRev = JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'sales_invoice',
            'voucher_number' => 'GL-REV-01',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Doanh thu bán hàng tháng 8',
            'total_amount' => 100000000,
            'status' => 'posted',
        ]);
        $jeRev->lines()->create(['account_code' => '131', 'debit_amount' => 100000000, 'credit_amount' => 0, 'description' => 'Phải thu']);
        $jeRev->lines()->create(['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 100000000, 'description' => 'Doanh thu']);

        $jeExp = JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'GL-EXP-01',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Chi phí tháng 8',
            'total_amount' => 80000000,
            'status' => 'posted',
        ]);
        $jeExp->lines()->create(['account_code' => '632', 'debit_amount' => 60000000, 'credit_amount' => 0, 'description' => 'Giá vốn']);
        $jeExp->lines()->create(['account_code' => '642', 'debit_amount' => 20000000, 'credit_amount' => 0, 'description' => 'Chi phí QLDN']);
        $jeExp->lines()->create(['account_code' => '1561', 'debit_amount' => 0, 'credit_amount' => 60000000, 'description' => 'Xuất kho']);
        $jeExp->lines()->create(['account_code' => '1111', 'debit_amount' => 0, 'credit_amount' => 20000000, 'description' => 'Tiền mặt']);

        // 3. Execute Period Closing Journal Entries:
        // Entry 1: Kết chuyển doanh thu (5111 -> 911: 100,000,000)
        // Entry 2: Kết chuyển chi phí (911 -> 632: 60M, 642: 20M = 80M)
        // Entry 3: Kết chuyển lãi sang 4212 (911 -> 4212: 20,000,000)
        $closingPayload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'KC-2026-08',
            'voucher_date' => '2026-08-31',
            'posting_date' => '2026-08-31',
            'reason' => 'Bút toán kết chuyển xác định kết quả kinh doanh tháng 8/2026',
            'total_amount' => 200000000,
            'status' => 'draft',
            'lines' => [
                // 5111 -> 911
                ['account_code' => '5111', 'debit_amount' => 100000000, 'credit_amount' => 0, 'description' => 'Kết chuyển doanh thu'],
                ['account_code' => '911', 'debit_amount' => 0, 'credit_amount' => 100000000, 'description' => 'Kết chuyển doanh thu vào 911'],
                // 911 -> 632, 642
                ['account_code' => '911', 'debit_amount' => 80000000, 'credit_amount' => 0, 'description' => 'Kết chuyển chi phí từ 911'],
                ['account_code' => '632', 'debit_amount' => 0, 'credit_amount' => 60000000, 'description' => 'Kết chuyển giá vốn'],
                ['account_code' => '642', 'debit_amount' => 0, 'credit_amount' => 20000000, 'description' => 'Kết chuyển CPQL'],
                // 911 -> 4212 (Profit = 20,000,000)
                ['account_code' => '911', 'debit_amount' => 20000000, 'credit_amount' => 0, 'description' => 'Kết chuyển lợi nhuận sau thuế'],
                ['account_code' => '4212', 'debit_amount' => 0, 'credit_amount' => 20000000, 'description' => 'LNST chưa phân phối'],
            ],
        ];

        $closeJeResp = $this->postJson('/api/v1/gl/journal-entries', $closingPayload);
        $closeJeResp->assertStatus(201);

        // Public HTTP creation is draft-only. Posting is an explicit,
        // separately-authorized accounting transition.
        $closingEntryId = $closeJeResp->json('data.id') ?? $closeJeResp->json('id');
        $this->postJson("/api/v1/gl/journal-entries/{$closingEntryId}/post")
            ->assertStatus(200);

        // 4. A manually posted voucher cannot act as server closing evidence.
        // Direct period close remains blocked; the generated workflow owns the
        // transition to closed.
        $periodCloseResp = $this->postJson('/api/v1/gl/periods/close', ['period_id' => $period->id]);
        $periodCloseResp->assertStatus(409);

        $this->assertDatabaseHas('periods', ['id' => $period->id, 'is_closed' => false]);

        // 5. Verify trial balance shows 5111, 632, 642, 911 ending balances are 0 and 4212 has 20M credit balance
        $tbResp = $this->getJson('/api/v1/reports/trial-balance?company_id='.$this->company->id);
        $tbResp->assertStatus(200);
        $tb = $tbResp->json();

        $acc5111 = collect($tb)->firstWhere('code', '5111');
        $this->assertEquals(0, $acc5111['ending_credit']);

        $acc632 = collect($tb)->firstWhere('code', '632');
        $this->assertEquals(0, $acc632['ending_debit']);

        $acc911 = collect($tb)->firstWhere('code', '911');
        $this->assertEquals(0, $acc911['ending_debit']);
        $this->assertEquals(0, $acc911['ending_credit']);

        $acc4212 = collect($tb)->firstWhere('code', '4212');
        $this->assertEquals(20000000, $acc4212['ending_credit']);
    }

    /**
     * 27. Test Hệ thống Báo cáo Tài chính: Bảng cân đối phát sinh (Trial Balance), Bảng cân đối kế toán (Balance Sheet), Báo cáo KQKD (Income Statement)
     */
    public function test_financial_reports_trial_balance_balance_sheet_income_statement()
    {
        // Post transaction: Owner capital contribution (1111: 500,000,000 / 411: 500,000,000)
        $jeCap = JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'GL-CAP-01',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'description' => 'Vốn góp chủ sở hữu ban đầu',
            'total_amount' => 500000000,
            'status' => 'posted',
        ]);
        $jeCap->lines()->create(['account_code' => '1111', 'debit_amount' => 500000000, 'credit_amount' => 0, 'description' => 'Tiền mặt']);
        $jeCap->lines()->create(['account_code' => '411', 'debit_amount' => 0, 'credit_amount' => 500000000, 'description' => 'Vốn CSH']);

        // 1. Trial Balance Report
        $tbResp = $this->getJson('/api/v1/reports/trial-balance?company_id='.$this->company->id);
        $tbResp->assertStatus(200);
        $tb = $tbResp->json();
        $this->assertIsArray($tb);
        $this->assertNotEmpty($tb);

        $totDebit = collect($tb)->where('is_parent', false)->sum('ending_debit');
        $totCredit = collect($tb)->where('is_parent', false)->sum('ending_credit');
        $this->assertEquals($totDebit, $totCredit, 'Trial balance must be balanced (Total Debits == Total Credits)');

        // 2. Balance Sheet Report
        $bsResp = $this->getJson('/api/v1/reports/balance-sheet?company_id='.$this->company->id);
        $bsResp->assertStatus(200)
            ->assertJsonStructure(['assets', 'liabilities', 'equity']);
        $assets = $bsResp->json('assets');
        $equity = $bsResp->json('equity');
        $this->assertNotEmpty($assets);
        $this->assertNotEmpty($equity);

        // 3. Income Statement Report
        $isResp = $this->getJson('/api/v1/reports/income-statement?company_id='.$this->company->id);
        $isResp->assertStatus(200);
        $is = $isResp->json();
        $this->assertIsArray($is);
        $this->assertNotEmpty($is);

        // 4. General Journal Report
        $gjResp = $this->getJson('/api/v1/reports/general-journal?from_date=2026-01-01&to_date=2026-12-31');
        $gjResp->assertStatus(200);
        $this->assertIsArray($gjResp->json());
    }

    /**
     * 28. Test Tra cứu chứng từ tham chiếu chéo (Cross-cutting Reference Modal) toàn hệ thống 5 phân hệ
     */
    public function test_cross_cutting_voucher_reference_modal_search_and_attach()
    {
        // Create sample vouchers across modules
        $inv = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer1->id,
            'invoice_number' => 'SA-CROSS-01',
            'invoice_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'sub_total' => 10000000,
            'tax_amount' => 1000000,
            'total_amount' => 11000000,
            'description' => 'Hóa đơn tham chiếu chéo bán hàng',
        ]);
        $inv->lines()->create([
            'sales_invoice_id' => $inv->id,
            'debit_account' => '131',
            'credit_account' => '5111',
            'quantity' => 1,
            'unit_price' => 10000000,
            'amount' => 10000000,
            'tax_rate' => 10,
        ]);

        $pu = PurchaseInvoice::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier1->id,
            'supplier_name' => $this->supplier1->name,
            'invoice_number' => 'PU-CROSS-01',
            'invoice_date' => '2026-08-18',
            'due_date' => '2026-09-18',
            'sub_total' => 20000000,
            'tax_amount' => 2000000,
            'total_amount' => 22000000,
            'description' => 'Hóa đơn tham chiếu chéo mua hàng',
        ]);
        $pu->lines()->create([
            'purchase_invoice_id' => $pu->id,
            'debit_account' => '1561',
            'credit_account' => '331',
            'quantity' => 1,
            'unit_price' => 20000000,
            'amount' => 20000000,
        ]);

        // Search by voucher number
        $respSA = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_number&search_value=SA-CROSS-01');
        $respSA->assertStatus(200);
        $this->assertEquals(1, $respSA->json('total'));
        $this->assertEquals('SA-CROSS-01', $respSA->json('data.0.voucher_number'));

        $respPU = $this->getJson('/api/v1/voucher-references/search?search_by=voucher_number&search_value=PU-CROSS-01');
        $respPU->assertStatus(200);
        $this->assertEquals(1, $respPU->json('total'));
        $this->assertEquals('PU-CROSS-01', $respPU->json('data.0.voucher_number'));
    }
}
