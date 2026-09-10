<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleAndPermissionSeederAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool { return false; }

    public function test_both_entry_points_keep_operational_permissions_and_privileged_boundaries_aligned(): void
    {
        foreach ([RolesAndPermissionsSeeder::class, RoleAndPermissionSeeder::class] as $seeder) {
            $this->seed($seeder);
            $admin = Role::findByName('admin');
            $accountant = Role::findByName('accountant');
            foreach (['settlement.allocations.create', 'borrowing.contracts.view', 'payroll.post', 'debt-adjustments.post',
                'master.accounts.view', 'master.accounts.create', 'master.accounts.update', 'master.voucher-settings.view', 'master.closing-rules.view',
                'fixed-assets.view', 'fixed-assets.create', 'fixed-assets.update', 'fixed-assets.delete', 'fixed-assets.post',
                'fixed-assets.disposals.create', 'fixed-assets.revaluations.create', 'fixed-assets.depreciation.run',
                'inventory.cost-calculation.run', 'inventory.transfers.delete', 'purchase.invoices.delete'] as $permission) {
                $this->assertTrue($accountant->hasPermissionTo($permission), $permission);
                $this->assertTrue($admin->hasPermissionTo($permission), $permission);
            }
            foreach (['manage_users', 'master.accounts.delete', 'master.accounts.transfer', 'payroll.unpost', 'debt-adjustments.reverse',
                'fixed-assets.unpost', 'fixed-assets.depreciation.unpost', 'fixed-assets.depreciation.delete',
                'accounting.account-mappings.approve', 'accounting.audit-trail.view', 'gl.periods.close'] as $permission) {
                $this->assertFalse($accountant->hasPermissionTo($permission), $permission);
                $this->assertTrue($admin->hasPermissionTo($permission), $permission);
            }
        }
    }

    public function test_seeded_roles_reach_validation_without_bypassing_accounting_controls(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $accountant = User::factory()->create(['company_id' => 1]);
        $accountant->assignRole('accountant');
        Sanctum::actingAs($accountant);
        $this->getJson('/api/v1/fixed-assets')->assertOk();
        $this->postJson('/api/v1/fixed-assets')->assertUnprocessable();
        $this->postJson('/api/v1/fixed-assets/999/unpost')->assertForbidden();

        $admin = User::factory()->create(['company_id' => 1]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/fixed-assets')->assertUnprocessable();
        $this->postJson('/api/v1/fixed-assets/999/unpost')->assertNotFound();
    }
}
