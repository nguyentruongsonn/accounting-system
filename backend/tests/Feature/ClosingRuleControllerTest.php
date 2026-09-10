<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\ClosingRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClosingRuleControllerTest extends TestCase
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
            'name' => 'Closing Rule Test Company',
            'tax_code' => '0101243151',
        ]);
        $this->configureAccountingTenant($this->user, $this->company);

        foreach ([
            ['code' => '511', 'name' => 'Doanh thu', 'type' => 'revenue', 'nature' => 'credit'],
            ['code' => '632', 'name' => 'Giá vốn', 'type' => 'expense', 'nature' => 'debit'],
            ['code' => '911', 'name' => 'Xác định kết quả', 'type' => 'equity', 'nature' => 'credit'],
            ['code' => '4212', 'name' => 'Lợi nhuận chưa phân phối', 'type' => 'equity', 'nature' => 'credit'],
        ] as $account) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
                ...$account,
            ]);
        }
        ChartOfAccount::create([
            'company_id' => $this->company->id,
            'code' => '400',
            'name' => 'Tài khoản tổng hợp doanh thu',
            'type' => 'revenue',
            'nature' => 'credit',
            'level' => 1,
            'is_parent' => true,
            'is_active' => true,
        ]);
    }

    public function test_can_list_closing_catalogue_with_execution_disabled(): void
    {
        $rule = ClosingRule::create([
            'company_id' => $this->company->id,
            'rule_code' => 'TEST_KC_511_911',
            'rule_name' => 'Kết chuyển doanh thu test',
            'rule_type' => 'revenue',
            'debit_account' => '511',
            'credit_account' => '911',
            'source_account' => '511',
            'target_account' => '911',
            'closing_side' => 'credit',
            'transfer_type' => 'turnover',
            'sequence' => 10,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/master/closing-rules')
            ->assertOk()
            ->assertJsonPath('meta.execution_authorized', false)
            ->assertJsonPath('meta.approval_required', true)
            ->assertJsonPath('data.0.id', $rule->id);
    }

    public function test_can_create_and_update_internal_closing_rule(): void
    {
        $payload = [
            'rule_code' => 'TEST_KC_632_911',
            'rule_name' => 'Kết chuyển giá vốn test',
            'rule_type' => 'expense',
            'debit_account' => '911',
            'credit_account' => '632',
            'source_account' => '632',
            'target_account' => '911',
            'closing_side' => 'debit',
            'transfer_type' => 'turnover',
            'sequence' => 20,
            'is_active' => true,
        ];

        $created = $this->postJson('/api/v1/master/closing-rules', $payload)
            ->assertCreated()
            ->assertJsonPath('data.rule_code', 'TEST_KC_632_911')
            ->assertJsonPath('meta.execution_authorized', false);

        $id = $created->json('data.id');
        $this->putJson("/api/v1/master/closing-rules/{$id}", [
            'rule_name' => 'Kết chuyển giá vốn đã sửa',
            'sequence' => 30,
        ])->assertOk()->assertJsonPath('data.rule_name', 'Kết chuyển giá vốn đã sửa');

        $this->assertDatabaseHas('closing_rules', [
            'id' => $id,
            'company_id' => $this->company->id,
            'sequence' => 30,
        ]);
    }

    public function test_rejects_unknown_or_inactive_account_without_persisting_rule(): void
    {
        $this->postJson('/api/v1/master/closing-rules', [
            'rule_code' => 'TEST_KC_UNKNOWN',
            'rule_name' => 'Không được lưu',
            'rule_type' => 'expense',
            'debit_account' => '9999',
            'credit_account' => '911',
            'closing_side' => 'debit',
            'transfer_type' => 'turnover',
            'sequence' => 40,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('closing_rules', ['rule_code' => 'TEST_KC_UNKNOWN']);

        $this->postJson('/api/v1/master/closing-rules', [
            'rule_code' => 'TEST_KC_PARENT',
            'rule_name' => 'Không dùng tài khoản tổng hợp',
            'rule_type' => 'revenue',
            'debit_account' => '400',
            'credit_account' => '911',
            'closing_side' => 'debit',
            'transfer_type' => 'turnover',
            'sequence' => 41,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('closing_rules', ['rule_code' => 'TEST_KC_PARENT']);
    }

    public function test_foreign_closing_rule_cannot_be_read_or_deleted(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign Closing Rule Company', 'tax_code' => '0101243152']);
        $rule = ClosingRule::create([
            'company_id' => $foreignCompany->id,
            'rule_code' => 'FOREIGN_KC',
            'rule_name' => 'Foreign',
            'rule_type' => 'revenue',
            'debit_account' => '511',
            'credit_account' => '911',
            'closing_side' => 'credit',
            'transfer_type' => 'turnover',
            'sequence' => 10,
            'is_active' => true,
        ]);

        $this->getJson("/api/v1/master/closing-rules/{$rule->id}")->assertNotFound();
        $this->deleteJson("/api/v1/master/closing-rules/{$rule->id}")->assertNotFound();
        $this->assertDatabaseHas('closing_rules', ['id' => $rule->id, 'company_id' => $foreignCompany->id]);
    }

    public function test_rule_code_uniqueness_is_scoped_to_the_actor_company(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign Duplicate Code Company', 'tax_code' => '0101243153']);
        ClosingRule::create([
            'company_id' => $foreignCompany->id,
            'rule_code' => 'SHARED_KC_CODE',
            'rule_name' => 'Foreign rule',
            'rule_type' => 'revenue',
            'debit_account' => '511',
            'credit_account' => '911',
            'closing_side' => 'credit',
            'transfer_type' => 'turnover',
            'sequence' => 1,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/master/closing-rules', [
            'rule_code' => 'SHARED_KC_CODE',
            'rule_name' => 'Tenant-local rule',
            'rule_type' => 'revenue',
            'debit_account' => '511',
            'credit_account' => '911',
            'closing_side' => 'credit',
            'transfer_type' => 'turnover',
            'sequence' => 2,
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.rule_code', 'SHARED_KC_CODE');
    }
}
