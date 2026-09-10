<?php

namespace Tests\Feature;

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

class DepreciationGLRollbackChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $companyA;

    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Công ty Cổ phần Thử Nghiệm M3 Alpha',
                'tax_code' => '0108889991',
                'address' => 'Hà Nội, Việt Nam',
                'is_active' => true,
            ]
        );

        $this->companyB = Company::create([
            'name' => 'Công ty TNHH Thử Nghiệm M3 Beta',
            'tax_code' => '0108889992',
            'address' => 'TP. Hồ Chí Minh, Việt Nam',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Kế toán trưởng Kiểm định Khấu hao',
            'email' => 'depreciation_challenger_'.uniqid().'@example.com',
        ]);
        Sanctum::actingAs($this->user);

        FiscalYear::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyB->id, 'year' => 2026],
            [
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'open',
            ]
        );

        // Tạo hệ thống tài khoản kế toán chuẩn VAS TT200 cho cả 2 công ty
        $accounts = [
            ['code' => '211', 'name' => 'Tài sản cố định hữu hình', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '2141', 'name' => 'Hao mòn TSCĐ hữu hình', 'type' => 'asset', 'nature' => 'credit'],
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '1111', 'name' => 'Tiền Việt Nam', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '6424', 'name' => 'Chi phí khấu hao TSCĐ QLDN', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '6414', 'name' => 'Chi phí khấu hao TSCĐ Bán hàng', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '154', 'name' => 'Chi phí SXKD dở dang', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '6274', 'name' => 'Chi phí khấu hao TSCĐ Phân xưởng', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '412', 'name' => 'Chênh lệch đánh giá lại tài sản', 'type' => 'equity', 'nature' => 'credit'],
        ];

        foreach ([$this->companyA->id, $this->companyB->id] as $cId) {
            foreach ($accounts as $acc) {
                ChartOfAccount::firstOrCreate(
                    [
                        'company_id' => $cId,
                        'code' => $acc['code'],
                    ],
                    array_merge($acc, [
                        'company_id' => $cId,
                        'is_active' => true,
                        'is_parent' => false,
                        'level' => 1,
                    ])
                );
            }
        }
    }

    /**
     * 1. Multi-Department Cost Allocation & Exact GL Double-Entry Balance Check
     * Test single & multiple assets across QLDN (6424), Bán hàng (6414), Sản xuất (154), Custom (6274), đối ứng Có 2141.
     * Ensure sum Debit = sum Credit down to the penny.
     */
    public function test_multi_department_cost_allocation_and_exact_gl_balance(): void
    {
        // Asset 1: QLDN (6424) -> 120M / 12 months = 10,000,000 / month
        $a1 = FixedAsset::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'TSCD-QLDN-01',
            'voucher_date' => '2026-08-01',
            'asset_code' => 'TS-QLDN-01',
            'asset_name' => 'Máy chủ trung tâm dữ liệu',
            'department_code' => 'QLDN',
            'purchase_date' => '2026-08-01',
            'start_depreciation_date' => '2026-08-01',
            'original_cost' => 120000000,
            'depreciable_cost' => 120000000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 10000000,
            'accumulated_depreciation' => 0,
            'net_value' => 120000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6424',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Asset 2: Bán hàng (6414) -> 60M / 12 months = 5,000,000 / month
        $a2 = FixedAsset::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'TSCD-BH-01',
            'voucher_date' => '2026-08-01',
            'asset_code' => 'TS-BH-01',
            'asset_name' => 'Hệ thống quầy thanh toán POS',
            'department_code' => 'BAN_HANG',
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
            'expense_account' => '6414',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Asset 3: Sản xuất (154) -> 240M / 12 months = 20,000,000 / month
        $a3 = FixedAsset::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'TSCD-SX-01',
            'voucher_date' => '2026-08-01',
            'asset_code' => 'TS-SX-01',
            'asset_name' => 'Máy CNC gia công cơ khí',
            'department_code' => 'SAN_XUAT',
            'purchase_date' => '2026-08-01',
            'start_depreciation_date' => '2026-08-01',
            'original_cost' => 240000000,
            'depreciable_cost' => 240000000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 20000000,
            'accumulated_depreciation' => 0,
            'net_value' => 240000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '154',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Asset 4: Custom explicit account 6274 (Phân xưởng) -> 120M / 12 months = 10,000,000 / month
        $a4 = FixedAsset::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'TSCD-PX-01',
            'voucher_date' => '2026-08-01',
            'asset_code' => 'TS-PX-01',
            'asset_name' => 'Hệ thống hút bụi công nghiệp',
            'department_code' => 'PHAN_XUONG',
            'purchase_date' => '2026-08-01',
            'start_depreciation_date' => '2026-08-01',
            'original_cost' => 120000000,
            'depreciable_cost' => 120000000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 10000000,
            'accumulated_depreciation' => 0,
            'net_value' => 120000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6274',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Preview
        $previewRes = $this->getJson('/api/v1/fixed-assets/depreciation/preview?month=2026-08');
        $previewRes->assertStatus(200)
            ->assertJsonPath('total_assets', 4)
            ->assertJsonPath('total_amount', 45000000);

        // Run Depreciation
        $runRes = $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->companyA->id,
            'month' => '2026-08',
            'description' => 'Trích khấu hao tháng 08/2026 - Kiểm thử 4 bộ phận',
        ]);
        $runRes->assertStatus(200)
            ->assertJsonPath('data.total_amount', '45000000.00')
            ->assertJsonPath('data.is_posted', true);

        // Check Log & Lines
        $log = DepreciationLog::with('lines')->where('month', '2026-08')->first();
        $this->assertNotNull($log);
        $this->assertCount(4, $log->lines);
        $this->assertNotNull($log->journal_entry_id);

        // Check Journal Entry Balancing
        $je = JournalEntry::with('lines')->find($log->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertEquals('posted', $je->status);

        $totalDebit = $je->lines->sum('debit_amount');
        $totalCredit = $je->lines->sum('credit_amount');
        $this->assertEquals(45000000, $totalDebit);
        $this->assertEquals(45000000, $totalCredit);
        $this->assertEquals($totalDebit, $totalCredit);

        // Verify account distribution
        $this->assertEquals(10000000, $je->lines->where('account_code', '6424')->sum('debit_amount'));
        $this->assertEquals(5000000, $je->lines->where('account_code', '6414')->sum('debit_amount'));
        $this->assertEquals(20000000, $je->lines->where('account_code', '154')->sum('debit_amount'));
        $this->assertEquals(10000000, $je->lines->where('account_code', '6274')->sum('debit_amount'));
        $this->assertEquals(45000000, $je->lines->where('account_code', '2141')->sum('credit_amount'));
    }

    /**
     * 2. Sequential Rollback Protection (LIFO Reverse Order Enforcement)
     * Posting Month 1, Month 2, Month 3.
     * Attempting to unpost Month 1 when Month 2/3 are posted MUST FAIL with 400.
     * Attempting to unpost Month 2 when Month 3 is posted MUST FAIL with 400.
     * Unposting Month 3 succeeds -> then Month 2 succeeds -> then Month 1 succeeds.
     * Restores asset accumulated depreciation and net value exactly.
     */
    public function test_sequential_rollback_protection_enforces_lifo_unpost_order(): void
    {
        $asset = FixedAsset::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'TSCD-ROLLBACK-01',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-ROLLBACK-01',
            'asset_name' => 'Máy nén khí cao áp',
            'department_code' => 'SAN_XUAT',
            'purchase_date' => '2026-01-01',
            'start_depreciation_date' => '2026-01-01',
            'original_cost' => 120000000,
            'depreciable_cost' => 120000000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 10000000,
            'accumulated_depreciation' => 0,
            'net_value' => 120000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '154',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Step 1: Run Month 1 (2026-01)
        $run1 = $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->companyA->id,
            'month' => '2026-01',
        ]);
        $run1->assertStatus(200);
        $log1Id = $run1->json('data.id');

        $asset->refresh();
        $this->assertEquals(10000000, (float) $asset->accumulated_depreciation);
        $this->assertEquals(110000000, (float) $asset->net_value);

        // Step 2: Run Month 2 (2026-02)
        $run2 = $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->companyA->id,
            'month' => '2026-02',
        ]);
        $run2->assertStatus(200);
        $log2Id = $run2->json('data.id');

        $asset->refresh();
        $this->assertEquals(20000000, (float) $asset->accumulated_depreciation);
        $this->assertEquals(100000000, (float) $asset->net_value);

        // Step 3: Run Month 3 (2026-03)
        $run3 = $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->companyA->id,
            'month' => '2026-03',
        ]);
        $run3->assertStatus(200);
        $log3Id = $run3->json('data.id');

        $asset->refresh();
        $this->assertEquals(30000000, (float) $asset->accumulated_depreciation);
        $this->assertEquals(90000000, (float) $asset->net_value);

        // Step 4: Adversarial Attack - Attempt to unpost Month 1 while Month 2 & 3 are posted -> MUST BE REJECTED
        $illegalUnpost1 = $this->postJson("/api/v1/fixed-assets/depreciation/{$log1Id}/unpost");
        $illegalUnpost1->assertStatus(400)
            ->assertJsonFragment(['error' => 'Không thể hủy khấu hao tháng 2026-01 vì các tháng tiếp theo đã được trích khấu hao. Hãy hủy khấu hao từ tháng mới nhất trở về trước.']);

        // Step 5: Adversarial Attack - Attempt to unpost Month 2 while Month 3 is posted -> MUST BE REJECTED
        $illegalUnpost2 = $this->postJson("/api/v1/fixed-assets/depreciation/{$log2Id}/unpost");
        $illegalUnpost2->assertStatus(400)
            ->assertJsonFragment(['error' => 'Không thể hủy khấu hao tháng 2026-02 vì các tháng tiếp theo đã được trích khấu hao. Hãy hủy khấu hao từ tháng mới nhất trở về trước.']);

        // Verify values are NOT corrupted by rejected unpost attempts
        $asset->refresh();
        $this->assertEquals(30000000, (float) $asset->accumulated_depreciation);
        $this->assertEquals(90000000, (float) $asset->net_value);

        // Step 6: Legitimate Rollback - Unpost Month 3 (latest) -> SUCCEEDS
        $legalUnpost3 = $this->postJson("/api/v1/fixed-assets/depreciation/{$log3Id}/unpost");
        $legalUnpost3->assertStatus(200)
            ->assertJsonPath('data.is_posted', false);

        $asset->refresh();
        $this->assertEquals(20000000, (float) $asset->accumulated_depreciation);
        $this->assertEquals(100000000, (float) $asset->net_value);

        $log3 = DepreciationLog::find($log3Id);
        $this->assertFalse($log3->is_posted);
        $je3 = JournalEntry::find($log3->journal_entry_id);
        $this->assertEquals('voided', $je3->status);

        // Step 7: Attempting Month 1 again should STILL FAIL because Month 2 is still posted
        $illegalUnpost1Again = $this->postJson("/api/v1/fixed-assets/depreciation/{$log1Id}/unpost");
        $illegalUnpost1Again->assertStatus(400);

        // Step 8: Unpost Month 2 -> SUCCEEDS
        $legalUnpost2 = $this->postJson("/api/v1/fixed-assets/depreciation/{$log2Id}/unpost");
        $legalUnpost2->assertStatus(200)
            ->assertJsonPath('data.is_posted', false);

        $asset->refresh();
        $this->assertEquals(10000000, (float) $asset->accumulated_depreciation);
        $this->assertEquals(110000000, (float) $asset->net_value);

        // Step 9: Unpost Month 1 -> SUCCEEDS
        $legalUnpost1 = $this->postJson("/api/v1/fixed-assets/depreciation/{$log1Id}/unpost");
        $legalUnpost1->assertStatus(200)
            ->assertJsonPath('data.is_posted', false);

        $asset->refresh();
        $this->assertEquals(0, (float) $asset->accumulated_depreciation);
        $this->assertEquals(120000000, (float) $asset->net_value);
    }

    /**
     * 3. Fully Depreciated Asset Handling & Final Month Penny-Clamping
     * Asset with original_cost = 10,000,000, useful_life = 3 months.
     * Monthly = round(10,000,000 / 3) = 3,333,333.
     * M1: 3,333,333 -> Net = 6,666,667
     * M2: 3,333,333 -> Net = 3,333,334
     * M3: 3,333,333 -> Net = 1
     * M4: Clamps to remaining 1 VND -> Net = 0, status = fully_depreciated.
     * M5: MUST NOT be depreciated anymore!
     */
    public function test_fully_depreciated_asset_handling_and_final_month_clamping(): void
    {
        $asset = FixedAsset::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'TSCD-CLAMP-01',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-CLAMP-01',
            'asset_name' => 'Máy in thẻ nhựa văn phòng',
            'department_code' => 'QLDN',
            'purchase_date' => '2026-01-01',
            'start_depreciation_date' => '2026-01-01',
            'original_cost' => 10000000,
            'depreciable_cost' => 10000000,
            'useful_life_months' => 3,
            'monthly_depreciation' => 3333333,
            'accumulated_depreciation' => 0,
            'net_value' => 10000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6424',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Month 1
        $this->postJson('/api/v1/fixed-assets/depreciation/run', ['company_id' => $this->companyA->id, 'month' => '2026-01'])->assertStatus(200);
        $asset->refresh();
        $this->assertEquals(3333333, (float) $asset->accumulated_depreciation);
        $this->assertEquals(6666667, (float) $asset->net_value);

        // Month 2
        $this->postJson('/api/v1/fixed-assets/depreciation/run', ['company_id' => $this->companyA->id, 'month' => '2026-02'])->assertStatus(200);
        $asset->refresh();
        $this->assertEquals(6666666, (float) $asset->accumulated_depreciation);
        $this->assertEquals(3333334, (float) $asset->net_value);

        // Month 3
        $this->postJson('/api/v1/fixed-assets/depreciation/run', ['company_id' => $this->companyA->id, 'month' => '2026-03'])->assertStatus(200);
        $asset->refresh();
        $this->assertEquals(9999999, (float) $asset->accumulated_depreciation);
        $this->assertEquals(1, (float) $asset->net_value);

        // Month 4: Net value is 1 VND < monthly_depreciation (3,333,333). Clamps to 1 VND!
        $preview4 = $this->getJson('/api/v1/fixed-assets/depreciation/preview?month=2026-04');
        $preview4->assertStatus(200)
            ->assertJsonPath('total_amount', 1);

        $run4 = $this->postJson('/api/v1/fixed-assets/depreciation/run', ['company_id' => $this->companyA->id, 'month' => '2026-04']);
        $run4->assertStatus(200)
            ->assertJsonPath('data.total_amount', '1.00');

        $asset->refresh();
        $this->assertEquals(10000000, (float) $asset->accumulated_depreciation);
        $this->assertEquals(0, (float) $asset->net_value);
        $this->assertEquals('fully_depreciated', $asset->status);
        $this->assertTrue($asset->isFullyDepreciated());

        // Month 5: Asset is fully depreciated, preview should be empty
        $preview5 = $this->getJson('/api/v1/fixed-assets/depreciation/preview?month=2026-05');
        $preview5->assertStatus(200)
            ->assertJsonPath('total_assets', 0)
            ->assertJsonPath('total_amount', 0);

        // Attempting to run Month 5 should return 400 (No assets to depreciate)
        $run5 = $this->postJson('/api/v1/fixed-assets/depreciation/run', ['company_id' => $this->companyA->id, 'month' => '2026-05']);
        $run5->assertStatus(400)
            ->assertJsonFragment(['error' => 'Không có tài sản nào cần trích khấu hao trong tháng 2026-05.']);
    }

    /**
     * 4. Multi-Company Isolation for Depreciation & Rollback
     * Depreciation logs and rollback checks in Company A must not interfere with Company B.
     */
    public function test_multi_company_isolation_for_depreciation_and_rollback(): void
    {
        // Company A asset
        $assetA = FixedAsset::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'TSCD-COMP-A',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-COMP-A',
            'asset_name' => 'Máy chủ Công ty A',
            'department_code' => 'QLDN',
            'purchase_date' => '2026-01-01',
            'start_depreciation_date' => '2026-01-01',
            'original_cost' => 12000000,
            'depreciable_cost' => 12000000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 1000000,
            'accumulated_depreciation' => 0,
            'net_value' => 12000000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '6424',
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Company B asset
        $assetB = FixedAsset::create([
            'company_id' => $this->companyB->id,
            'voucher_number' => 'TSCD-COMP-B',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-COMP-B',
            'asset_name' => 'Máy chủ Công ty B',
            'department_code' => 'QLDN',
            'purchase_date' => '2026-01-01',
            'start_depreciation_date' => '2026-01-01',
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
            'is_posted' => true,
            'status' => 'posted',
        ]);

        // Company A runs 2026-01 and 2026-02
        $runA1 = $this->postJson('/api/v1/fixed-assets/depreciation/run', ['company_id' => $this->companyA->id, 'month' => '2026-01'])->assertStatus(200);
        $runA2 = $this->postJson('/api/v1/fixed-assets/depreciation/run', ['company_id' => $this->companyA->id, 'month' => '2026-02'])->assertStatus(200);

        // A malicious tenant switch in the payload must not run Company B.
        $this->postJson('/api/v1/fixed-assets/depreciation/run', [
            'company_id' => $this->companyB->id,
            'month' => '2026-01',
        ])->assertStatus(400);
        $this->assertDatabaseMissing('asset_depreciation_logs', [
            'company_id' => $this->companyB->id,
            'month' => '2026-01',
        ]);

        // Company B runs ONLY 2026-01 under a Company B principal.
        $userB = User::factory()->create(['company_id' => $this->companyB->id]);
        Sanctum::actingAs($userB);
        $runB1 = $this->postJson('/api/v1/fixed-assets/depreciation/run', ['company_id' => $this->companyB->id, 'month' => '2026-01'])->assertStatus(200);
        $logB1Id = $runB1->json('data.id');

        // Unposting Company B's 2026-01 must SUCCEED because Company B has no 2026-02 posted (even though Company A has 2026-02 posted)
        $unpostB1 = $this->postJson("/api/v1/fixed-assets/depreciation/{$logB1Id}/unpost");
        $unpostB1->assertStatus(200)
            ->assertJsonPath('data.is_posted', false);

        $assetB->refresh();
        $this->assertEquals(0, (float) $assetB->accumulated_depreciation);
        $this->assertEquals(24000000, (float) $assetB->net_value);

        // Company A's 2026-01 unpost MUST still be blocked by Company A's 2026-02
        Sanctum::actingAs($this->user);
        $logA1Id = $runA1->json('data.id');
        $unpostA1 = $this->postJson("/api/v1/fixed-assets/depreciation/{$logA1Id}/unpost");
        $unpostA1->assertStatus(400);
    }

    public function test_foreign_asset_update_and_depreciation_unpost_are_rejected(): void
    {
        $assetB = FixedAsset::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'asset_code' => 'TS-B-OWNERSHIP',
            'asset_name' => 'Original Company B Asset',
            'purchase_date' => '2026-01-01',
            'start_depreciation_date' => '2026-01-01',
            'original_cost' => 24000000,
            'depreciable_cost' => 24000000,
            'useful_life_months' => 12,
            'monthly_depreciation' => 2000000,
            'accumulated_depreciation' => 2000000,
            'net_value' => 22000000,
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $logB = DepreciationLog::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'voucher_number' => 'KHTS-B-OWNERSHIP',
            'voucher_date' => '2026-01-31',
            'accounting_date' => '2026-01-31',
            'month' => '2026-01',
            'description' => 'Company B depreciation',
            'total_amount' => 2000000,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        $this->putJson("/api/v1/fixed-assets/{$assetB->id}", [
            'company_id' => $this->companyB->id,
            'asset_name' => 'Cross-tenant overwrite',
        ])->assertStatus(404);
        $this->postJson("/api/v1/fixed-assets/depreciation/{$logB->id}/unpost", [
            'company_id' => $this->companyB->id,
        ])->assertStatus(404);

        $this->assertSame(
            'Original Company B Asset',
            FixedAsset::withoutGlobalScopes()->findOrFail($assetB->id)->asset_name
        );
        $this->assertTrue(
            DepreciationLog::withoutGlobalScopes()->findOrFail($logB->id)->is_posted
        );
    }

    /**
     * 5. Asset Lock Protection: Cannot change original cost, unpost, or delete asset with active depreciation
     */
    public function test_cannot_modify_cost_or_unpost_or_delete_asset_with_depreciation(): void
    {
        $asset = FixedAsset::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'TSCD-LOCK-01',
            'voucher_date' => '2026-01-01',
            'asset_code' => 'TS-LOCK-01',
            'asset_name' => 'Máy phát điện dự phòng',
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

        // Run depreciation
        $this->postJson('/api/v1/fixed-assets/depreciation/run', ['company_id' => $this->companyA->id, 'month' => '2026-01'])->assertStatus(200);

        // 1. Attempt to update original_cost directly -> MUST FAIL with 400
        $updateCostRes = $this->putJson("/api/v1/fixed-assets/{$asset->id}", [
            'original_cost' => 60000000,
        ]);
        $updateCostRes->assertStatus(400)
            ->assertJsonFragment(['error' => 'Không thể thay đổi nguyên giá tài sản đã phát sinh khấu hao. Vui lòng sử dụng chức năng Đánh giá lại TSCĐ.']);

        // 2. Attempt to unpost asset -> MUST FAIL with 400
        $unpostAssetRes = $this->postJson("/api/v1/fixed-assets/{$asset->id}/unpost");
        $unpostAssetRes->assertStatus(400)
            ->assertJsonFragment(['error' => 'Không thể bỏ ghi sổ tài sản đã phát sinh khấu hao. Hãy hủy các chứng từ khấu hao liên quan trước.']);

        // 3. Attempt to delete asset -> MUST FAIL with 400
        $deleteAssetRes = $this->deleteJson("/api/v1/fixed-assets/{$asset->id}");
        $deleteAssetRes->assertStatus(400)
            ->assertJsonFragment(['error' => 'Không thể xóa tài sản đã phát sinh khấu hao.']);
    }
}
