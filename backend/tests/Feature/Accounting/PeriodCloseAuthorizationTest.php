<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PeriodCloseAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_accountant_cannot_close_or_reopen_a_period(): void
    {
        $company = Company::create(['name' => 'Period close company']);
        $accountant = $this->makeUser($company, 'accountant');
        Sanctum::actingAs($accountant, [], 'sanctum');

        $this->postJson('/api/v1/gl/periods/close', [])->assertForbidden();
        $this->postJson('/api/v1/gl/periods/1/reopen', [])->assertForbidden();
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
