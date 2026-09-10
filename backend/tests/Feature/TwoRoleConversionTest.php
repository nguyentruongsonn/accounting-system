<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TwoRoleConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_preview_does_not_change_users_and_never_exports_secrets(): void
    {
        $user = User::factory()->create(['company_id' => 1]);
        $user->assignRole(Role::findOrCreate('legacy-chief', 'web'));
        $token = $user->createToken('existing');
        $this->artisan('access:convert-two-roles')->expectsOutputToContain($user->email)->assertSuccessful();
        $this->assertSame(['legacy-chief'], $user->fresh()->getRoleNames()->all());
        $this->assertTrue($user->fresh()->is_active);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_apply_refuses_without_explicit_confirmed_active_admin(): void
    {
        User::factory()->create(['company_id' => 1]);
        $this->artisan('access:convert-two-roles', ['--apply' => true])->assertFailed();
    }

    public function test_preview_json_contains_only_review_fields_and_no_password_or_token_hashes(): void
    {
        $user = User::factory()->create(['company_id' => 1]);
        $token = $user->createToken('sensitive');
        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, $this->artisan('access:convert-two-roles'));
        $output = Artisan::output();
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['id', 'company_id', 'name', 'email', 'is_active', 'roles', 'proposed_action'], array_keys($data['users'][0]));
        $this->assertStringNotContainsString($user->password, $output);
        $this->assertStringNotContainsString($token->accessToken->token, $output);
    }

    public function test_apply_preserves_confirmed_admin_and_deactivates_unknown_identity_without_deletion(): void
    {
        $admin = User::factory()->create(['company_id' => 1]);
        $legacy = User::factory()->create(['company_id' => 1]);
        $legacy->assignRole(Role::findOrCreate('director', 'web'));
        $token = $legacy->createToken('old');
        $this->artisan('access:convert-two-roles', ['--apply' => true, '--confirm-admin' => [$admin->id]])->assertSuccessful();
        $this->assertSame(['admin'], $admin->fresh()->getRoleNames()->all());
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertFalse($legacy->fresh()->is_active);
        $this->assertSame(['director'], $legacy->fresh()->getRoleNames()->all());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_apply_accepts_an_explicitly_confirmed_accountant_without_promoting_legacy_identities(): void
    {
        $admin = User::factory()->create(['company_id' => 1]);
        $accountant = User::factory()->create(['company_id' => 1]);
        $accountant->assignRole(Role::findOrCreate('chief_accountant', 'web'));
        $accountantToken = $accountant->createToken('old-accountant-session');

        $this->artisan('access:convert-two-roles', [
            '--apply' => true,
            '--confirm-admin' => [$admin->id],
            '--confirm-accountant' => [$accountant->id],
        ])->assertSuccessful();

        $this->assertSame(['admin'], $admin->fresh()->getRoleNames()->all());
        $this->assertSame(['accountant'], $accountant->fresh()->getRoleNames()->all());
        $this->assertTrue($accountant->fresh()->is_active);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $accountantToken->accessToken->id]);
    }

    public function test_apply_rejects_the_same_identity_as_both_admin_and_accountant(): void
    {
        $user = User::factory()->create(['company_id' => 1]);

        $this->artisan('access:convert-two-roles', [
            '--apply' => true,
            '--confirm-admin' => [$user->id],
            '--confirm-accountant' => [$user->id],
        ])->assertFailed();
    }

    public function test_apply_requires_confirmed_admin_for_each_company_and_rejects_inactive_confirmation(): void
    {
        $admin = User::factory()->create(['company_id' => 1]);
        Company::unguarded(fn () => Company::firstOrCreate(['id' => 2], ['name' => 'Other company']));
        User::factory()->create(['company_id' => 2]);
        $this->artisan('access:convert-two-roles', ['--apply' => true, '--confirm-admin' => [$admin->id]])->assertFailed();
        $admin->forceFill(['is_active' => false])->save();
        $this->artisan('access:convert-two-roles', ['--apply' => true, '--confirm-admin' => [$admin->id]])->assertFailed();
    }
}
