<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CashBankInventoryRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_module_routes_require_authentication_and_explicit_permissions(): void
    {
        $this->getJson('/api/v1/cash/receipts')->assertUnauthorized();
        $this->getJson('/api/v1/cash-forecasts')->assertUnauthorized();
        $this->getJson('/api/v1/cash-inventories')->assertUnauthorized();
        $this->getJson('/api/v1/cash/book-balance?account_code=1111&as_of_date=2026-01-31')->assertUnauthorized();
        $this->getJson('/api/v1/bank/receipts')->assertUnauthorized();
        $this->getJson('/api/v1/inventory/items')->assertUnauthorized();

        $this->actingAsUser();

        $this->getJson('/api/v1/cash/receipts')->assertForbidden();
        $this->getJson('/api/v1/cash-forecasts')->assertForbidden();
        $this->getJson('/api/v1/cash-inventories')->assertForbidden();
        $this->getJson('/api/v1/bank/accounts')->assertForbidden();
        $this->getJson('/api/v1/inventory/stock-report')->assertForbidden();
        $this->postJson('/api/v1/inventory/cost-calculation/run')->assertForbidden();
    }

    public function test_explicit_read_permissions_do_not_grant_writes_across_cash_bank_and_inventory(): void
    {
        $viewer = $this->actingAsUser();
        $viewer->givePermissionTo(['cash.receipts.view', 'cash.payments.view', 'cash.forecasts.view', 'cash.inventories.view', 'bank.receipts.view', 'bank.accounts.view', 'inventory.items.view', 'inventory.stock-report.view']);

        $this->getJson('/api/v1/cash/receipts')->assertOk();
        $this->getJson('/api/v1/cash-forecasts')->assertOk();
        $this->getJson('/api/v1/cash-inventories')->assertOk();
        $this->getJson('/api/v1/cash/book-balance?account_code=1111&as_of_date=2026-01-31')->assertStatus(422);
        $this->getJson('/api/v1/bank/receipts')->assertOk();
        $this->getJson('/api/v1/bank/accounts')->assertOk();
        $this->getJson('/api/v1/inventory/items')->assertOk();
        $this->getJson('/api/v1/inventory/stock-report')->assertOk();

        $this->getJson('/api/v1/cash/receipts/next-code')->assertForbidden();
        $this->postJson('/api/v1/cash-forecasts')->assertForbidden();
        $this->postJson('/api/v1/cash-inventories')->assertForbidden();
        $this->postJson('/api/v1/cash/receipts/999/post')->assertForbidden();
        $this->postJson('/api/v1/bank/receipts/999/void')->assertForbidden();
        $this->deleteJson('/api/v1/inventory/items/999')->assertForbidden();
        $this->postJson('/api/v1/inventory/cost-calculation/run')->assertForbidden();

        $this->assertFalse($viewer->can('cash.receipts.create'));
        $this->assertFalse($viewer->can('cash.forecasts.create'));
        $this->assertFalse($viewer->can('cash.inventories.create'));
        $this->assertFalse($viewer->can('bank.receipts.post'));
        $this->assertFalse($viewer->can('inventory.cost-calculation.run'));
    }

    public function test_explicit_preparation_permissions_cannot_post_or_void(): void
    {
        $cashier = $this->actingAsUser();
        $cashier->givePermissionTo(['cash.receipts.view', 'cash.receipts.create', 'bank.payments.view', 'bank.payments.create', 'bank.receipts.create', 'cash.forecasts.create', 'cash.inventories.create']);

        $this->getJson('/api/v1/cash/receipts')->assertOk();
        $this->getJson('/api/v1/cash/receipts/next-code')->assertOk();
        $this->getJson('/api/v1/bank/payments')->assertOk();
        $this->getJson('/api/v1/bank/payments/next-code')->assertOk();

        $this->postJson('/api/v1/cash/receipts/999/post')->assertForbidden();
        $this->postJson('/api/v1/cash/receipts/999/unpost')->assertForbidden();
        $this->postJson('/api/v1/bank/payments/999/void')->assertForbidden();
        $this->deleteJson('/api/v1/bank/payments/999')->assertForbidden();
        $this->getJson('/api/v1/inventory/items')->assertForbidden();

        $this->assertTrue($cashier->can('bank.receipts.create'));
        $this->assertTrue($cashier->can('cash.forecasts.create'));
        $this->assertTrue($cashier->can('cash.inventories.create'));
        $this->assertFalse($cashier->can('cash.inventories.delete'));
        $this->assertFalse($cashier->can('cash.receipts.post'));
        $this->assertFalse($cashier->can('bank.payments.void'));
    }

    public function test_accountant_and_admin_have_distinct_lifecycle_authority(): void
    {
        $accountant = $this->actingAsRole('accountant');

        $this->assertTrue($accountant->can('cash.receipts.post'));
        $this->assertTrue($accountant->can('bank.payments.post'));
        $this->assertTrue($accountant->can('inventory.receipts.post'));
        $this->assertFalse($accountant->can('cash.receipts.unpost'));
        $this->assertFalse($accountant->can('bank.payments.void'));
        $this->assertTrue($accountant->can('inventory.cost-calculation.run'));

        $this->postJson('/api/v1/cash/receipts/999/unpost')->assertForbidden();
        // The shared PHPUnit harness opts legacy fixtures out of the
        // production inventory mapping gate. Turn this control back on for
        // the explicit role-boundary assertion: a cost run must fail closed
        // until the tenant has approved inventory account mappings.
        config()->set('accounting.enforce_inventory_posting_account_mappings', true);
        try {
            $this->postJson('/api/v1/inventory/cost-calculation/run')->assertUnprocessable();
        } finally {
            config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        }

        $chief = $this->actingAsRole('admin');

        $this->assertTrue($chief->can('cash.receipts.unpost'));
        $this->assertTrue($chief->can('bank.payments.void'));
        $this->assertTrue($chief->can('inventory.cost-calculation.run'));
    }

    public function test_action_permission_does_not_leak_to_other_actions(): void
    {
        $user = $this->actingAsUser();
        $user->givePermissionTo('cash.receipts.post');

        $this->getJson('/api/v1/cash/receipts')->assertForbidden();
        $this->postJson('/api/v1/cash/receipts/999/void')->assertForbidden();
        $this->postJson('/api/v1/cash/receipts/999/unpost')->assertForbidden();
    }

    private function actingAsRole(string $role): User
    {
        $user = $this->actingAsUser();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['company_id' => 1]);
        Sanctum::actingAs($user);

        return $user;
    }
}
