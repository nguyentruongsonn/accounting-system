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

class ReportCapabilityManifestTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_manifest_is_tenant_scoped_regime_aware_and_declares_unavailable_reports(): void
    {
        $company = Company::create(['name' => 'Capability tenant', 'tax_code' => 'CAPABILITY-A']);
        $fiscalYear = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo('reports.view');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/reports/capabilities?'.http_build_query([
            'company_id' => 999999,
            'fiscal_year_id' => $fiscalYear->id,
        ]))
            ->assertOk()
            ->assertHeader('X-Accounting-Report-Classification', 'operational-draft')
            ->assertHeader('X-Accounting-Statutory-Output-Available', 'false')
            ->assertHeader('X-Accounting-Report-Non-Certifying', 'true')
            ->assertJsonPath('meta.company_id', $company->id)
            ->assertJsonPath('meta.fiscal_year_id', $fiscalYear->id)
            ->assertJsonPath('meta.accounting_regime', 'TT99')
            ->assertJsonPath('meta.from_date', '2026-01-01')
            ->assertJsonPath('meta.to_date', '2026-12-31')
            ->assertJsonPath('meta.manifest_version', 'report-capabilities.v1')
            ->assertJsonPath('capabilities.0.key', 'trial_balance')
            ->assertJsonPath('capabilities.0.available', true)
            ->assertJsonPath('capabilities.0.implementation_status', 'operational_draft')
            ->assertJsonPath('capabilities.0.appendix_iv_certified', false)
            ->assertJsonPath('capabilities.0.definition_version', null)
            ->assertJsonPath('capabilities.5.key', 'cash_flow_statement')
            ->assertJsonPath('capabilities.5.status', 'not_implemented')
            ->assertJsonPath('capabilities.5.available', false)
            ->assertJsonPath('capabilities.5.appendix_iv_certified', false)
            ->assertJsonPath('capabilities.5.definition_version', null)
            ->assertJsonPath('capabilities.6.key', 'financial_statement_notes')
            ->assertJsonPath('capabilities.6.available', false);

        $this->assertNotEmpty($response->json('meta.disclaimer'));
        foreach ($response->json('capabilities') as $capability) {
            $this->assertFalse($capability['appendix_iv_certified']);
            $this->assertNull($capability['definition_version']);
        }
    }

    public function test_manifest_context_fails_closed_for_invalid_or_cross_fiscal_year_ranges(): void
    {
        $user = User::factory()->create(['company_id' => 1]);
        $user->givePermissionTo('reports.view');
        Sanctum::actingAs($user);
        $fiscalYear = FiscalYear::withoutGlobalScopes()->where('company_id', 1)->firstOrFail();

        $this->getJson('/api/v1/reports/capabilities?'.http_build_query([
            'fiscal_year_id' => $fiscalYear->id,
            'from_date' => '2026-12-31',
            'to_date' => '2026-01-01',
        ]))->assertStatus(422)
            ->assertHeader('X-Accounting-Report-Classification', 'operational-draft')
            ->assertHeader('X-Accounting-Statutory-Output-Available', 'false')
            ->assertHeader('X-Accounting-Report-Non-Certifying', 'true')
            ->assertJsonValidationErrors('to_date');

        $this->getJson('/api/v1/reports/capabilities?'.http_build_query([
            'fiscal_year_id' => $fiscalYear->id,
            'from_date' => '2025-12-31',
            'to_date' => '2026-01-01',
        ]))->assertStatus(422)->assertJsonValidationErrors('date_range');
    }

    public function test_manifest_resolves_historical_tt200_context_without_certifying_reports(): void
    {
        $company = Company::create(['name' => 'Historical capability tenant', 'tax_code' => 'CAPABILITY-HIST']);
        $fiscalYear = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'year' => 2025,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'open',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo('reports.view');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/capabilities?fiscal_year_id='.$fiscalYear->id)
            ->assertOk()
            ->assertJsonPath('meta.accounting_regime', 'TT200')
            ->assertJsonPath('capabilities.1.appendix_iv_certified', false)
            ->assertJsonPath('capabilities.1.definition_version', null);
    }

    public function test_manifest_requires_the_existing_reports_view_permission(): void
    {
        $this->getJson('/api/v1/reports/capabilities')
            ->assertUnauthorized()
            ->assertHeader('X-Accounting-Report-Classification', 'operational-draft')
            ->assertHeader('X-Accounting-Statutory-Output-Available', 'false')
            ->assertHeader('X-Accounting-Report-Non-Certifying', 'true');

        $user = User::factory()->create(['company_id' => 1]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/capabilities')
            ->assertForbidden()
            ->assertHeader('X-Accounting-Report-Classification', 'operational-draft')
            ->assertHeader('X-Accounting-Statutory-Output-Available', 'false')
            ->assertHeader('X-Accounting-Report-Non-Certifying', 'true');
    }

    public function test_legacy_vat_declaration_endpoint_fails_closed_instead_of_presenting_a_statutory_return(): void
    {
        $user = User::factory()->create(['company_id' => 1]);
        $user->givePermissionTo('reports.view');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/tax/vat?company_id=1&month=2026-08')
            ->assertStatus(501)
            ->assertHeader('X-Accounting-Report-Classification', 'operational-draft')
            ->assertHeader('X-Accounting-Statutory-Output-Available', 'false')
            ->assertHeader('X-Accounting-Report-Non-Certifying', 'true')
            ->assertJsonPath('code', 'TAX_DECLARATION_NOT_IMPLEMENTED')
            ->assertJsonPath('not_a_statutory_declaration', true)
            ->assertJsonPath('regulatory_dependency', 'Tax obligations must be evaluated under applicable tax law; TT99/2025/TT-BTC is an accounting-regime baseline, not a tax-filing certification.');
    }
}
