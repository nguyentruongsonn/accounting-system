<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PurchaseSalesMasterRbacTest extends TestCase
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

    public function test_routes_require_authentication_and_explicit_permissions(): void
    {
        $this->getJson('/api/v1/purchase/orders')->assertUnauthorized();
        $this->getJson('/api/v1/sales/invoices')->assertUnauthorized();
        $this->getJson('/api/v1/master/customers')->assertUnauthorized();
        $this->getJson('/api/v1/payroll')->assertUnauthorized();
        $this->getJson('/api/v1/borrowing-contracts')->assertUnauthorized();

        $this->actingAsUser();

        $this->getJson('/api/v1/purchase/dashboard')->assertForbidden();
        $this->getJson('/api/v1/purchase/orders')->assertForbidden();
        $this->getJson('/api/v1/sales/invoices')->assertForbidden();
        $this->getJson('/api/v1/master/customers')->assertForbidden();
        $this->getJson('/api/v1/payroll')->assertForbidden();
        $this->getJson('/api/v1/borrowing-contracts')->assertForbidden();
    }

    public function test_explicit_read_permissions_cannot_prepare_or_mutate(): void
    {
        $viewer = $this->actingAsUser();
        $viewer->givePermissionTo(['purchase.orders.view', 'purchase.invoices.view', 'sales.quotes.view', 'sales.invoices.view', 'master.customers.view', 'master.voucher-settings.view']);
        [$purchaseInvoice, $salesInvoice] = $this->invoices();

        $this->getJson('/api/v1/purchase/orders')->assertOk();
        $this->getJson('/api/v1/purchase/invoices')->assertOk();
        $this->getJson('/api/v1/sales/quotes')->assertOk();
        $this->getJson('/api/v1/sales/invoices')->assertOk();
        $this->getJson('/api/v1/master/customers')->assertOk();
        $this->getJson('/api/v1/master/voucher-type-settings/types')->assertOk();

        $this->getJson('/api/v1/purchase/orders/next-code')->assertForbidden();
        $this->postJson("/api/v1/purchase/invoices/{$purchaseInvoice->id}/post")->assertForbidden();
        $this->postJson("/api/v1/sales/invoices/{$salesInvoice->id}/void")->assertForbidden();
        $this->postJson('/api/v1/master/customers')->assertForbidden();
        $this->putJson('/api/v1/master/company/1')->assertForbidden();

        $this->assertFalse($viewer->can('purchase.invoices.create'));
        $this->assertFalse($viewer->can('sales.invoices.post'));
        $this->assertFalse($viewer->can('master.company.update'));
        $this->assertFalse($viewer->can('payroll.post'));
    }

    public function test_cash_permission_does_not_imply_purchase_sales_or_master_access(): void
    {
        $cashier = $this->actingAsUser();
        $cashier->givePermissionTo('cash.receipts.create');

        $this->getJson('/api/v1/purchase/orders')->assertForbidden();
        $this->getJson('/api/v1/sales/orders')->assertForbidden();
        $this->getJson('/api/v1/master/suppliers')->assertForbidden();

        $this->assertFalse($cashier->can('purchase.orders.view'));
        $this->assertFalse($cashier->can('sales.orders.view'));
        $this->assertFalse($cashier->can('master.suppliers.view'));
    }

    public function test_accountant_can_post_but_reversal_and_settings_actions_require_admin(): void
    {
        $accountant = $this->actingAsRole('accountant');
        [$purchaseInvoice, $salesInvoice] = $this->invoices();

        $this->assertTrue($accountant->can('purchase.invoices.create'));
        $this->assertTrue($accountant->can('purchase.invoices.post'));
        $this->assertTrue($accountant->can('sales.invoices.post'));
        $this->assertTrue($accountant->can('master.customers.update'));
        $this->assertTrue($accountant->can('payroll.post'));
        $this->assertFalse($accountant->can('payroll.unpost'));
        $this->assertFalse($accountant->can('purchase.invoices.unpost'));
        $this->assertFalse($accountant->can('sales.invoices.void'));
        $this->assertFalse($accountant->can('master.company.update'));
        $this->assertFalse($accountant->can('master.voucher-settings.update'));

        $this->postJson("/api/v1/purchase/invoices/{$purchaseInvoice->id}/unpost")->assertForbidden();
        $this->postJson("/api/v1/sales/invoices/{$salesInvoice->id}/void")->assertForbidden();
        $this->putJson('/api/v1/master/company/1')->assertForbidden();
        $this->putJson('/api/v1/master/voucher-type-settings/999')->assertForbidden();

        $chief = $this->actingAsRole('admin');

        $this->assertTrue($chief->can('purchase.invoices.unpost'));
        $this->assertTrue($chief->can('sales.invoices.void'));
        $this->assertTrue($chief->can('master.company.update'));
        $this->assertTrue($chief->can('master.voucher-settings.update'));
        $this->assertTrue($chief->can('payroll.unpost'));
    }

    public function test_one_action_permission_does_not_authorize_sibling_actions(): void
    {
        $user = $this->actingAsUser();
        $user->givePermissionTo('sales.invoices.post');
        [, $salesInvoice] = $this->invoices();

        $this->getJson('/api/v1/sales/invoices')->assertForbidden();
        $this->postJson("/api/v1/sales/invoices/{$salesInvoice->id}/unpost")->assertForbidden();
        $this->postJson("/api/v1/sales/invoices/{$salesInvoice->id}/void")->assertForbidden();
        $this->getJson('/api/v1/purchase/invoices')->assertForbidden();
    }

    public function test_borrowing_contract_creation_uses_authenticated_company_not_client_company(): void
    {
        $accountant = $this->actingAsRole('accountant');
        $foreignCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Foreign borrowing company',
            'tax_code' => 'RBAC-BORROW-'.uniqid(),
            'address' => 'Foreign address',
        ]);

        $response = $this->postJson('/api/v1/borrowing-contracts', [
            'company_id' => $foreignCompany->id,
            'contract_number' => 'RBAC-BORROW-'.uniqid(),
            'lender_name' => 'Test lender',
            'amount' => '1000000.00',
            'disbursement_date' => '2026-08-22',
            'maturity_date' => '2027-08-22',
        ]);

        $response->assertCreated()
            ->assertJsonPath('company_id', $accountant->company_id);

        $this->assertDatabaseHas('borrowing_contracts', [
            'id' => $response->json('id'),
            'company_id' => $accountant->company_id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $accountant->company_id,
            'action' => 'borrowing_contract.created',
            'model_id' => $response->json('id'),
        ]);
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

    /** @return array{PurchaseInvoice, SalesInvoice} */
    private function invoices(): array
    {
        $supplier = Supplier::withoutGlobalScopes()->create([
            'company_id' => 1,
            'code' => 'RBAC-SUP-'.uniqid(),
            'name' => 'RBAC Supplier',
        ]);
        $customer = Customer::withoutGlobalScopes()->create([
            'company_id' => 1,
            'code' => 'RBAC-CUS-'.uniqid(),
            'name' => 'RBAC Customer',
        ]);

        return [
            PurchaseInvoice::withoutGlobalScopes()->create([
                'company_id' => 1,
                'supplier_id' => $supplier->id,
                'invoice_number' => 'RBAC-PI-'.uniqid(),
                'invoice_date' => '2026-08-21',
                'accounting_date' => '2026-08-21',
            ]),
            SalesInvoice::withoutGlobalScopes()->create([
                'company_id' => 1,
                'customer_id' => $customer->id,
                'invoice_number' => 'RBAC-SI-'.uniqid(),
                'invoice_date' => '2026-08-21',
                'accounting_date' => '2026-08-21',
            ]),
        ];
    }
}
