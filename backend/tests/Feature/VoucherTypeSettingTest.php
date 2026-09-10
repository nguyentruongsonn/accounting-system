<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Models\VoucherTypeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoucherTypeSettingTest extends TestCase
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
            'name' => 'MISA AMIS Test Company',
            'tax_code' => '0101243150',
            'address' => 'Hà Nội',
        ]);

        $this->configureAccountingTenant($this->user, $this->company);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1111', 'name' => 'Tiền Việt Nam', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '711', 'name' => 'Thu nhập khác', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '111', 'name' => 'Tiền mặt tổng hợp', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 1]);
    }

    public function test_can_get_system_default_voucher_types(): void
    {
        $response = $this->getJson('/api/v1/master/voucher-type-settings/types');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    public function test_fallback_to_system_defaults_when_empty(): void
    {
        $response = $this->getJson('/api/v1/master/voucher-type-settings?voucher_type=thu_tien_mat&company_id='.$this->company->id);

        $response->assertStatus(200)
            ->assertJsonPath('meta.is_system_defaults', true)
            ->assertJsonCount(5, 'data');
    }

    public function test_internal_catalogue_can_include_inactive_rows_without_changing_operational_default(): void
    {
        $setting = VoucherTypeSetting::create([
            'company_id' => $this->company->id,
            'voucher_type' => 'thu_tien_mat',
            'name' => 'Cấu hình đã tắt',
            'debit_account' => '1111',
            'credit_account' => '131',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/master/voucher-type-settings?voucher_type=thu_tien_mat')
            ->assertOk()
            ->assertJsonPath('meta.is_system_defaults', true)
            ->assertJsonMissing(['id' => $setting->id]);

        $this->getJson('/api/v1/master/voucher-type-settings?voucher_type=thu_tien_mat&include_inactive=1')
            ->assertOk()
            ->assertJsonMissingPath('meta.is_system_defaults')
            ->assertJsonPath('data.0.id', $setting->id)
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_can_create_and_persist_voucher_type_setting(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'thu_tien_mat',
            'name' => 'Thu tiền thuê nhà xưởng',
            'debit_account' => '1111',
            'credit_account' => '711',
            'filter_debit' => '111',
            'filter_credit' => '711',
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/master/voucher-type-settings', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Thu tiền thuê nhà xưởng')
            ->assertJsonPath('data.debit_account', '1111')
            ->assertJsonPath('data.credit_account', '711');

        $this->assertDatabaseHas('voucher_type_settings', [
            'name' => 'Thu tiền thuê nhà xưởng',
            'voucher_type' => 'thu_tien_mat',
            'debit_account' => '1111',
            'credit_account' => '711',
        ]);
    }

    public function test_rejects_parent_or_inactive_accounts_for_internal_defaults(): void
    {
        $this->postJson('/api/v1/master/voucher-type-settings', [
            'voucher_type' => 'thu_tien_mat',
            'name' => 'Không dùng tài khoản tổng hợp',
            'debit_account' => '111',
            'credit_account' => '131',
            'is_active' => true,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('voucher_type_settings', [
            'name' => 'Không dùng tài khoản tổng hợp',
        ]);
    }

    public function test_cash_receipt_next_code_is_sequential(): void
    {
        $res1 = $this->getJson('/api/v1/cash/receipts/next-code?company_id='.$this->company->id);
        $res1->assertStatus(200)
            ->assertJsonPath('code', 'PT00001');

        CashReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PT00001',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'payer_name' => 'Khách hàng A',
            'reason' => 'Thu tiền',
            'total_amount' => 500000,
            'is_posted' => false,
            'status' => 'draft',
        ]);

        $res2 = $this->getJson('/api/v1/cash/receipts/next-code?company_id='.$this->company->id);
        $res2->assertStatus(200)
            ->assertJsonPath('code', 'PT00002');
    }

    public function test_can_unpost_receipt_without_voiding(): void
    {
        $receipt = CashReceipt::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'PT00099',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'payer_name' => 'Khách hàng B',
            'reason' => 'Thu tiền đã post',
            'total_amount' => 1000000,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        $response = $this->postJson("/api/v1/cash/receipts/{$receipt->id}/unpost");
        $response->assertStatus(200);

        $receipt->refresh();
        $this->assertFalse($receipt->is_posted);
        $this->assertEquals('draft', $receipt->status);
    }

    public function test_create_and_retrieve_custom_voucher_type_with_employee(): void
    {
        // 1. Create Employee Lê Văn Tám
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'code' => 'NV00002',
            'name' => 'Lê Văn Tám',
            'is_active' => true,
        ]);
        Customer::create([
            'company_id' => $this->company->id,
            'code' => 'KH001',
            'name' => 'Công ty An Phát',
        ]);

        // 2. Create custom voucher setting "Thành phố Hồ Chí Minh"
        $setting = VoucherTypeSetting::create([
            'company_id' => $this->company->id,
            'voucher_type' => 'thu_tien_mat',
            'name' => 'Thành phố Hồ Chí Minh',
            'debit_account' => '1111',
            'credit_account' => '131',
            'is_active' => true,
        ]);

        // 3. Create cash receipt with custom voucher type and employee
        $payload = [
            'company_id' => $this->company->id,
            'voucher_type' => 'Thành phố Hồ Chí Minh',
            'voucher_number' => 'PT00055',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'contact_type' => 'customer',
            'contact_id' => 'KH001',
            'contact_name' => 'Công ty An Phát',
            'payer_name' => 'Công ty An Phát',
            'employee_id' => $employee->id,
            'reason' => 'Thành phố Hồ Chí Minh của Công ty An Phát',
            'lines' => [
                [
                    'debit_account' => '1111',
                    'credit_account' => '131',
                    'amount' => 2000000,
                    'description' => 'Thành phố Hồ Chí Minh của Công ty An Phát',
                ],
            ],
        ];

        $postRes = $this->postJson('/api/v1/cash/receipts', $payload);
        $postRes->assertStatus(201)
            ->assertJsonPath('data.voucher_type', 'Thành phố Hồ Chí Minh')
            ->assertJsonPath('data.employee_id', (string) $employee->id)
            ->assertJsonPath('data.employee_name', 'Lê Văn Tám');

        $receiptId = $postRes->json('data.id');

        // 4. Retrieve receipt - must preserve voucher_type, employee_id, and employee_name
        $getRes = $this->getJson("/api/v1/cash/receipts/{$receiptId}");
        $getRes->assertStatus(200)
            ->assertJsonPath('data.voucher_type', 'Thành phố Hồ Chí Minh')
            ->assertJsonPath('data.employee_id', (string) $employee->id)
            ->assertJsonPath('data.employee_name', 'Lê Văn Tám')
            ->assertJsonPath('data.lines.0.credit_account', '131');
    }
}
