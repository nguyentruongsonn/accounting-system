<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancialReportContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user = User::factory()->create(['company_id' => 1]);
        $this->user->assignRole($role);
        $this->user->givePermissionTo('reports.view');
        Sanctum::actingAs($this->user);

        $this->fiscalYear = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', 1)
            ->firstOrFail();
    }

    public function test_all_supported_report_endpoints_resolve_one_tenant_bound_fiscal_context(): void
    {
        $query = http_build_query([
            'company_id' => 99999,
            'fiscal_year_id' => $this->fiscalYear->id,
            'from_date' => $this->fiscalYear->start_date->toDateString(),
            'to_date' => $this->fiscalYear->end_date->toDateString(),
            'include_metadata' => 1,
        ]);

        foreach ([
            '/api/v1/reports/balance-sheet?'.$query,
            '/api/v1/reports/income-statement?'.$query,
            '/api/v1/reports/trial-balance?'.$query,
            '/api/v1/reports/general-journal?'.$query,
            '/api/v1/reports/general-ledger?account_code=111&'.$query,
        ] as $url) {
            $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('meta.company_id', $this->user->company_id)
                ->assertJsonPath('meta.fiscal_year_id', $this->fiscalYear->id)
                ->assertJsonPath('meta.accounting_regime', 'TT99')
                ->assertJsonPath('meta.effective_from', $this->fiscalYear->start_date->toDateString());
        }
    }

    public function test_foreign_fiscal_year_and_out_of_year_dates_are_rejected_before_report_execution(): void
    {
        $otherCompany = Company::create(['name' => 'Foreign report company', 'tax_code' => 'FOREIGN-123']);
        $foreignYear = FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $otherCompany->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        $this->getJson('/api/v1/reports/trial-balance?fiscal_year_id='.$foreignYear->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('fiscal_year_id');

        $this->getJson('/api/v1/reports/income-statement?'.http_build_query([
            'fiscal_year_id' => $this->fiscalYear->id,
            'from_date' => '2025-12-31',
            'to_date' => '2026-01-31',
        ]))->assertStatus(422)->assertJsonValidationErrors('date_range');
    }

    public function test_invalid_date_format_and_reversed_date_range_fail_closed(): void
    {
        $base = 'fiscal_year_id='.$this->fiscalYear->id.'&';

        $this->getJson('/api/v1/reports/general-journal?'.$base.'from_date=2026-02-30&to_date=2026-03-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from_date');

        $this->getJson('/api/v1/reports/general-ledger?'.$base.'account_code=111&from_date=2026-03-31&to_date=2026-03-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_date');
    }
}
