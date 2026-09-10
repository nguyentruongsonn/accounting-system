<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\AllocationAwareAgingV2Service;
use App\Services\InventoryMovementV2Adapter;
use App\Services\ManagementReportDefinitionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManagementReportCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_manifest_is_read_only_tenant_bound_and_explicitly_non_statutory(): void
    {
        foreach (['reports.view', 'purchase.reports.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $company = Company::query()->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo(['reports.view', 'purchase.reports.view']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/management-capabilities')
            ->assertOk()
            ->assertJsonPath('meta.manifest_version', 'management-report-capabilities.v1')
            ->assertJsonPath('meta.company_id', $company->id)
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('meta.statutory_or_appendix_iv_certified', false)
            ->assertJsonCount(5, 'capabilities');

        $this->assertSame(
            'Báo cáo nội bộ, chỉ đọc; số liệu lấy trực tiếp từ máy chủ theo bộ lọc.',
            $response->json('meta.disclaimer'),
        );
        $this->assertStringNotContainsString('BCTC', $response->json('meta.disclaimer'));
        $this->assertStringNotContainsString('TT99', $response->json('meta.disclaimer'));
        $this->assertStringNotContainsString('Phụ lục IV', $response->json('meta.disclaimer'));

        $capabilities = collect($response->json('capabilities'))->keyBy('key');
        $this->assertSame(true, $capabilities['accounts_receivable_aging']['tenant_enforced_at_endpoint']);
        $this->assertSame(true, $capabilities['budget_vs_actual']['tenant_boundary_ready']);
        $this->assertSame(false, $capabilities['budget_vs_actual']['production_ready']);
        $this->assertSame(true, $capabilities['stock_movement_balance']['tenant_enforced_at_endpoint']);
        $this->assertSame(false, $capabilities['stock_movement_balance']['production_ready']);
        $this->assertSame(['warehouse_id', 'item_id', 'from_date', 'to_date'], $capabilities['stock_movement_balance']['accepted_filters']);
        $this->assertSame(['items', 'opening_balance_inventory_lines', 'inventory_receipt_lines', 'inventory_issue_lines', 'inventory_movement_events'], $capabilities['stock_movement_balance']['source']);
        $this->assertStringContainsString('posted receipt/issue', $capabilities['stock_movement_balance']['observed_legacy_behavior']);
        $this->assertStringNotContainsString('purchase-invoice', $capabilities['stock_movement_balance']['observed_legacy_behavior']);
        $this->assertSame(null, $capabilities['stock_movement_balance']['definition_version']);
        $this->assertSame('purchase/ap-aging', $capabilities['accounts_payable_aging']['route']);
        $this->assertSame('purchase.reports.view', $capabilities['accounts_payable_aging']['required_permission']);
        $this->assertSame(true, $capabilities['accounts_payable_aging']['authorized_for_current_actor']);
        $this->assertSame('definition_unavailable', $capabilities['accounts_payable_aging']['v2_execution']['status']);
        $this->assertSame(false, $capabilities['accounts_payable_aging']['v2_execution']['available']);
        $this->assertSame(null, $capabilities['accounts_payable_aging']['v2_execution']['api_path']);
        $this->assertSame(null, $capabilities['accounts_payable_aging']['v2_execution']['http_method']);
        $this->assertArrayNotHasKey('calculation', $capabilities['accounts_payable_aging']);
        $this->assertArrayHasKey('observed_legacy_behavior', $capabilities['accounts_payable_aging']);
        $this->assertSame(['year'], $capabilities['budget_vs_actual']['accepted_filters']);
        $this->assertSame(['company_id'], $capabilities['budget_vs_actual']['tolerated_ignored_parameters']);
        $this->assertSame('management-report-capability.v1', $capabilities['accounts_payable_aging']['schema']);
        $this->assertSame(true, $capabilities['inventory_valuation_reconciliation']['available']);
        $this->assertSame('available', $capabilities['inventory_valuation_reconciliation']['status']);
        $this->assertSame('inventory/subledger-gl-reconciliations', $capabilities['inventory_valuation_reconciliation']['route']);
        $this->assertSame('/api/v1/inventory/subledger-gl-reconciliations', $capabilities['inventory_valuation_reconciliation']['api_path']);
        $this->assertSame('inventory.reconciliations.view', $capabilities['inventory_valuation_reconciliation']['required_permission']);
        $this->assertSame(false, $capabilities['inventory_valuation_reconciliation']['authorized_for_current_actor']);

        $expectedKeys = [
            'schema', 'key', 'label', 'route', 'api_path', 'http_method', 'status', 'available', 'implementation_status', 'read_only',
            'required_permission', 'authorized_for_current_actor', 'tenant_scope', 'tenant_enforced_at_endpoint',
            'date_semantics', 'accepted_filters', 'tolerated_ignored_parameters', 'source', 'observed_legacy_behavior',
            'definition_version', 'statutory_or_appendix_iv_certified', 'tenant_boundary_ready', 'production_ready', 'reason', 'v2_execution',
        ];
        foreach ($capabilities as $capability) {
            $this->assertSame($expectedKeys, array_keys($capability));
        }
        $this->assertSame('/api/v1/purchase/ap-aging', $capabilities['accounts_payable_aging']['api_path']);
        $this->assertSame('GET', $capabilities['accounts_payable_aging']['http_method']);

        foreach ($capabilities->where('available', true) as $capability) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($route) => $route->uri() === ltrim($capability['api_path'], '/'));
            $this->assertNotNull($route, "Missing declared route [{$capability['api_path']}].");
            $this->assertContains('GET', $route->methods());
            $this->assertContains('permission:'.$capability['required_permission'], $route->gatherMiddleware());
        }
    }

    public function test_manifest_requires_reporting_permission(): void
    {
        Sanctum::actingAs(User::factory()->create(['company_id' => 1]));

        $this->getJson('/api/v1/reports/management-capabilities')->assertForbidden();
    }

    public function test_manifest_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/management-capabilities')->assertUnauthorized();
    }

    public function test_manifest_accepts_a_persisted_pat_for_a_canonical_accountant(): void
    {
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = Company::query()->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::findOrCreate('accountant', 'web'));
        $user->givePermissionTo('reports.view');
        $token = $user->createToken('management-report-manifest', ['api'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/reports/management-capabilities')
            ->assertOk()
            ->assertJsonPath('meta.company_id', $company->id)
            ->assertJsonPath('meta.manifest_version', 'management-report-capabilities.v1');
    }

    public function test_manifest_marks_unavailable_module_permission_for_current_actor(): void
    {
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['company_id' => 1]);
        $user->givePermissionTo('reports.view');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/management-capabilities')->assertOk();
        $capabilities = collect($response->json('capabilities'))->keyBy('key');
        $this->assertSame(false, $capabilities['accounts_payable_aging']['authorized_for_current_actor']);
    }

    public function test_manifest_binds_company_id_to_the_authenticated_actor_not_a_client_parameter(): void
    {
        foreach (['reports.view', 'purchase.reports.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $otherCompany = Company::query()->create(['name' => 'Other management capability tenant']);
        $user = User::factory()->create(['company_id' => $otherCompany->id]);
        $user->givePermissionTo(['reports.view', 'purchase.reports.view']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/management-capabilities?company_id=1')
            ->assertOk()
            ->assertJsonPath('meta.company_id', $otherCompany->id)
            ->assertJsonPath('capabilities.0.authorized_for_current_actor', true);
    }

    public function test_manifest_advertises_v2_transport_details_only_after_the_registry_resolves_an_effective_definition(): void
    {
        foreach (['reports.view', 'purchase.reports.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = Company::query()->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo(['reports.view', 'purchase.reports.view']);
        app(ManagementReportDefinitionLifecycleService::class)->publish(
            $user,
            app(ManagementReportDefinitionLifecycleService::class)->createDraft(
                $user,
                'accounts_payable_aging.v2',
                '2026.08.1',
                ['sources' => ['purchase_invoices'], 'cutoff' => 'as_of_date'],
                ['outstanding' => 'allocation-aware'],
            ),
        );
        Sanctum::actingAs($user);

        $v2 = collect($this->getJson('/api/v1/reports/management-capabilities')
            ->assertOk()
            ->json('capabilities'))
            ->keyBy('key')
            ->get('accounts_payable_aging')['v2_execution'];

        $this->assertSame(false, $v2['available']);
        $this->assertSame('definition_effective_source_lineage_incomplete', $v2['status']);
        $this->assertSame('management-reports/ap-aging', $v2['route']);
        $this->assertSame('/api/v2/management-reports/ap-aging', $v2['api_path']);
        $this->assertSame('GET', $v2['http_method']);
        $this->assertSame('2026.08.1', $v2['definition_version']);
        $this->assertSame(false, $v2['production_ready']);
    }

    public function test_manifest_keeps_allocation_aging_fail_closed_until_typed_lineage_is_complete(): void
    {
        foreach (['reports.view', 'purchase.reports.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = Company::query()->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo(['reports.view', 'purchase.reports.view']);
        $lifecycle = app(ManagementReportDefinitionLifecycleService::class);
        $lifecycle->publish($user, $lifecycle->createDraft(
            $user,
            'accounts_payable_aging.v2',
            'allocation-v1',
            AllocationAwareAgingV2Service::supportedSourceContract('ap'),
            AllocationAwareAgingV2Service::supportedCalculationContract(),
        ));
        Sanctum::actingAs($user);

        $v2 = collect($this->getJson('/api/v1/reports/management-capabilities')
            ->assertOk()
            ->json('capabilities'))
            ->keyBy('key')
            ->get('accounts_payable_aging')['v2_execution'];

        $this->assertSame(false, $v2['available']);
        $this->assertSame('definition_effective_source_lineage_incomplete', $v2['status']);
        $this->assertSame('allocation-v1', $v2['definition_version']);
        $this->assertSame(['fx_revaluation'], $v2['incomplete_sources']);
        $codes = array_column($v2['missing_conditions'], 'code');
        $this->assertContains('immutable_cutoff_snapshot_evidence_not_implemented', $codes);
        $this->assertContains('apar_subledger_to_gl_reconciliation_not_executable', $codes);
        $this->assertSame(false, $v2['production_ready']);
    }

    public function test_manifest_advertises_only_the_narrow_non_valuation_stock_movement_source_when_its_exact_contract_is_effective(): void
    {
        foreach (['reports.view', 'inventory.stock-report.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = Company::query()->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo(['reports.view', 'inventory.stock-report.view']);
        $lifecycle = app(ManagementReportDefinitionLifecycleService::class);
        $lifecycle->publish($user, $lifecycle->createDraft(
            $user,
            'stock_movement_source.v2',
            'stock-source-v1',
            InventoryMovementV2Adapter::supportedSourceContract(),
            InventoryMovementV2Adapter::supportedCalculationContract(),
            InventoryMovementV2Adapter::supportedAmountContract(),
        ));
        Sanctum::actingAs($user);

        $v2 = collect($this->getJson('/api/v1/reports/management-capabilities')
            ->assertOk()
            ->json('capabilities'))
            ->keyBy('key')
            ->get('stock_movement_balance')['v2_execution'];

        $this->assertSame(true, $v2['available']);
        $this->assertSame('operational_definition_effective', $v2['status']);
        $this->assertSame('management-reports/stock-movement', $v2['route']);
        $this->assertSame(true, $v2['completeness_ready']);
        $this->assertSame(false, $v2['production_ready']);
    }
}
