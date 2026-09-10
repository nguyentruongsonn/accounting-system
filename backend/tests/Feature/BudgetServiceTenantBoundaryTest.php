<?php

namespace Tests\Feature;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\Budget;
use App\Models\Company;
use App\Models\User;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BudgetServiceTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_budget_service_cannot_select_a_foreign_company(): void
    {
        $companyA = Company::create(['name' => 'Budget direct actor', 'tax_code' => 'BUDGET-DIRECT-A']);
        $companyB = Company::create(['name' => 'Budget direct foreign', 'tax_code' => 'BUDGET-DIRECT-B']);
        $actor = User::factory()->create(['company_id' => $companyA->id]);
        Sanctum::actingAs($actor);
        $service = app(BudgetService::class);

        foreach ([
            fn () => $service->setBudget($companyB->id, '2026', '642', ['jan' => 100]),
            fn () => $service->getBudgetVsActualReport($companyB->id, '2026'),
            fn () => $service->fiscalContextAvailability($companyB->id, 1),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An authenticated budget service caller must not select a foreign company.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('company_id', $exception->errors());
            }
        }

        $this->assertFalse(Budget::withoutGlobalScopes()->where('company_id', $companyB->id)->exists());
    }

    public function test_production_budget_report_fails_closed_until_an_approved_definition_exists(): void
    {
        $company = Company::create(['name' => 'Budget production gate', 'tax_code' => 'BUDGET-PROD-GATE']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($actor);
        Config::set('app.env', 'production');

        try {
            app(BudgetService::class)->getBudgetVsActualReport($company->id, '2026');
            $this->fail('The non-certified budget report must not emit production financial output.');
        } catch (ReportDefinitionUnavailableException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(ReportDefinitionUnavailableException::ERROR_CODE, $exception::ERROR_CODE);
        } finally {
            Config::set('app.env', 'testing');
        }
    }

    public function test_production_budget_report_api_exposes_the_stable_definition_unavailable_contract(): void
    {
        $company = Company::create(['name' => 'Budget production API gate', 'tax_code' => 'BUDGET-PROD-API']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        Permission::firstOrCreate(['name' => 'budgets.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->givePermissionTo('budgets.view');
        Sanctum::actingAs($actor);
        Config::set('app.env', 'production');

        try {
            $this->getJson('/api/v1/budgets/report?year=2026')
                ->assertConflict()
                ->assertJsonPath('error', 'The requested report definition is not available.')
                ->assertJsonPath('error_code', ReportDefinitionUnavailableException::ERROR_CODE);
        } finally {
            Config::set('app.env', 'testing');
        }
    }
}
