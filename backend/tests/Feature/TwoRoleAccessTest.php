<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TwoRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    private function actor(string $role = 'admin', int $company = 1): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Company::unguarded(fn () => Company::firstOrCreate(['id' => $company], ['name' => 'Test company '.$company]));
        $user = User::factory()->create(['company_id' => $company]);
        $user->assignRole($role);

        return $user;
    }

    public function test_seeders_have_identical_bounded_grants_and_never_elevate_first_user(): void
    {
        $user = User::factory()->create();
        Permission::create(['name' => 'future.superpower', 'guard_name' => 'web']);
        foreach ([RoleAndPermissionSeeder::class, RolesAndPermissionsSeeder::class, RoleAndPermissionSeeder::class] as $seeder) {
            $this->seed($seeder);
            $this->assertSame(['accountant', 'admin'], Role::orderBy('name')->pluck('name')->all());
            $this->assertCount(0, $user->fresh()->roles);
            $this->assertFalse(Role::findByName('admin')->hasPermissionTo('future.superpower'));
            foreach (['purchase.invoices.post', 'purchase.orders.delete', 'inventory.cost-calculation.run', 'master.warehouses.view', 'gl.reconciliations.execute', 'accounting.account-mappings.create'] as $permission) {
                $this->assertTrue(Role::findByName('accountant')->hasPermissionTo($permission), $permission);
            }
            foreach (['manage_users', 'accounting.account-mappings.approve', 'accounting.audit-trail.view', 'master.accounts.delete', 'gl.journal-entries.reverse', 'gl.periods.close', 'payroll.unpost'] as $permission) {
                $this->assertFalse(Role::findByName('accountant')->hasPermissionTo($permission), $permission);
            }
        }
    }

    public function test_existing_legacy_identities_are_not_changed_by_seeding(): void
    {
        $role = Role::create(['name' => 'legacy-owner', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->assertSame(['legacy-owner'], $user->fresh()->getRoleNames()->all());
    }

    public function test_accountant_can_access_core_but_not_administration_or_reversal(): void
    {
        Sanctum::actingAs($this->actor('accountant'));
        $this->getJson('/api/v1/master/warehouses')->assertOk();
        $this->postJson('/api/v1/fixed-assets')->assertUnprocessable();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/fixed-assets/999/unpost')->assertForbidden();
        $this->postJson('/api/v1/approved-account-mappings/999/approve')->assertForbidden();
    }

    public function test_admin_user_creation_is_tenant_scoped_hashed_and_role_restricted(): void
    {
        Sanctum::actingAs($this->actor());
        $foreign = $this->actor('admin', 2);
        $body = ['name' => 'Bookkeeper', 'email' => 'book@example.test', 'password' => 'Strong-password-123!', 'password_confirmation' => 'Strong-password-123!', 'role' => 'accountant', 'is_active' => true];
        $this->postJson('/api/v1/users', $body + ['company_id' => 2])->assertCreated()->assertJsonMissingPath('data.password');
        $created = User::where('email', $body['email'])->firstOrFail();
        $this->assertEquals(1, $created->company_id);
        $this->assertTrue(Hash::check($body['password'], $created->password));
        $this->getJson('/api/v1/users')->assertOk()->assertJsonMissing(['email' => $foreign->email]);
        $this->putJson('/api/v1/users/'.$foreign->id, ['name' => 'Changed'])->assertNotFound();
        $this->postJson('/api/v1/users', array_replace($body, ['email' => 'bad@example.test', 'role' => 'chief-accountant']))->assertUnprocessable();
        $this->postJson('/api/v1/users', array_replace($body, ['email' => 'weak@example.test', 'password' => 'x', 'password_confirmation' => 'x']))->assertUnprocessable();
    }

    public function test_last_active_admin_cannot_be_disabled_or_demoted(): void
    {
        $admin = $this->actor();
        $this->actor('admin', 2);
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/users/'.$admin->id, ['is_active' => false])->assertUnprocessable();
        $this->putJson('/api/v1/users/'.$admin->id, ['role' => 'accountant'])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_role_password_and_active_changes_revoke_tokens_and_database_sessions(): void
    {
        $admin = $this->actor();
        $target = $this->actor('accountant');
        Sanctum::actingAs($admin);
        foreach ([['role' => 'admin'], ['password' => 'Changed-password-123!', 'password_confirmation' => 'Changed-password-123!'], ['is_active' => false]] as $change) {
            $target->createToken('existing');
            DB::table('sessions')->insert(['id' => 'existing', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
            $this->putJson('/api/v1/users/'.$target->id, $change)->assertOk();
            $this->assertCount(0, $target->tokens()->get());
            $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        }
    }

    public function test_inactive_login_and_issued_token_are_rejected(): void
    {
        $user = $this->actor('accountant');
        $token = $user->createToken('existing')->plainTextToken;
        $user->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_active_legacy_identity_cannot_login_until_reassigned_to_a_canonical_role(): void
    {
        $legacyRole = Role::findOrCreate('legacy-owner', 'web');
        $legacy = User::factory()->create(['company_id' => 1, 'password' => 'password']);
        $legacy->assignRole($legacyRole);

        $this->postJson('/api/v1/auth/login', [
            'email' => $legacy->email,
            'password' => 'password',
        ])->assertUnauthorized();
    }

    public function test_existing_token_of_active_legacy_identity_is_rejected(): void
    {
        $legacyRole = Role::findOrCreate('legacy-owner', 'web');
        $legacy = User::factory()->create(['company_id' => 1]);
        $legacy->assignRole($legacyRole);
        $token = $legacy->createToken('legacy-session')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $legacy->id]);
    }

    public function test_multi_role_identity_cannot_login_until_it_has_exactly_one_canonical_role(): void
    {
        $user = $this->actor('admin');
        $user->assignRole('accountant');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnauthorized();
    }

    public function test_existing_token_of_multi_role_identity_is_rejected(): void
    {
        $user = $this->actor('admin');
        $user->assignRole('accountant');
        $token = $user->createToken('multi-role-session')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_stateful_legacy_principal_is_rejected(): void
    {
        $legacyRole = Role::findOrCreate('legacy-owner', 'web');
        $legacy = User::factory()->create(['company_id' => 1]);
        $legacy->assignRole($legacyRole);

        config(['app.env' => 'production']);
        try {
            $this->actingAs($legacy, 'web')->getJson('/api/v1/auth/user')->assertUnauthorized();
        } finally {
            config(['app.env' => 'testing']);
        }
    }

    public function test_stateful_multi_role_principal_is_rejected(): void
    {
        $user = $this->actor('admin');
        $user->assignRole('accountant');

        config(['app.env' => 'production']);
        try {
            $this->actingAs($user, 'web')->getJson('/api/v1/auth/user')->assertUnauthorized();
        } finally {
            config(['app.env' => 'testing']);
        }
    }

    public function test_stateful_canonical_roles_remain_usable(): void
    {
        foreach (['admin', 'accountant'] as $role) {
            $user = $this->actor($role);

            config(['app.env' => 'production']);
            try {
                $this->actingAs($user, 'web')
                    ->getJson('/api/v1/auth/user')
                    ->assertOk()
                    ->assertJsonPath('roles', [$role]);
            } finally {
                config(['app.env' => 'testing']);
                app('auth')->forgetGuards();
            }
        }
    }

    public function test_existing_tokens_of_both_canonical_roles_remain_usable(): void
    {
        foreach (['admin', 'accountant'] as $role) {
            $user = $this->actor($role);
            $token = $user->createToken($role.'-session')->plainTextToken;

            $this->withToken($token)
                ->getJson('/api/v1/auth/user')
                ->assertOk()
                ->assertJsonPath('roles', [$role]);

            app('auth')->forgetGuards();
        }
    }

    public function test_inactive_stateful_principal_is_rejected(): void
    {
        $user = $this->actor('accountant');
        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_explicit_role_replacement_removes_direct_grants_and_revokes_even_same_role(): void
    {
        $admin = $this->actor();
        $user = $this->actor('accountant');
        $user->givePermissionTo('manage_users');
        $token = $user->createToken('old');
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/users/'.$user->id, ['role' => 'accountant'])->assertOk();
        $this->assertFalse($user->fresh()->hasPermissionTo('manage_users'));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_permission_only_user_and_anonymous_caller_cannot_manage_users(): void
    {
        $this->postJson('/api/v1/users', ['role' => 'admin'])->assertUnauthorized();
        $user = $this->actor('accountant');
        $user->givePermissionTo('manage_users');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', ['role' => 'admin'])->assertForbidden();
    }

    public function test_legacy_user_cannot_be_reactivated_without_a_canonical_role(): void
    {
        $admin = $this->actor();
        $legacy = User::factory()->create(['company_id' => 1]);
        $legacy->assignRole(Role::findOrCreate('legacy-owner', 'web'));
        $legacy->forceFill(['is_active' => false])->save();
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/users/'.$legacy->id, ['is_active' => true])->assertUnprocessable();
        $this->putJson('/api/v1/users/'.$legacy->id, ['is_active' => true, 'role' => 'accountant'])->assertOk();
        $this->assertSame(['accountant'], $legacy->fresh()->getRoleNames()->all());
    }

    public function test_active_legacy_user_cannot_be_edited_without_explicit_role_replacement(): void
    {
        $admin = $this->actor();
        $legacy = User::factory()->create(['company_id' => 1, 'name' => 'Legacy user']);
        $legacy->assignRole(Role::findOrCreate('legacy-owner', 'web'));
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/users/'.$legacy->id, ['name' => 'Still legacy'])->assertUnprocessable()
            ->assertJsonValidationErrors('role');
        $this->assertSame('Legacy user', $legacy->fresh()->name);
    }

    public function test_pre_migration_login_stays_available_but_user_management_explains_schema_requirement(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Schema-removal compatibility probe is SQLite-only; MySQL DDL implicitly commits.');
        }
        $user = $this->actor();
        Schema::table('users', function ($table) {
            $table->dropColumn(['is_active', 'auth_version']);
        });
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($login->json('token'))->getJson('/api/v1/auth/user')->assertOk();
        $this->getJson('/api/v1/users')->assertStatus(503);
    }

    public function test_active_middleware_uses_fresh_permissions_not_stale_principal_roles(): void
    {
        $user = $this->actor();
        $user->load('roles', 'permissions');
        Sanctum::actingAs($user);
        $fresh = $user->fresh();
        $fresh->syncRoles('accountant');
        $this->getJson('/api/v1/users')->assertForbidden();
    }
}
