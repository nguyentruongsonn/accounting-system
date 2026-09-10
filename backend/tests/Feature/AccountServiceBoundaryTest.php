<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountServiceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_create_cannot_reference_a_parent_from_another_company(): void
    {
        $company = Company::create(['name' => 'Account service boundary', 'tax_code' => 'ASB-001']);
        $foreign = Company::create(['name' => 'Foreign account service boundary', 'tax_code' => 'ASB-002']);
        $user = User::factory()->create();
        $this->configureAccountingTenant($user, $company);
        Sanctum::actingAs($user);

        $foreignParent = ChartOfAccount::withoutGlobalScopes()->create([
            'company_id' => $foreign->id,
            'code' => '9000',
            'name' => 'Foreign parent',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => false,
            'is_active' => true,
        ]);

        try {
            app(AccountService::class)->create([
                'company_id' => $company->id,
                'code' => '9001',
                'name' => 'Cross tenant child',
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'parent_code' => $foreignParent->code,
            ]);
            $this->fail('A cross-tenant parent should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_code', $exception->errors());
        }

        $this->assertDatabaseMissing('chart_of_accounts', [
            'company_id' => $company->id,
            'code' => '9001',
        ]);
    }

    public function test_direct_update_cannot_create_a_parent_cycle(): void
    {
        $company = Company::create(['name' => 'Account cycle boundary', 'tax_code' => 'ASB-003']);
        $user = User::factory()->create();
        $this->configureAccountingTenant($user, $company);
        Sanctum::actingAs($user);

        $parent = ChartOfAccount::create([
            'company_id' => $company->id,
            'code' => '9100',
            'name' => 'Parent',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => true,
            'is_active' => true,
        ]);
        $child = ChartOfAccount::create([
            'company_id' => $company->id,
            'code' => '9101',
            'name' => 'Child',
            'type' => 'asset',
            'nature' => 'debit',
            'parent_code' => $parent->code,
            'level' => 2,
            'is_parent' => false,
            'is_active' => true,
        ]);

        try {
            app(AccountService::class)->update($parent->id, [
                'parent_code' => $child->code,
            ], $company->id);
            $this->fail('A parent cycle should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_code', $exception->errors());
        }

        $this->assertSame(null, $parent->fresh()->parent_code);
        $this->assertSame($parent->code, $child->fresh()->parent_code);
    }

    public function test_direct_update_rejects_code_or_company_mutation(): void
    {
        $company = Company::create(['name' => 'Account immutable identity', 'tax_code' => 'ASB-004']);
        $foreign = Company::create(['name' => 'Foreign immutable identity', 'tax_code' => 'ASB-005']);
        $user = User::factory()->create();
        $this->configureAccountingTenant($user, $company);
        Sanctum::actingAs($user);

        $account = ChartOfAccount::create([
            'company_id' => $company->id,
            'code' => '9200',
            'name' => 'Immutable identity',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => false,
            'is_active' => true,
        ]);

        foreach ([
            ['code' => '9201'],
            ['company_id' => $foreign->id],
        ] as $mutation) {
            try {
                app(AccountService::class)->update($account->id, $mutation, $company->id);
                $this->fail('An account identity mutation should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $account->id,
            'company_id' => $company->id,
            'code' => '9200',
        ]);
    }
}
