<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\AllocationAwareAgingV2Service;
use App\Services\InventoryMovementV2Adapter;
use App\Services\ManagementReportDefinitionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManagementReportV2BoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_v2_ap_aging_requires_an_explicit_iso_cutoff_before_definition_lookup(): void
    {
        $this->actingAsReportUser(['reports.view', 'purchase.reports.view']);

        $this->getJson('/api/v2/management-reports/ap-aging')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'The given data was invalid.')
            ->assertJsonStructure(['request_id', 'errors' => ['as_of_date']]);

        $this->getJson('/api/v2/management-reports/ap-aging?as_of_date=2026/08/22')
            ->assertUnprocessable();
    }

    public function test_v2_aging_routes_fail_closed_without_a_published_execution_definition(): void
    {
        $this->actingAsReportUser(['reports.view', 'purchase.reports.view', 'sales.reports.view']);

        foreach (['ap-aging', 'ar-aging'] as $report) {
            $this->getJson("/api/v2/management-reports/{$report}?as_of_date=2026-08-22")
                ->assertConflict()
                ->assertJsonPath('error_code', 'DEFINITION_UNAVAILABLE')
                ->assertJsonPath('error', 'The requested report definition is not available.')
                ->assertJsonStructure(['request_id']);
        }
    }

    public function test_v2_ap_aging_remains_fail_closed_when_effective_definition_lacks_complete_typed_lineage(): void
    {
        $actor = $this->actingAsReportUser(['reports.view', 'purchase.reports.view']);
        $lifecycle = app(ManagementReportDefinitionLifecycleService::class);
        $draft = $lifecycle->createDraft(
            $actor,
            'accounts_payable_aging.v2',
            'test-aging-v2',
            AllocationAwareAgingV2Service::supportedSourceContract('ap'),
            AllocationAwareAgingV2Service::supportedCalculationContract(),
        );
        $lifecycle->publish($actor, $draft);

        $this->getJson('/api/v2/management-reports/ap-aging?as_of_date=2026-08-22')
            ->assertConflict()
            ->assertJsonPath('error_code', 'DEFINITION_UNAVAILABLE');
    }

    public function test_v2_ap_aging_rechecks_role_state_for_a_persisted_pat(): void
    {
        foreach (['reports.view', 'purchase.reports.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = Company::query()->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::findOrCreate('accountant', 'web'));
        $user->givePermissionTo(['reports.view', 'purchase.reports.view']);
        $token = $user->createToken('management-report-v2', ['api'])->plainTextToken;

        // A canonical persisted bearer reaches the V2 controller, which
        // correctly fails closed at the missing execution definition.
        $this->withToken($token)
            ->getJson('/api/v2/management-reports/ap-aging?as_of_date=2026-08-22')
            ->assertConflict()
            ->assertJsonPath('error_code', 'DEFINITION_UNAVAILABLE');

        $user->syncRoles(Role::findOrCreate('legacy-owner', 'web'));
        app('auth')->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v2/management-reports/ap-aging?as_of_date=2026-08-22')
            ->assertUnauthorized();

        $user->syncRoles([
            Role::findOrCreate('admin', 'web'),
            Role::findOrCreate('accountant', 'web'),
        ]);
        app('auth')->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v2/management-reports/ap-aging?as_of_date=2026-08-22')
            ->assertUnauthorized();
    }

    public function test_v2_stock_movement_requires_an_exact_definition_and_returns_only_a_non_valuation_source_surface(): void
    {
        $actor = $this->actingAsReportUser(['reports.view', 'inventory.stock-report.view']);
        $lifecycle = app(ManagementReportDefinitionLifecycleService::class);

        $this->getJson('/api/v2/management-reports/stock-movement?from_date=2026-08-01&to_date=2026-08-31')
            ->assertConflict()
            ->assertJsonPath('error_code', 'DEFINITION_UNAVAILABLE');

        $lifecycle->publish($actor, $lifecycle->createDraft(
            $actor,
            'stock_movement_source.v2',
            'stock-source-v1',
            InventoryMovementV2Adapter::supportedSourceContract(),
            InventoryMovementV2Adapter::supportedCalculationContract(),
            InventoryMovementV2Adapter::supportedAmountContract(),
        ));

        $this->getJson('/api/v2/management-reports/stock-movement?from_date=2026-08-01&to_date=2026-08-31')
            ->assertOk()
            ->assertJsonPath('schema', 'stock-movement-source.v2')
            ->assertJsonPath('report_key', 'stock_movement_source.v2')
            ->assertJsonPath('definition_version', 'stock-source-v1')
            ->assertJsonPath('stock_balance_or_valuation', false)
            ->assertJsonPath('statutory_or_appendix_iv_certified', false)
            ->assertJsonPath('production_ready', false)
            ->assertJsonPath('movements', []);

        $this->getJson('/api/v2/management-reports/stock-movement?from_date=2026-02-31&to_date=2026-08-31')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'The given data was invalid.')
            ->assertJsonStructure(['errors' => ['from_date']]);
    }

    public function test_v2_budget_requires_fiscal_year_and_scenario_then_fails_closed_without_an_approved_definition(): void
    {
        $this->actingAsReportUser(['reports.view', 'budgets.view']);

        $this->getJson('/api/v2/management-reports/budget-vs-actual')
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['fiscal_year_id', 'scenario_id']]);

        $this->getJson('/api/v2/management-reports/budget-vs-actual?fiscal_year_id=1&scenario_id=1')
            ->assertConflict()
            ->assertJsonPath('error_code', 'DEFINITION_UNAVAILABLE');
    }

    private function actingAsReportUser(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = Company::query()->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }
}
