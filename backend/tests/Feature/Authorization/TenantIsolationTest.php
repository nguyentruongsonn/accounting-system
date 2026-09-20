<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_only_sees_users_from_the_authenticated_company(): void
    {
        $companyA = Company::create(['name' => 'Tenant A']);
        $companyB = Company::create(['name' => 'Tenant B']);
        $adminA = $this->makeUser($companyA, 'admin', 'admin-a@example.test');
        $userB = $this->makeUser($companyB, 'accountant', 'accountant-b@example.test');
        Sanctum::actingAs($adminA, [], 'sanctum');

        $response = $this->getJson('/api/v1/users')->assertSuccessful();
        $response->assertJsonPath('data.0.email', 'admin-a@example.test');
        $response->assertJsonMissing(['email' => $userB->email]);
    }

    public function test_admin_cannot_update_a_user_from_another_company(): void
    {
        $companyA = Company::create(['name' => 'Tenant A update']);
        $companyB = Company::create(['name' => 'Tenant B update']);
        $adminA = $this->makeUser($companyA, 'admin', 'admin-update-a@example.test');
        $userB = $this->makeUser($companyB, 'accountant', 'accountant-update-b@example.test');
        Sanctum::actingAs($adminA, [], 'sanctum');

        $this->putJson('/api/v1/users/'.$userB->id, [
            'name' => 'Cross tenant update',
            'email' => $userB->email,
            'role' => 'accountant',
            'is_active' => true,
        ])->assertNotFound();
    }

    private function makeUser(Company $company, string $role, string $email): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
