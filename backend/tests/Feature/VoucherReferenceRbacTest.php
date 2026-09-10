<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VoucherReferenceRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_authenticated_actor_without_reference_permissions_is_denied(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/voucher-references/search?module_group=not-a-module')
            ->assertForbidden();
        $this->postJson('/api/v1/voucher-references/resolve-defaults')
            ->assertForbidden();
    }

    public function test_search_permission_does_not_grant_cross_voucher_resolution(): void
    {
        $actor = $this->actorWithPermission('voucher-references.view');
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/voucher-references/search?module_group=not-a-module')
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->postJson('/api/v1/voucher-references/resolve-defaults')
            ->assertForbidden();
    }

    public function test_resolution_requires_its_explicit_permission(): void
    {
        $actor = $this->actorWithPermission('voucher-references.resolve-defaults');
        Sanctum::actingAs($actor);

        // No target is intentional: it exercises the authorization boundary
        // without invoking legacy account-suggestion branches.
        $this->postJson('/api/v1/voucher-references/resolve-defaults')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function actorWithPermission(string $permission): User
    {
        Permission::findOrCreate($permission, 'web');

        $actor = User::factory()->create(['company_id' => 1]);
        $actor->givePermissionTo($permission);

        return $actor;
    }
}
