<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\APAgingService;
use App\Services\ARAgingService;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManagementReportTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    private User $user;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['purchase.reports.view', 'sales.reports.view', 'budgets.view', 'budgets.manage'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->company = Company::query()->firstOrFail();
        $this->otherCompany = Company::query()->create(['name' => 'Other management-report tenant']);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->user->givePermissionTo(['purchase.reports.view', 'sales.reports.view', 'budgets.view', 'budgets.manage']);
        Sanctum::actingAs($this->user);
    }

    public function test_ap_and_ar_aging_ignore_client_company_id_and_use_authenticated_tenant(): void
    {
        $ap = Mockery::mock(APAgingService::class);
        $ap->shouldReceive('generateReport')->once()->with($this->company->id)->andReturn([]);
        $this->app->instance(APAgingService::class, $ap);
        $this->getJson('/api/v1/purchase/ap-aging?company_id='.$this->otherCompany->id)->assertOk()->assertExactJson([]);

        $ar = Mockery::mock(ARAgingService::class);
        $ar->shouldReceive('generateReport')->once()->with($this->company->id)->andReturn([]);
        $this->app->instance(ARAgingService::class, $ar);
        $this->getJson('/api/v1/sales/ar-aging?company_id='.$this->otherCompany->id)->assertOk()->assertExactJson([]);
    }

    public function test_ap_and_ar_aging_forward_an_explicit_cutoff_without_changing_legacy_response_shape(): void
    {
        $row = ['supplier_code' => 'SUP-1', 'supplier_name' => 'Supplier', 'total_due' => '10.00', 'current' => '10.00', 'days_1_30' => '0.00', 'days_31_60' => '0.00', 'days_over_60' => '0.00'];
        $ap = Mockery::mock(APAgingService::class);
        $ap->shouldReceive('generateReport')->once()->with($this->company->id, '2026-08-26')->andReturn([$row]);
        $this->app->instance(APAgingService::class, $ap);
        $this->getJson('/api/v1/purchase/ap-aging?as_of_date=2026-08-26')->assertOk()->assertExactJson([$row]);

        $arRow = ['customer_code' => 'CUS-1', 'customer_name' => 'Customer', 'total_due' => '12.00', 'current' => '12.00', 'days_1_30' => '0.00', 'days_31_60' => '0.00', 'days_over_60' => '0.00'];
        $ar = Mockery::mock(ARAgingService::class);
        $ar->shouldReceive('generateReport')->once()->with($this->company->id, '2026-08-26')->andReturn([$arRow]);
        $this->app->instance(ARAgingService::class, $ar);
        $this->getJson('/api/v1/sales/ar-aging?as_of_date=2026-08-26')->assertOk()->assertExactJson([$arRow]);
    }

    public function test_budget_report_and_write_ignore_client_company_id_and_use_authenticated_tenant(): void
    {
        $budget = Mockery::mock(BudgetService::class);
        $budget->shouldReceive('getBudgetVsActualReport')->once()->with($this->company->id, '2026')->andReturn([]);
        $budget->shouldReceive('setBudget')->once()->with($this->company->id, '2026', '642', ['jan' => '100.00'])->andReturn((object) ['id' => 1]);
        $this->app->instance(BudgetService::class, $budget);

        $this->getJson('/api/v1/budgets/report?company_id='.$this->otherCompany->id.'&year=2026')
            ->assertOk()->assertExactJson(['data' => []]);
        $this->postJson('/api/v1/budgets', [
            'company_id' => $this->otherCompany->id,
            'year' => '2026',
            'account_code' => '642',
            'amounts' => ['jan' => '100.00'],
        ])->assertOk();
    }

    public function test_management_report_routes_require_their_domain_permissions(): void
    {
        $withoutPermissions = User::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($withoutPermissions);

        $this->getJson('/api/v1/purchase/ap-aging')->assertForbidden();
        $this->getJson('/api/v1/sales/ar-aging')->assertForbidden();
        $this->getJson('/api/v1/budgets/report?year=2026')->assertForbidden();
        $this->postJson('/api/v1/budgets', [
            'year' => '2026', 'account_code' => '642', 'amounts' => [],
        ])->assertForbidden();
    }
}
