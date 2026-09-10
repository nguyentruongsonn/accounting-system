<?php

namespace Tests;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Legacy functional tests predate route-level RBAC and are intended to
     * exercise accounting behaviour, not authorization. Authorization tests
     * override this hook so their actors remain genuinely permissionless.
     */
    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return true;
    }

    /**
     * Grant explicit GL/report permissions to functional tests that exercise
     * accounting behavior rather than authorization behavior.
     */
    protected function grantGlReportPermissions(User $user): void
    {
        $permissions = [
            'cash.receipts.view',
            'cash.receipts.create',
            'cash.receipts.update',
            'cash.receipts.delete',
            'cash.receipts.post',
            'cash.receipts.unpost',
            'cash.receipts.void',
            'cash.payments.view',
            'cash.payments.create',
            'cash.payments.update',
            'cash.payments.delete',
            'cash.payments.post',
            'cash.payments.unpost',
            'cash.payments.void',
            'cash.forecasts.view',
            'cash.forecasts.create',
            'cash.payment-requests.view',
            'cash.payment-requests.create',
            'cash.payment-requests.update',
            'cash.payment-requests.delete',
            'cash.payment-requests.submit',
            'cash.advance-settlements.view',
            'cash.advance-settlements.create',
            'cash.advance-settlements.update',
            'cash.advance-settlements.delete',
            'cash.advance-settlements.submit',
            'cash.inventories.view',
            'cash.inventories.create',
            'cash.inventories.delete',
            'borrowing.contracts.view',
            'borrowing.contracts.create',
            'payroll.view',
            'payroll.create',
            'payroll.post',
            'payroll.unpost',
            'debt-adjustments.view',
            'debt-adjustments.create',
            'debt-adjustments.post',
            'debt-adjustments.reverse',
            'settlement.allocations.reverse',
            'ap-ar-fx-revaluations.view',
            'ap-ar-fx-revaluations.create',
            'ap-ar-fx-revaluations.post',
            'ap-ar-fx-revaluations.reverse',
            'bank.accounts.view',
            'bank.accounts.create',
            'bank.accounts.update',
            'bank.accounts.delete',
            'bank.receipts.view',
            'bank.receipts.create',
            'bank.receipts.update',
            'bank.receipts.delete',
            'bank.receipts.post',
            'bank.receipts.unpost',
            'bank.receipts.void',
            'bank.payments.view',
            'bank.payments.create',
            'bank.payments.update',
            'bank.payments.delete',
            'bank.payments.post',
            'bank.payments.unpost',
            'bank.payments.void',
            'inventory.items.view',
            'inventory.items.create',
            'inventory.items.update',
            'inventory.items.delete',
            'inventory.receipts.view',
            'inventory.receipts.create',
            'inventory.receipts.update',
            'inventory.receipts.delete',
            'inventory.receipts.post',
            'inventory.receipts.unpost',
            'inventory.receipts.void',
            'inventory.issues.view',
            'inventory.issues.create',
            'inventory.issues.update',
            'inventory.issues.delete',
            'inventory.issues.post',
            'inventory.issues.unpost',
            'inventory.issues.void',
            'inventory.stock-report.view',
            'inventory.transfers.view',
            'inventory.transfers.create',
            'inventory.transfers.update',
            'inventory.transfers.delete',
            'inventory.stock-counts.view',
            'inventory.stock-counts.create',
            'inventory.stock-counts.update',
            'inventory.stock-counts.delete',
            'budgets.view',
            'budgets.manage',
            'inventory.cost-calculation.run',
            'tools.equipment.allocate',
            'costing.allocate',
            'voucher-references.view',
            'voucher-references.resolve-defaults',
            'purchase.dashboard.view',
            'purchase.orders.view',
            'purchase.orders.create',
            'purchase.orders.update',
            'purchase.orders.delete',
            'purchase.contracts.view',
            'purchase.contracts.create',
            'purchase.contracts.update',
            'purchase.contracts.delete',
            'purchase.invoices.view',
            'purchase.invoices.create',
            'purchase.invoices.update',
            'purchase.invoices.delete',
            'purchase.invoices.post',
            'purchase.invoices.unpost',
            'purchase.invoices.void',
            'purchase.returns.view',
            'purchase.returns.create',
            'purchase.returns.update',
            'purchase.returns.delete',
            'purchase.returns.post',
            'purchase.returns.unpost',
            'purchase.returns.void',
            'purchase.discounts.view',
            'purchase.discounts.create',
            'purchase.discounts.update',
            'purchase.discounts.delete',
            'purchase.discounts.post',
            'purchase.discounts.unpost',
            'purchase.discounts.void',
            'purchase.reports.view',
            'sales.quotes.view',
            'sales.quotes.create',
            'sales.quotes.update',
            'sales.quotes.delete',
            'sales.quotes.unpost',
            'sales.orders.view',
            'sales.orders.create',
            'sales.orders.update',
            'sales.orders.delete',
            'sales.orders.unpost',
            'sales.invoices.view',
            'sales.invoices.create',
            'sales.invoices.update',
            'sales.invoices.delete',
            'sales.invoices.post',
            'sales.invoices.unpost',
            'sales.invoices.void',
            'sales.returns.view',
            'sales.returns.create',
            'sales.returns.update',
            'sales.returns.delete',
            'sales.returns.post',
            'sales.returns.unpost',
            'sales.returns.void',
            'sales.discounts.view',
            'sales.discounts.create',
            'sales.discounts.update',
            'sales.discounts.delete',
            'sales.discounts.post',
            'sales.discounts.unpost',
            'sales.discounts.void',
            'sales.reports.view',
            'master.company.view',
            'master.company.update',
            'master.accounts.view',
            'master.accounts.create',
            'master.accounts.update',
            'master.accounts.delete',
            'master.customers.view',
            'master.customers.create',
            'master.customers.update',
            'master.customers.delete',
            'master.suppliers.view',
            'master.suppliers.create',
            'master.suppliers.update',
            'master.suppliers.delete',
            'master.employees.view',
            'master.employees.create',
            'master.employees.update',
            'master.employees.delete',
            'master.payment-terms.view',
            'master.payment-terms.create',
            'master.payment-terms.update',
            'master.payment-terms.delete',
            'master.item-categories.view',
            'master.item-categories.create',
            'master.item-categories.update',
            'master.item-categories.delete',
            'master.units.view',
            'master.units.create',
            'master.units.update',
            'master.units.delete',
            'master.warehouses.view',
            'master.warehouses.create',
            'master.warehouses.update',
            'master.warehouses.delete',
            'master.voucher-settings.view',
            'master.voucher-settings.create',
            'master.voucher-settings.update',
            'master.voucher-settings.delete',
            'gl.journal-entries.view',
            'gl.journal-entries.create',
            'gl.journal-entries.update',
            'gl.journal-entries.delete',
            'gl.journal-entries.post',
            'gl.journal-entries.reverse',
            'gl.periods.view',
            'gl.periods.close',
            'reports.view',
            'fixed-assets.view',
            'fixed-assets.create',
            'fixed-assets.update',
            'fixed-assets.delete',
            'fixed-assets.post',
            'fixed-assets.unpost',
            'fixed-assets.disposals.view',
            'fixed-assets.disposals.create',
            'fixed-assets.revaluations.view',
            'fixed-assets.revaluations.create',
            'fixed-assets.depreciation.view',
            'fixed-assets.depreciation.run',
            'fixed-assets.depreciation.unpost',
            'fixed-assets.depreciation.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user->givePermissionTo($permissions);
    }

    protected function configureAccountingTenant(
        User $user,
        Company $company,
        int $year = 2026
    ): FiscalYear {
        $user->update(['company_id' => $company->id]);

        return FiscalYear::firstOrCreate(
            ['company_id' => $company->id, 'year' => $year],
            [
                'start_date' => "{$year}-01-01",
                'end_date' => "{$year}-12-31",
                'status' => 'open',
            ]
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the deterministic tenant id used by legacy fixtures even on
        // MySQL, whose auto-increment counter is not rewound by a rolled-back
        // test transaction. `id` is guarded on the model, so the explicit key
        // must be created inside an unguarded block.
        Company::unguarded(static function (): void {
            Company::firstOrCreate(
                ['id' => 1],
                ['name' => 'Test Company', 'tax_code' => '123', 'address' => '123']
            );
        });

        // Ensure FiscalYear with ID 1 exists for JournalEntryService
        FiscalYear::unguarded(static function (): void {
            FiscalYear::firstOrCreate(
                ['id' => 1],
                [
                    'company_id' => 1,
                    'year' => 2026,
                    'start_date' => '2026-01-01',
                    'end_date' => '2026-12-31',
                    'status' => 'open',
                ]
            );
        });

        // Pre-seed Spatie permissions for web guard
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'view_reports',    'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create_documents', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'post_documents',   'guard_name' => 'web']);

        if ($this->grantGlReportPermissionsToLegacyActors()) {
            User::created(function (User $user): void {
                $this->grantGlReportPermissions($user);
            });
        }
    }
}
