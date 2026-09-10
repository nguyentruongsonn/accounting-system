<?php

namespace Tests\Feature;

use App\Models\AssetDisposal;
use App\Models\AssetRevaluation;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\DepreciationLog;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FixedAssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Công ty TNHH MISA M3 Test',
            'tax_code' => '0101234567',
            'address' => 'Hà Nội, Việt Nam',
        ]);

        $this->user->update(['company_id' => $this->company->id]);

        FiscalYear::create([
            'company_id' => $this->company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        // Tạo hệ thống tài khoản kế toán chuẩn VAS TT200
        $accounts = [
            ['code' => '211', 'name' => 'Tài sản cố định hữu hình', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '2111', 'name' => 'Nhà cửa, vật kiến trúc', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '2112', 'name' => 'Máy móc, thiết bị', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '2141', 'name' => 'Hao mòn TSCĐ hữu hình', 'type' => 'asset', 'nature' => 'credit'],
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '1111', 'name' => 'Tiền Việt Nam', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '6424', 'name' => 'Chi phí khấu hao TSCĐ QLDN', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '6414', 'name' => 'Chi phí khấu hao TSCĐ Bán hàng', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '154', 'name' => 'Chi phí SXKD dở dang', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '6274', 'name' => 'Chi phí khấu hao TSCĐ PX', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '412', 'name' => 'Chênh lệch đánh giá lại tài sản', 'type' => 'equity', 'nature' => 'credit'],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'nature' => 'credit'],
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
    }

    /**
     * Test Scenario 1: Ghi tăng TSCĐ và sinh bút toán Sổ cái GL cân bằng
     */
    public function test_asset_registration_creates_record_and_balanced_increment_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-0001',
            'voucher_date' => '2026-08-01',
            'asset_code' => 'TS00001',
            'asset_name' => 'Máy chủ Dell PowerEdge R750',
            'category_code' => 'MAY_MOC',
            'department_code' => 'QLDN',
            'quantity' => 1,
            'purchase_date' => '2026-08-01',
            'start_depreciation_date' => '2026-08-01',
            'original_cost' => 120000000,
            'depreciable_cost' => 120000000,
            'useful_life_months' => 24,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6424',
            'credit_account' => '331',
            'is_posted' => true,
        ];

        $response = $this->postJson('/api/v1/fixed-assets', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('asset_code', 'TS00001')
            ->assertJsonPath('monthly_depreciation', '5000000.00')
            ->assertJsonPath('net_value', '120000000.00')
            ->assertJsonPath('is_posted', true)
            ->assertJsonPath('status', 'posted');

        $this->assertDatabaseHas('fixed_assets', [
            'asset_code' => 'TS00001',
            'company_id' => $this->company->id,
            'monthly_depreciation' => 5000000,
            'is_posted' => true,
        ]);

        $asset = FixedAsset::where('asset_code', 'TS00001')->first();
        $this->assertNotNull($asset->journal_entry_id);

        $je = JournalEntry::with('lines')->find($asset->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertEquals('posted', $je->status);

        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');
        $this->assertEquals(120000000, $totalDebit);
        $this->assertEquals(120000000, $totalCredit);
        $this->assertEquals($totalDebit, $totalCredit);
    }

    /**
     * Test Scenario 2: Vòng đời CRUD, Ghi sổ, Bỏ ghi sổ, Nhân bản, Sinh mã tự động
     */
    public function test_fixed_asset_crud_and_lifecycle_transitions()
    {
        // 1. Tạo Draft
        $payload = [
            'company_id' => $this->company->id,
            'asset_name' => 'Xe tải Isuzu Forward',
            'department_code' => 'BAN_HANG',
            'purchase_date' => '2026-08-01',
            'original_cost' => 600000000,
            'depreciable_cost' => 600000000,
            'useful_life_months' => 60,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6414',
            'credit_account' => '331',
            'is_posted' => false,
            'status' => 'draft',
        ];

        $createRes = $this->postJson('/api/v1/fixed-assets', $payload);
        $createRes->assertStatus(201);
        $assetId = $createRes->json('id');
        $this->assertFalse($createRes->json('is_posted'));

        // 2. Ghi sổ (Post)
        $postRes = $this->postJson("/api/v1/fixed-assets/{$assetId}/post");
        $postRes->assertStatus(200);
        $this->assertTrue($postRes->json('data.is_posted'));

        // 3. Bỏ ghi sổ (Unpost)
        $unpostRes = $this->postJson("/api/v1/fixed-assets/{$assetId}/unpost");
        $unpostRes->assertStatus(200);
        $this->assertFalse($unpostRes->json('data.is_posted'));

        // 4. Cập nhật thông tin
        $updateRes = $this->putJson("/api/v1/fixed-assets/{$assetId}", [
            'asset_name' => 'Xe tải Isuzu Forward 5 Tấn (Đã nâng cấp)',
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('asset_name', 'Xe tải Isuzu Forward 5 Tấn (Đã nâng cấp)');

        // 5. Nhân bản (Duplicate)
        $dupRes = $this->postJson("/api/v1/fixed-assets/{$assetId}/duplicate");
        $dupRes->assertStatus(201);
        $dupId = $dupRes->json('data.id');
        $this->assertNotEquals($assetId, $dupId);
        $this->assertFalse($dupRes->json('data.is_posted'));

        // 6. Sinh mã tự động (Next Code)
        $codeRes = $this->getJson('/api/v1/fixed-assets/next-code?type=TS');
        $codeRes->assertStatus(200);
        $this->assertNotEmpty($codeRes->json('code'));
    }

    /**
     * Test Scenario 3: Xem trước (Preview) trích khấu hao tháng phân bổ đúng theo bộ phận
     */
    public function test_preview_monthly_depreciation_calculates_correct_breakdown_per_department()
    {
        // Tài sản 1: QLDN (6424) -> 24M / 12 tháng = 2M/tháng
        FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-0001',
            'voucher_date' => '2026-08-01',
            'asset_code' => 'TS-QLDN',
            'asset_name' => 'Máy in văn phòng Giám đốc',
            'department_code' => 'QLDN',
            'purchase_date' => '2026-08-01',
            'start_depreciation_date' => '2026-08-01',
            'original_cost' => 24000000,
            'depreciable_cost' => 24000000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 2000000,
            'accumulated_depreciation' => 0,
            'net_value' => 24000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6424',
            'is_active' => true,
            'status' => 'posted',
        ]);

        // Tài sản 2: Bán hàng (6414) -> 36M / 12 tháng = 3M/tháng
        FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-0002',
            'voucher_date' => '2026-08-01',
            'asset_code' => 'TS-BH',
            'asset_name' => 'Màn hình quảng cáo Showroom',
            'department_code' => 'BAN_HANG',
            'purchase_date' => '2026-08-01',
            'start_depreciation_date' => '2026-08-01',
            'original_cost' => 36000000,
            'depreciable_cost' => 36000000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 3000000,
            'accumulated_depreciation' => 0,
            'net_value' => 36000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6414',
            'is_active' => true,
            'status' => 'posted',
        ]);

        // Tài sản 3: Phân xưởng sản xuất (154) -> 60M / 12 tháng = 5M/tháng
        FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-0003',
            'voucher_date' => '2026-08-01',
            'asset_code' => 'TS-SX',
            'asset_name' => 'Máy dập cơ khí',
            'department_code' => 'SAN_XUAT',
            'purchase_date' => '2026-08-01',
            'start_depreciation_date' => '2026-08-01',
            'original_cost' => 60000000,
            'depreciable_cost' => 60000000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 5000000,
            'accumulated_depreciation' => 0,
            'net_value' => 60000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '154',
            'is_active' => true,
            'status' => 'posted',
        ]);

        $response = $this->getJson('/api/v1/fixed-assets/depreciation/preview?month=2026-08');
        $response->assertStatus(200)
            ->assertJsonPath('total_assets', 3)
            ->assertJsonPath('total_amount', 10000000);

        $lines = collect($response->json('lines'));
        $this->assertEquals(2000000, $lines->firstWhere('asset_code', 'TS-QLDN')['monthly_depreciation']);
        $this->assertEquals('6424', $lines->firstWhere('asset_code', 'TS-QLDN')['expense_account']);

        $this->assertEquals(3000000, $lines->firstWhere('asset_code', 'TS-BH')['monthly_depreciation']);
        $this->assertEquals('6414', $lines->firstWhere('asset_code', 'TS-BH')['expense_account']);

        $this->assertEquals(5000000, $lines->firstWhere('asset_code', 'TS-SX')['monthly_depreciation']);
        $this->assertEquals('154', $lines->firstWhere('asset_code', 'TS-SX')['expense_account']);
    }

    /**
     * Test Scenario 4: Chạy trích khấu hao tháng và sinh bút toán Sổ cái GL phân bổ chuẩn
     */
    public function test_run_monthly_depreciation_allocates_by_department_and_generates_balanced_gl()
    {
        $this->test_preview_monthly_depreciation_calculates_correct_breakdown_per_department();

        $runRes = $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->company->id,
            'month' => '2026-08',
            'description' => 'Trích khấu hao TSCĐ toàn công ty tháng 08/2026',
        ]);

        $runRes->assertStatus(200)
            ->assertJsonPath('data.total_amount', '10000000.00')
            ->assertJsonPath('data.is_posted', true);

        $this->assertDatabaseHas('asset_depreciation_logs', [
            'company_id' => $this->company->id,
            'month' => '2026-08',
            'total_amount' => 10000000,
            'is_posted' => true,
        ]);

        $this->assertDatabaseCount('asset_depreciation_log_lines', 3);

        $log = DepreciationLog::where('company_id', $this->company->id)->where('month', '2026-08')->first();
        $this->assertNotNull($log->journal_entry_id);

        $je = JournalEntry::with('lines')->find($log->journal_entry_id);
        $this->assertNotNull($je);

        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');
        $this->assertEquals(10000000, $totalDebit);
        $this->assertEquals(10000000, $totalCredit);
        $this->assertEquals($totalDebit, $totalCredit);

        // Kiểm tra đúng từng tài khoản Nợ 6424, Nợ 6414, Nợ 154 và Có 2141
        $debit6424 = $je->lines->where('account_code', '6424')->sum('debit_amount');
        $debit6414 = $je->lines->where('account_code', '6414')->sum('debit_amount');
        $debit154 = $je->lines->where('account_code', '154')->sum('debit_amount');
        $credit2141 = $je->lines->where('account_code', '2141')->sum('credit_amount');

        $this->assertEquals(2000000, $debit6424);
        $this->assertEquals(3000000, $debit6414);
        $this->assertEquals(5000000, $debit154);
        $this->assertEquals(10000000, $credit2141);
    }

    /**
     * Test Scenario 5: Cập nhật hao mòn lũy kế và giá trị còn lại trên FixedAsset
     */
    public function test_monthly_depreciation_updates_accumulated_and_net_value()
    {
        $this->test_run_monthly_depreciation_allocates_by_department_and_generates_balanced_gl();

        $asset1 = FixedAsset::where('asset_code', 'TS-QLDN')->first();
        $this->assertEquals(2000000, (float) $asset1->accumulated_depreciation);
        $this->assertEquals(22000000, (float) $asset1->net_value);

        $asset2 = FixedAsset::where('asset_code', 'TS-BH')->first();
        $this->assertEquals(3000000, (float) $asset2->accumulated_depreciation);
        $this->assertEquals(33000000, (float) $asset2->net_value);

        $asset3 = FixedAsset::where('asset_code', 'TS-SX')->first();
        $this->assertEquals(5000000, (float) $asset3->accumulated_depreciation);
        $this->assertEquals(55000000, (float) $asset3->net_value);
    }

    /**
     * Test Scenario 6: Bỏ ghi sổ (Unpost) hoàn nhập giá trị tài sản và hủy bút toán GL
     */
    public function test_unpost_monthly_depreciation_restores_asset_values_and_cancels_gl_entry()
    {
        $this->test_run_monthly_depreciation_allocates_by_department_and_generates_balanced_gl();

        $log = DepreciationLog::where('company_id', $this->company->id)->where('month', '2026-08')->first();
        $journalId = $log->journal_entry_id;

        $unpostRes = $this->postJson("/api/v1/fixed-assets/depreciation/{$log->id}/unpost");
        $unpostRes->assertStatus(200)
            ->assertJsonPath('data.is_posted', false);

        // Kiểm tra số liệu được phục hồi nguyên vẹn
        $asset1 = FixedAsset::where('asset_code', 'TS-QLDN')->first();
        $this->assertEquals(0, (float) $asset1->accumulated_depreciation);
        $this->assertEquals(24000000, (float) $asset1->net_value);

        $asset2 = FixedAsset::where('asset_code', 'TS-BH')->first();
        $this->assertEquals(0, (float) $asset2->accumulated_depreciation);
        $this->assertEquals(36000000, (float) $asset2->net_value);

        // Kiểm tra GL Entry chuyển sang voided
        $je = JournalEntry::find($journalId);
        $this->assertEquals('voided', $je->status);
    }

    /**
     * Test Scenario 7: Không thể chạy trùng lặp khấu hao cho cùng 1 tháng
     */
    public function test_cannot_run_duplicate_depreciation_for_same_month()
    {
        $this->test_run_monthly_depreciation_allocates_by_department_and_generates_balanced_gl();

        $duplicateRun = $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->company->id,
            'month' => '2026-08',
        ]);

        $duplicateRun->assertStatus(400);
    }

    /**
     * Test Scenario 8: Thanh lý / Ghi giảm TSCĐ và sinh bút toán Sổ cái VAS TT200 cân bằng
     */
    public function test_asset_disposal_writeoff_generates_exact_balanced_gl()
    {
        // 1. Tạo tài sản nguyên giá 100M
        $asset = FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-DISP',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-DISP-01',
            'asset_name' => 'Máy photocopy văn phòng',
            'department_code' => 'QLDN',
            'purchase_date' => '2026-01-01',
            'start_depreciation_date' => '2026-01-01',
            'original_cost' => 100000000,
            'depreciable_cost' => 100000000,
            'useful_life_months' => 20,
            'monthly_depreciation' => 5000000,
            'accumulated_depreciation' => 20000000, // Đã khấu hao 20M
            'net_value' => 80000000,               // Giá trị còn lại 80M
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6424',
            'is_active' => true,
            'status' => 'posted',
        ]);

        // 2. Thanh lý với giá bán 30M
        $disposalPayload = [
            'voucher_number' => 'GGTS-2026-0001',
            'voucher_date' => '2026-08-20',
            'disposal_date' => '2026-08-20',
            'disposal_type' => 'liquidation',
            'disposal_reason' => 'Thanh lý máy cũ nâng cấp máy mới',
            'disposal_price' => 30000000,
            'payment_method' => 'cash',
        ];

        $res = $this->postJson("/api/v1/fixed-assets/{$asset->id}/dispose", $disposalPayload);
        $res->assertStatus(201)
            ->assertJsonPath('data.original_cost', '100000000.00')
            ->assertJsonPath('data.accumulated_depreciation', '20000000.00')
            ->assertJsonPath('data.net_value', '80000000.00')
            ->assertJsonPath('data.disposal_price', '30000000.00');

        // Kiểm tra tài sản chuyển trạng thái disposed
        $asset->refresh();
        $this->assertEquals('disposed', $asset->status);
        $this->assertFalse($asset->is_active);

        // Kiểm tra bút toán GL:
        // Nợ 2141 (20M) + Nợ 811 (80M) = Có 211 (100M)
        // Nợ 1111 (30M) / Có 711 (30M)
        $disposal = AssetDisposal::where('fixed_asset_id', $asset->id)->first();
        $this->assertNotNull($disposal->journal_entry_id);

        $je = JournalEntry::with('lines')->find($disposal->journal_entry_id);
        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');

        $this->assertEquals(130000000, $totalDebit);
        $this->assertEquals(130000000, $totalCredit);
        $this->assertEquals($totalDebit, $totalCredit);

        $this->assertEquals(20000000, $je->lines->where('account_code', '2141')->sum('debit_amount'));
        $this->assertEquals(80000000, $je->lines->where('account_code', '811')->sum('debit_amount'));
        $this->assertEquals(100000000, $je->lines->where('account_code', '211')->sum('credit_amount'));
        $this->assertEquals(30000000, $je->lines->where('account_code', '1111')->sum('debit_amount'));
        $this->assertEquals(30000000, $je->lines->where('account_code', '711')->sum('credit_amount'));
    }

    /**
     * Test Scenario 9: Đánh giá lại TSCĐ, cập nhật nguyên giá và sinh bút toán TK 412
     */
    public function test_asset_revaluation_updates_cost_and_generates_account_412_gl()
    {
        $asset = FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-REVAL',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-REVAL-01',
            'asset_name' => 'Dây chuyền đóng gói',
            'department_code' => 'SAN_XUAT',
            'purchase_date' => '2026-01-01',
            'start_depreciation_date' => '2026-01-01',
            'original_cost' => 120000000,
            'depreciable_cost' => 120000000,
            'useful_life_months' => 24,
            'monthly_depreciation' => 5000000,
            'accumulated_depreciation' => 10000000,
            'net_value' => 110000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '154',
            'is_active' => true,
            'status' => 'posted',
        ]);

        // Đánh giá tăng nguyên giá từ 120M lên 150M (+30M)
        $revalPayload = [
            'voucher_number' => 'DGTS-2026-0001',
            'voucher_date' => '2026-08-20',
            'new_original_cost' => 150000000,
            'new_useful_life_months' => 25,
            'reason' => 'Đánh giá lại theo quyết định thẩm định giá',
        ];

        $res = $this->postJson("/api/v1/fixed-assets/{$asset->id}/revalue", $revalPayload);
        $res->assertStatus(201)
            ->assertJsonPath('data.cost_difference', '30000000.00');

        $asset->refresh();
        $this->assertEquals(150000000, (float) $asset->original_cost);
        $this->assertEquals(6000000, (float) $asset->monthly_depreciation); // 150M / 25 tháng = 6M

        $reval = AssetRevaluation::where('fixed_asset_id', $asset->id)->first();
        $this->assertNotNull($reval->journal_entry_id);

        $je = JournalEntry::with('lines')->find($reval->journal_entry_id);
        $this->assertEquals(30000000, $je->lines->where('account_code', '211')->sum('debit_amount'));
        $this->assertEquals(30000000, $je->lines->where('account_code', '412')->sum('credit_amount'));
    }

    /**
     * Test Scenario 10: Tài sản đã thanh lý không được tính khấu hao và không thể thanh lý lần 2
     */
    public function test_disposed_asset_cannot_be_depreciated_or_disposed_again()
    {
        $this->test_asset_disposal_writeoff_generates_exact_balanced_gl();

        $asset = FixedAsset::where('asset_code', 'TS-DISP-01')->first();

        // 1. Thử thanh lý lần 2 -> Lỗi
        $dispRes = $this->postJson("/api/v1/fixed-assets/{$asset->id}/dispose", [
            'voucher_date' => '2026-08-21',
        ]);
        $dispRes->assertStatus(400);

        // 2. Chạy khấu hao kỳ tiếp theo -> Tài sản đã thanh lý không có trong bảng tính
        $preview = $this->getJson('/api/v1/fixed-assets/depreciation/preview?month=2026-09');
        $preview->assertStatus(200);

        $lines = collect($preview->json('lines'));
        $this->assertNull($lines->firstWhere('asset_code', 'TS-DISP-01'));
    }
}
