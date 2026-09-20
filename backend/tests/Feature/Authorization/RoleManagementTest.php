<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_accountant_can_read_and_prepare_purchase_invoices_but_cannot_manage_users_or_close_periods(): void
    {
        $company = Company::create(['name' => 'Role test company']);
        $accountant = $this->makeUser($company, 'accountant');
        Sanctum::actingAs($accountant, [], 'sanctum');

        $this->getJson('/api/v1/purchase/invoices')->assertSuccessful();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/gl/periods/close', [])->assertForbidden();
    }

    public function test_admin_can_manage_users_and_read_accounting_audit_trail(): void
    {
        $company = Company::create(['name' => 'Admin role test company']);
        $admin = $this->makeUser($company, 'admin');
        Sanctum::actingAs($admin, [], 'sanctum');

        $this->getJson('/api/v1/users')->assertSuccessful();
        $this->getJson('/api/v1/accounting-audit-trail')->assertSuccessful();
    }

    private function makeUser(Company $company, string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
