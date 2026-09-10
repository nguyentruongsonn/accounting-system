<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetFiscalContextAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_fiscal_year_is_not_mistaken_for_an_approved_budget_report_definition(): void
    {
        $company = Company::query()->firstOrFail();
        $fiscalYear = FiscalYear::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();

        $availability = app(BudgetService::class)->fiscalContextAvailability($company->id, $fiscalYear->id);

        $this->assertSame([
            'available' => false,
            'code' => 'DEFINITION_UNAVAILABLE',
            'fiscal_year_id' => $fiscalYear->id,
            'fiscal_year_context_found' => true,
            'missing_contracts' => [
                'approved_published_report_definition',
                'approved_budget_scenario_and_version',
                'approved_effective_dated_account_dimension_sign_mapping',
            ],
        ], $availability);
    }

    public function test_a_foreign_fiscal_year_is_not_disclosed_or_accepted_as_context(): void
    {
        $company = Company::query()->firstOrFail();
        $otherCompany = Company::query()->create(['name' => 'Other budget tenant']);
        $foreignFiscalYear = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        $availability = app(BudgetService::class)->fiscalContextAvailability($company->id, $foreignFiscalYear->id);

        $this->assertSame(false, $availability['available']);
        $this->assertSame('DEFINITION_UNAVAILABLE', $availability['code']);
        $this->assertNull($availability['fiscal_year_id']);
        $this->assertFalse($availability['fiscal_year_context_found']);
        $this->assertSame('tenant_fiscal_year_context', $availability['missing_contracts'][0]);
    }
}
