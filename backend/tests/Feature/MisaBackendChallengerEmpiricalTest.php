<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherTypeSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Empirical Adversarial Test Suite for Backend MISA AMIS Upgrade
 * Challenger 1 (Backend Challenger)
 */
class MisaBackendChallengerEmpiricalTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $companyA;

    protected Company $companyB;

    protected BankAccount $bankAccountA;

    protected BankAccount $bankAccountB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->companyA = Company::create([
            'name' => 'Công ty Cổ phần MISA AMIS Test A',
            'tax_code' => '0101243150',
            'address' => 'Hà Nội',
        ]);

        $this->companyB = Company::create([
            'name' => 'Công ty TNHH Đối thủ B',
            'tax_code' => '0301243999',
            'address' => 'TP Hồ Chí Minh',
        ]);
        $this->configureAccountingTenant($this->user, $this->companyA);
        // Lifecycle posting is guarded by the canonical two-role policy. Use
        // a real accountant fixture rather than bypassing authorization with
        // a roleless legacy user.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);
        FiscalYear::firstOrCreate(
            ['company_id' => $this->companyB->id, 'year' => 2026],
            ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']
        );

        $this->bankAccountA = BankAccount::create([
            'company_id' => $this->companyA->id,
            'account_number' => '1903666888999',
            'bank_name' => 'Vietcombank Ba Đình',
            'gl_account_code' => '1121',
        ]);

        $this->bankAccountB = BankAccount::create([
            'company_id' => $this->companyB->id,
            'account_number' => '1903666111222',
            'bank_name' => 'Techcombank Sài Gòn',
            'gl_account_code' => '1121',
        ]);

        // Seed Standard Accounts for Company A & B
        foreach ([$this->companyA->id, $this->companyB->id] as $cid) {
            ChartOfAccount::create(['company_id' => $cid, 'code' => '1111', 'name' => 'Tiền Việt Nam', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
            ChartOfAccount::create(['company_id' => $cid, 'code' => '1121', 'name' => 'Tiền gửi ngân hàng VND', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
            ChartOfAccount::create(['company_id' => $cid, 'code' => '131', 'name' => 'Phải thu của khách hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
            ChartOfAccount::create(['company_id' => $cid, 'code' => '141', 'name' => 'Tạm ứng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
            ChartOfAccount::create(['company_id' => $cid, 'code' => '331', 'name' => 'Phải trả cho người bán', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0]);
            ChartOfAccount::create(['company_id' => $cid, 'code' => '511', 'name' => 'Doanh thu bán hàng', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0]);
            ChartOfAccount::create(['company_id' => $cid, 'code' => '642', 'name' => 'Chi phí quản lý doanh nghiệp', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
            ChartOfAccount::create(['company_id' => $cid, 'code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0]);
            ChartOfAccount::create(['company_id' => $cid, 'code' => '811', 'name' => 'Chi phí khác', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        }
    }

    // =========================================================================
    // SECTION 1: VoucherTypeSetting Full CRUD, Validation & Multi-Tenant Fallback
    // =========================================================================

    public function test_voucher_type_settings_full_crud_lifecycle_and_soft_delete(): void
    {
        // 1. Types endpoint
        $typesRes = $this->getJson('/api/v1/master/voucher-type-settings/types');
        $typesRes->assertStatus(200);
        $this->assertCount(5, $typesRes->json('data'));
        $values = collect($typesRes->json('data'))->pluck('value')->all();
        $this->assertEquals(['thu_tien_mat', 'thu_tien_gui', 'chi_tien_mat', 'chi_tien_gui', 'chung_tu_khac'], $values);

        // 2. Create custom setting for Company A
        $createPayload = [
            'company_id' => $this->companyA->id,
            'voucher_type' => 'thu_tien_mat',
            'name' => 'Thu tiền thanh lý TSCĐ',
            'debit_account' => '1111',
            'credit_account' => '711',
            'filter_debit' => '111',
            'filter_credit' => '711',
            'is_active' => true,
        ];
        $storeRes = $this->postJson('/api/v1/master/voucher-type-settings', $createPayload);
        $storeRes->assertStatus(201)
            ->assertJsonPath('data.name', 'Thu tiền thanh lý TSCĐ');
        $id = $storeRes->json('data.id');
        $this->assertNotNull($id);

        // 3. Show
        $showRes = $this->getJson("/api/v1/master/voucher-type-settings/{$id}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Thu tiền thanh lý TSCĐ');

        // 4. Update
        $updatePayload = [
            'name' => 'Thu tiền thanh lý TSCĐ (Đã sửa)',
            'credit_account' => '711',
            'is_active' => true,
        ];
        $updateRes = $this->putJson("/api/v1/master/voucher-type-settings/{$id}", $updatePayload);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Thu tiền thanh lý TSCĐ (Đã sửa)');

        // 5. Index when custom records exist: should NOT return system defaults
        $indexRes = $this->getJson("/api/v1/master/voucher-type-settings?voucher_type=thu_tien_mat&company_id={$this->companyA->id}");
        $indexRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Thu tiền thanh lý TSCĐ (Đã sửa)');
        $this->assertNull($indexRes->json('meta.is_system_defaults'));

        // 6. Delete (Soft delete)
        $delRes = $this->deleteJson("/api/v1/master/voucher-type-settings/{$id}");
        $delRes->assertStatus(200);

        $this->assertSoftDeleted('voucher_type_settings', ['id' => $id]);

        // 7. After deletion, querying again should fallback to system defaults
        $fallbackRes = $this->getJson("/api/v1/master/voucher-type-settings?voucher_type=thu_tien_mat&company_id={$this->companyA->id}");
        $fallbackRes->assertStatus(200)
            ->assertJsonPath('meta.is_system_defaults', true)
            ->assertJsonCount(5, 'data');
    }

    public function test_voucher_type_settings_company_isolation(): void
    {
        // Company A creates a custom setting
        $this->postJson('/api/v1/master/voucher-type-settings', [
            'company_id' => $this->companyA->id,
            'voucher_type' => 'chi_tien_mat',
            'name' => 'Chi tiếp khách Company A',
            'debit_account' => '642',
            'credit_account' => '1111',
            'is_active' => true,
        ])->assertStatus(201);

        // Company A query gets custom setting
        $resA = $this->getJson("/api/v1/master/voucher-type-settings?voucher_type=chi_tien_mat&company_id={$this->companyA->id}");
        $resA->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Chi tiếp khách Company A');

        // Client-controlled company_id must not switch the authenticated tenant.
        $resB = $this->getJson("/api/v1/master/voucher-type-settings?voucher_type=chi_tien_mat&company_id={$this->companyB->id}");
        $resB->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Chi tiếp khách Company A');
    }

    public function test_voucher_type_settings_validation_errors(): void
    {
        // Invalid voucher_type enum
        $res = $this->postJson('/api/v1/master/voucher-type-settings', [
            'company_id' => $this->companyA->id,
            'voucher_type' => 'invalid_type_123',
            'name' => 'Test Invalid',
            'debit_account' => '1111',
            'credit_account' => '131',
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_type']);

        // Missing required name
        $res2 = $this->postJson('/api/v1/master/voucher-type-settings', [
            'company_id' => $this->companyA->id,
            'voucher_type' => 'thu_tien_mat',
        ]);
        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // =========================================================================
    // SECTION 2: Unpost vs Void State Transitions across Cash & Bank Vouchers
    // =========================================================================

    public function test_cash_receipt_post_unpost_edit_repost_and_void_lifecycle(): void
    {
        // Step 1: Create a Draft Cash Receipt
        $receipt = CashReceipt::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'PT00010',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'payer_name' => 'Công ty Khách Hàng ABC',
            'reason' => 'Thu tiền bán hàng đợt 1',
            'total_amount' => 2000000,
            'is_posted' => false,
            'status' => 'draft',
        ]);
        $receipt->lines()->create([
            'description' => 'Thu tiền bán hàng đợt 1',
            'debit_account' => '1111',
            'credit_account' => '131',
            'amount' => 2000000,
        ]);

        $this->assertFalse($receipt->is_posted);
        $this->assertEquals('draft', $receipt->status);
        $this->assertNull($receipt->journal_entry_id);

        // Step 2: POST the Cash Receipt -> Journal Entry created
        $postRes = $this->postJson("/api/v1/cash/receipts/{$receipt->id}/post");
        $postRes->assertStatus(200);

        $receipt->refresh();
        $this->assertTrue((bool) $receipt->is_posted);
        $this->assertNotNull($receipt->journal_entry_id);

        $je = JournalEntry::find($receipt->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertEquals('posted', $je->status);
        $this->assertEquals('GL-CR-PT00010', $je->voucher_number);
        $this->assertCount(2, $je->lines);

        // Step 3: UNPOST the Cash Receipt
        $unpostRes = $this->postJson("/api/v1/cash/receipts/{$receipt->id}/unpost");
        $unpostRes->assertStatus(200);

        $receipt->refresh();
        $this->assertFalse((bool) $receipt->is_posted);
        $this->assertEquals('draft', $receipt->status);
        $this->assertNull($receipt->journal_entry_id);

        // Verify the original Journal Entry is VOIDED
        $je->refresh();
        $this->assertEquals('voided', $je->status);

        // Step 4: EDIT the draft voucher after unposting
        $updateRes = $this->putJson("/api/v1/cash/receipts/{$receipt->id}", [
            'voucher_date' => '2026-08-21',
            'reason' => 'Thu tiền bán hàng đợt 1 (Đã chỉnh sửa số tiền)',
            'lines' => [
                [
                    'description' => 'Thu tiền bán hàng đợt 1 (Sửa lên 3,500,000)',
                    'debit_account' => '1111',
                    'credit_account' => '131',
                    'amount' => 3500000,
                ],
            ],
        ]);
        $updateRes->assertStatus(200);

        $receipt->refresh();
        $this->assertEquals(3500000, $receipt->total_amount);

        // Step 5: RE-POST the edited voucher
        $repostRes = $this->postJson("/api/v1/cash/receipts/{$receipt->id}/post");
        $repostRes->assertStatus(200);

        $receipt->refresh();
        $this->assertTrue((bool) $receipt->is_posted);
        $this->assertNotNull($receipt->journal_entry_id);
        $this->assertNotEquals($je->id, $receipt->journal_entry_id); // Brand new JE ID

        $newJe = JournalEntry::find($receipt->journal_entry_id);
        $this->assertNotNull($newJe);
        $this->assertEquals('posted', $newJe->status);
        $this->assertEquals(3500000, $newJe->lines()->where('account_code', '1111')->value('debit_amount'));

        // Step 6: VOID the posted voucher
        $voidRes = $this->postJson("/api/v1/cash/receipts/{$receipt->id}/void");
        $voidRes->assertStatus(200);

        $receipt->refresh();
        $this->assertFalse((bool) $receipt->is_posted);
        $this->assertEquals('voided', $receipt->status);

        $newJe->refresh();
        $this->assertEquals('voided', $newJe->status);
    }

    public function test_cash_payment_post_unpost_and_void_lifecycle(): void
    {
        $payment = CashPayment::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'PC00010',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'receiver_name' => 'Nhà cung cấp XYZ',
            'reason' => 'Chi trả tiền hàng',
            'total_amount' => 1500000,
            'is_posted' => false,
            'status' => 'draft',
        ]);
        $payment->lines()->create([
            'description' => 'Chi trả tiền hàng',
            'debit_account' => '331',
            'credit_account' => '1111',
            'amount' => 1500000,
        ]);

        // Post
        $this->postJson("/api/v1/cash/payments/{$payment->id}/post")->assertStatus(200);
        $payment->refresh();
        $this->assertTrue((bool) $payment->is_posted);
        $jeId = $payment->journal_entry_id;
        $this->assertNotNull($jeId);

        // Unpost
        $this->postJson("/api/v1/cash/payments/{$payment->id}/unpost")->assertStatus(200);
        $payment->refresh();
        $this->assertFalse((bool) $payment->is_posted);
        $this->assertEquals('draft', $payment->status);
        $this->assertNull($payment->journal_entry_id);

        $je = JournalEntry::find($jeId);
        $this->assertEquals('voided', $je->status);

        // Re-post
        $this->postJson("/api/v1/cash/payments/{$payment->id}/post")->assertStatus(200);
        $payment->refresh();
        $this->assertTrue((bool) $payment->is_posted);

        // Void
        $this->postJson("/api/v1/cash/payments/{$payment->id}/void")->assertStatus(200);
        $payment->refresh();
        $this->assertFalse((bool) $payment->is_posted);
        $this->assertEquals('voided', $payment->status);
    }

    public function test_bank_receipt_and_payment_unpost_vs_void(): void
    {
        // Bank Receipt
        $bankReceipt = BankReceipt::create([
            'company_id' => $this->companyA->id,
            'bank_account_id' => $this->bankAccountA->id,
            'voucher_number' => 'BC00010',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'payer_name' => 'Khách hàng Chuyển khoản',
            'reason' => 'Thu tiền gửi KH',
            'total_amount' => 5000000,
            'is_posted' => false,
            'status' => 'draft',
        ]);
        $bankReceipt->lines()->create([
            'description' => 'Thu tiền gửi KH',
            'debit_account' => '1121',
            'credit_account' => '131',
            'amount' => 5000000,
        ]);

        // Post Bank Receipt
        $this->postJson("/api/v1/bank/receipts/{$bankReceipt->id}/post")->assertStatus(200);
        $bankReceipt->refresh();
        $this->assertTrue((bool) $bankReceipt->is_posted);
        $this->assertEquals('posted', $bankReceipt->status);

        // Unpost Bank Receipt
        $this->postJson("/api/v1/bank/receipts/{$bankReceipt->id}/unpost")->assertStatus(200);
        $bankReceipt->refresh();
        $this->assertFalse((bool) $bankReceipt->is_posted);
        $this->assertEquals('draft', $bankReceipt->status);
        $this->assertNull($bankReceipt->journal_entry_id);

        // Bank Payment
        $bankPayment = BankPayment::create([
            'company_id' => $this->companyA->id,
            'bank_account_id' => $this->bankAccountA->id,
            'voucher_number' => 'UNC00010',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'receiver_name' => 'Nhà cung cấp Vina',
            'reason' => 'Ủy nhiệm chi trả tiền NCC',
            'total_amount' => 7000000,
            'is_posted' => false,
            'status' => 'draft',
        ]);
        $bankPayment->lines()->create([
            'description' => 'Ủy nhiệm chi trả tiền NCC',
            'debit_account' => '331',
            'credit_account' => '1121',
            'amount' => 7000000,
        ]);

        // Post Bank Payment
        $this->postJson("/api/v1/bank/payments/{$bankPayment->id}/post")->assertStatus(200);
        $bankPayment->refresh();
        $this->assertTrue((bool) $bankPayment->is_posted);
        $this->assertEquals('posted', $bankPayment->status);

        // Unpost Bank Payment
        $this->postJson("/api/v1/bank/payments/{$bankPayment->id}/unpost")->assertStatus(200);
        $bankPayment->refresh();
        $this->assertFalse((bool) $bankPayment->is_posted);
        $this->assertEquals('draft', $bankPayment->status);
        $this->assertNull($bankPayment->journal_entry_id);
    }

    // =========================================================================
    // SECTION 3: Next-Code Sequential Generation & Stress Increments
    // =========================================================================

    public function test_cash_and_bank_next_code_payload_format_and_multi_increments(): void
    {
        // 1. Verify CashReceipt next-code dual structure
        $resReceipt = $this->getJson("/api/v1/cash/receipts/next-code?company_id={$this->companyA->id}");
        $resReceipt->assertStatus(200)
            ->assertJsonPath('code', 'PT00001')
            ->assertJsonPath('next_code', 'PT00001')
            ->assertJsonPath('data.code', 'PT00001')
            ->assertJsonPath('data.next_code', 'PT00001');

        // 2. Verify CashPayment next-code dual structure
        $resPayment = $this->getJson("/api/v1/cash/payments/next-code?company_id={$this->companyA->id}");
        $resPayment->assertStatus(200)
            ->assertJsonPath('code', 'PC00001')
            ->assertJsonPath('next_code', 'PC00001')
            ->assertJsonPath('data.code', 'PC00001')
            ->assertJsonPath('data.next_code', 'PC00001');

        // 3. Sequential generation stress test (simulate 15 created vouchers)
        for ($i = 1; $i <= 15; $i++) {
            $expectedCode = 'PT'.str_pad($i, 5, '0', STR_PAD_LEFT);
            $check = $this->getJson("/api/v1/cash/receipts/next-code?company_id={$this->companyA->id}");
            $check->assertStatus(200)->assertJsonPath('code', $expectedCode);

            CashReceipt::create([
                'company_id' => $this->companyA->id,
                'voucher_number' => $expectedCode,
                'voucher_date' => '2026-08-20',
                'posting_date' => '2026-08-20',
                'payer_name' => "Người nộp {$i}",
                'reason' => "Thu tiền lần {$i}",
                'total_amount' => 100000 * $i,
                'is_posted' => false,
                'status' => 'draft',
            ]);
        }

        // After 15 items, next code must be PT00016
        $resAfter15 = $this->getJson("/api/v1/cash/receipts/next-code?company_id={$this->companyA->id}");
        $resAfter15->assertStatus(200)->assertJsonPath('code', 'PT00016');

        // 4. Multi-tenant isolation: a client-supplied company_id must never
        // switch the authenticated company context.
        $resCompanyB = $this->getJson("/api/v1/cash/receipts/next-code?company_id={$this->companyB->id}");
        $resCompanyB->assertStatus(200)->assertJsonPath('code', 'PT00016');

        // Create 1 receipt in Company B
        CashReceipt::create([
            'company_id' => $this->companyB->id,
            'voucher_number' => 'PT00001',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'payer_name' => 'Company B Client',
            'reason' => 'Thu tiền cty B',
            'total_amount' => 500000,
            'is_posted' => false,
            'status' => 'draft',
        ]);

        // Company B data remains invisible to Company A's authenticated user.
        $this->getJson("/api/v1/cash/receipts/next-code?company_id={$this->companyB->id}")
            ->assertStatus(200)->assertJsonPath('code', 'PT00016');
        // Company A next code remains PT00016
        $this->getJson("/api/v1/cash/receipts/next-code?company_id={$this->companyA->id}")
            ->assertStatus(200)->assertJsonPath('code', 'PT00016');
    }

    // =========================================================================
    // SECTION 4: CashReceipt Server-Side Filtering Stress Test
    // =========================================================================

    public function test_cash_receipt_server_side_filters(): void
    {
        $cust1 = Customer::create([
            'company_id' => $this->companyA->id,
            'code' => 'KH001',
            'name' => 'Công ty An Bình',
        ]);

        $cust2 = Customer::create([
            'company_id' => $this->companyA->id,
            'code' => 'KH002',
            'name' => 'Công ty Bình Minh',
        ]);

        // Seed 4 distinct cash receipts
        CashReceipt::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'PT00101',
            'voucher_date' => '2026-01-10',
            'posting_date' => '2026-01-10',
            'contact_id' => $cust1->id,
            'payer_name' => 'Công ty An Bình',
            'reason' => 'Thu tiền tạm ứng quý 1',
            'total_amount' => 1000000,
            'status' => 'draft',
            'is_posted' => false,
        ]);

        CashReceipt::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'PT00102',
            'voucher_date' => '2026-03-15',
            'posting_date' => '2026-03-15',
            'contact_id' => $cust1->id,
            'payer_name' => 'Công ty An Bình',
            'reason' => 'Thu tiền hợp đồng đợt 2',
            'total_amount' => 2000000,
            'status' => 'posted',
            'is_posted' => true,
        ]);

        CashReceipt::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'PT00103',
            'voucher_date' => '2026-06-20',
            'posting_date' => '2026-06-20',
            'contact_id' => $cust2->id,
            'payer_name' => 'Công ty Bình Minh',
            'reason' => 'Thu hồi công nợ',
            'total_amount' => 3000000,
            'status' => 'voided',
            'is_posted' => false,
        ]);

        CashReceipt::create([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'PT00104',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'contact_id' => $cust2->id,
            'payer_name' => 'Công ty Bình Minh',
            'reason' => 'Thu tiền dịch vụ tư vấn',
            'total_amount' => 4000000,
            'status' => 'posted',
            'is_posted' => true,
        ]);

        // Filter 1: Date range (2026-01-01 to 2026-03-31) -> Should return 2 (PT00101, PT00102)
        $resDate = $this->getJson('/api/v1/cash/receipts?date_from=2026-01-01&date_to=2026-03-31');
        $resDate->assertStatus(200)->assertJsonCount(2, 'data');
        $vNums = collect($resDate->json('data'))->pluck('voucher_number')->all();
        $this->assertContains('PT00101', $vNums);
        $this->assertContains('PT00102', $vNums);

        // Filter 2: Contact ID (Cust 1) -> Should return 2 (PT00101, PT00102)
        $resContact = $this->getJson("/api/v1/cash/receipts?contact_id={$cust1->id}");
        $resContact->assertStatus(200)->assertJsonCount(2, 'data');

        // Filter 3: Status = 'posted' -> Should return 2 (PT00102, PT00104)
        $resStatus = $this->getJson('/api/v1/cash/receipts?status=posted');
        $resStatus->assertStatus(200)->assertJsonCount(2, 'data');

        // Filter 4: Status = 'voided' -> Should return 1 (PT00103)
        $resVoided = $this->getJson('/api/v1/cash/receipts?status=voided');
        $resVoided->assertStatus(200)->assertJsonCount(1, 'data');

        // Filter 5: Search by voucher number keyword '104' -> Should return 1 (PT00104)
        $resSearchNum = $this->getJson('/api/v1/cash/receipts?search=104');
        $resSearchNum->assertStatus(200)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.voucher_number', 'PT00104');

        // Filter 6: Search by reason keyword 'tư vấn' -> Should return 1 (PT00104)
        $resSearchReason = $this->getJson('/api/v1/cash/receipts?search=tư vấn');
        $resSearchReason->assertStatus(200)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.voucher_number', 'PT00104');

        // Filter 7: Multi-filter combinations (date_from=2026-06-01 + contact_id=cust2 + status=posted) -> Should return 1 (PT00104)
        $resMulti = $this->getJson("/api/v1/cash/receipts?date_from=2026-06-01&contact_id={$cust2->id}&status=posted");
        $resMulti->assertStatus(200)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.voucher_number', 'PT00104');
    }
}
