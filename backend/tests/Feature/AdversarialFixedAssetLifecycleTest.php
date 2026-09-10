<?php

namespace Tests\Feature;

use App\Models\AssetDisposal;
use App\Models\AssetRevaluation;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdversarialFixedAssetLifecycleTest extends TestCase
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
            'name' => 'Công ty TNHH Adversarial FA Testing',
            'tax_code' => '0109999999',
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
     * Challenge Scenario 1: Asset Creation with Diverse Credit Accounts & Strict GL Balance
     */
    public function test_asset_creation_with_various_credit_accounts_guarantees_double_entry_balance()
    {
        $testCases = [
            ['code' => 'TS-AP-01', 'credit' => '331', 'cost' => 50000000],
            ['code' => 'TS-CASH-01', 'credit' => '1111', 'cost' => 25000000],
            ['code' => 'TS-BANK-01', 'credit' => '1121', 'cost' => 150000000],
        ];

        foreach ($testCases as $tc) {
            $payload = [
                'company_id' => $this->company->id,
                'asset_code' => $tc['code'],
                'asset_name' => 'Test Asset '.$tc['code'],
                'department_code' => 'QLDN',
                'purchase_date' => '2026-08-01',
                'original_cost' => $tc['cost'],
                'useful_life_months' => 24,
                'asset_account' => '211',
                'credit_account' => $tc['credit'],
                'is_posted' => true,
            ];

            $res = $this->postJson('/api/v1/fixed-assets', $payload);
            $res->assertStatus(201);

            $asset = FixedAsset::where('asset_code', $tc['code'])->first();
            $this->assertNotNull($asset->journal_entry_id);

            $je = JournalEntry::with('lines')->find($asset->journal_entry_id);
            $this->assertNotNull($je);
            $this->assertEquals('posted', $je->status);

            $sumDebit = $je->lines->sum('debit_amount');
            $sumCredit = $je->lines->sum('credit_amount');
            $this->assertEquals($tc['cost'], $sumDebit);
            $this->assertEquals($tc['cost'], $sumCredit);
            $this->assertEquals($sumDebit, $sumCredit);

            $this->assertEquals($tc['cost'], $je->lines->where('account_code', '211')->sum('debit_amount'));
            $this->assertEquals($tc['cost'], $je->lines->where('account_code', $tc['credit'])->sum('credit_amount'));
        }
    }

    /**
     * Challenge Scenario 2: Zero Depreciation Disposal (Brand New Asset Write-off)
     * Debit 811 (100%) = Credit 211 (100%)
     */
    public function test_disposal_with_zero_accumulated_depreciation_balances_perfectly()
    {
        $asset = FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-NEW',
            'voucher_date' => '2026-08-01',
            'asset_code' => 'TS-NEW-01',
            'asset_name' => 'Máy thí nghiệm mới mua bị hỏng',
            'department_code' => 'SAN_XUAT',
            'purchase_date' => '2026-08-01',
            'original_cost' => 80000000,
            'depreciable_cost' => 80000000,
            'useful_life_months' => 36,
            'accumulated_depreciation' => 0,
            'net_value' => 80000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '154',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        $res = $this->postJson("/api/v1/fixed-assets/{$asset->id}/dispose", [
            'voucher_date' => '2026-08-15',
            'disposal_type' => 'lost',
            'disposal_reason' => 'Hư hỏng không thể sửa chữa',
            'disposal_price' => 0,
        ]);

        $res->assertStatus(201);

        $asset->refresh();
        $this->assertEquals('disposed', $asset->status);
        $this->assertFalse($asset->is_active);

        $disposal = AssetDisposal::where('fixed_asset_id', $asset->id)->first();
        $this->assertNotNull($disposal->journal_entry_id);

        $je = JournalEntry::with('lines')->find($disposal->journal_entry_id);
        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        $this->assertEquals(80000000, $sumDebit);
        $this->assertEquals(80000000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);

        // Nợ 811 = 80M, Có 211 = 80M, Nợ 2141 = 0
        $this->assertEquals(80000000, $je->lines->where('account_code', '811')->sum('debit_amount'));
        $this->assertEquals(80000000, $je->lines->where('account_code', '211')->sum('credit_amount'));
        $this->assertEquals(0, $je->lines->where('account_code', '2141')->sum('debit_amount'));
    }

    /**
     * Challenge Scenario 3: Fully Depreciated Asset Disposal (Net Value = 0)
     * Debit 2141 (100%) = Credit 211 (100%)
     */
    public function test_disposal_with_fully_depreciated_asset_balances_perfectly()
    {
        $asset = FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-FULL',
            'voucher_date' => '2020-01-01',
            'asset_code' => 'TS-FULL-01',
            'asset_name' => 'Máy tính cũ hết hạn sử dụng',
            'department_code' => 'QLDN',
            'purchase_date' => '2020-01-01',
            'original_cost' => 45000000,
            'depreciable_cost' => 45000000,
            'useful_life_months' => 36,
            'accumulated_depreciation' => 45000000,
            'net_value' => 0,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6424',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'fully_depreciated',
        ]);

        $res = $this->postJson("/api/v1/fixed-assets/{$asset->id}/dispose", [
            'voucher_date' => '2026-08-20',
            'disposal_type' => 'liquidation',
            'disposal_reason' => 'Thanh lý phế liệu',
            'disposal_price' => 2000000,
            'payment_method' => 'cash',
        ]);

        $res->assertStatus(201);

        $disposal = AssetDisposal::where('fixed_asset_id', $asset->id)->first();
        $je = JournalEntry::with('lines')->find($disposal->journal_entry_id);

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        // Total Debit = 45M (2141) + 2M (1111) = 47M
        // Total Credit = 45M (211) + 2M (711) = 47M
        $this->assertEquals(47000000, $sumDebit);
        $this->assertEquals(47000000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);

        $this->assertEquals(45000000, $je->lines->where('account_code', '2141')->sum('debit_amount'));
        $this->assertEquals(45000000, $je->lines->where('account_code', '211')->sum('credit_amount'));
        $this->assertEquals(0, $je->lines->where('account_code', '811')->sum('debit_amount'));
        $this->assertEquals(2000000, $je->lines->where('account_code', '1111')->sum('debit_amount'));
        $this->assertEquals(2000000, $je->lines->where('account_code', '711')->sum('credit_amount'));
    }

    /**
     * Challenge Scenario 4: Revaluation Decrease (Debit 412 / Credit 211)
     */
    public function test_revaluation_decrease_generates_account_412_debit()
    {
        $asset = FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-DOWN',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-DOWN-01',
            'asset_name' => 'Dây chuyền sản xuất suy giảm giá trị',
            'department_code' => 'SAN_XUAT',
            'purchase_date' => '2026-01-01',
            'original_cost' => 200000000,
            'depreciable_cost' => 200000000,
            'useful_life_months' => 40,
            'monthly_depreciation' => 5000000,
            'accumulated_depreciation' => 20000000,
            'net_value' => 180000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '154',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Đánh giá giảm nguyên giá từ 200M xuống 160M (-40M)
        $res = $this->postJson("/api/v1/fixed-assets/{$asset->id}/revalue", [
            'voucher_date' => '2026-08-20',
            'new_original_cost' => 160000000,
            'new_useful_life_months' => 32,
            'reason' => 'Điều chỉnh giảm theo kết luận hội đồng định giá',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.cost_difference', '-40000000.00');

        $asset->refresh();
        $this->assertEquals(160000000, (float) $asset->original_cost);
        $this->assertEquals(140000000, (float) $asset->net_value); // 160M - 20M = 140M
        $this->assertEquals(5000000, (float) $asset->monthly_depreciation); // 160M / 32 = 5M

        $reval = AssetRevaluation::where('fixed_asset_id', $asset->id)->first();
        $je = JournalEntry::with('lines')->find($reval->journal_entry_id);

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        $this->assertEquals(40000000, $sumDebit);
        $this->assertEquals(40000000, $sumCredit);
        $this->assertEquals($sumDebit, $sumCredit);

        // Nợ 412 (40M) / Có 211 (40M)
        $this->assertEquals(40000000, $je->lines->where('account_code', '412')->sum('debit_amount'));
        $this->assertEquals(40000000, $je->lines->where('account_code', '211')->sum('credit_amount'));
    }

    /**
     * Challenge Scenario 5: Direct Cost Modification Protection When Depreciated
     */
    public function test_cannot_directly_modify_original_cost_after_depreciation_has_occurred()
    {
        $asset = FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-PROTECT',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-PROTECT-01',
            'asset_name' => 'Máy in công nghiệp',
            'department_code' => 'QLDN',
            'purchase_date' => '2026-01-01',
            'start_depreciation_date' => '2026-01-01',
            'original_cost' => 50000000,
            'depreciable_cost' => 50000000,
            'useful_life_months' => 10,
            'monthly_depreciation' => 5000000,
            'accumulated_depreciation' => 0,
            'net_value' => 50000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6424',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Chạy khấu hao 1 kỳ
        $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->company->id,
            'month' => '2026-01',
        ]);

        // Thử sửa nguyên giá trực tiếp qua PUT -> Phải bị chặn
        $res = $this->putJson("/api/v1/fixed-assets/{$asset->id}", [
            'original_cost' => 70000000,
        ]);

        $res->assertStatus(400);
    }

    /**
     * Challenge Scenario 6: Sequential Rollback Protection For Depreciation Logs
     */
    public function test_sequential_rollback_protection_blocks_unposting_older_period_first()
    {
        FixedAsset::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'TSCD-2026-SEQ',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-SEQ-01',
            'asset_name' => 'Server máy chủ',
            'department_code' => 'QLDN',
            'purchase_date' => '2026-01-01',
            'start_depreciation_date' => '2026-01-01',
            'original_cost' => 120000000,
            'depreciable_cost' => 120000000,
            'useful_life_months' => 24,
            'monthly_depreciation' => 5000000,
            'accumulated_depreciation' => 0,
            'net_value' => 120000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6424',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Chạy khấu hao tháng 01/2026
        $res1 = $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->company->id,
            'month' => '2026-01',
        ]);
        $log1Id = $res1->json('data.id');

        // Chạy khấu hao tháng 02/2026
        $res2 = $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->company->id,
            'month' => '2026-02',
        ]);
        $log2Id = $res2->json('data.id');

        // Thử hủy tháng 01/2026 trong khi tháng 02/2026 vẫn đang ghi sổ -> Phải bị chặn
        $unpost1 = $this->postJson("/api/v1/fixed-assets/depreciation/{$log1Id}/unpost");
        $unpost1->assertStatus(400);

        // Hủy tuần tự tháng 02/2026 trước -> Thành công
        $unpost2 = $this->postJson("/api/v1/fixed-assets/depreciation/{$log2Id}/unpost");
        $unpost2->assertStatus(200);

        // Sau đó hủy tháng 01/2026 -> Thành công
        $unpost1After = $this->postJson("/api/v1/fixed-assets/depreciation/{$log1Id}/unpost");
        $unpost1After->assertStatus(200);
    }
}
