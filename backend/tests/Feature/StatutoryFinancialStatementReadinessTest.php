<?php

namespace Tests\Feature;

use App\Models\AccountingRegimeProfile;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingRegimeService;
use App\Services\StatutoryFinancialStatementDefinitionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StatutoryFinancialStatementReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_readiness_fails_closed_without_a_published_effective_catalogue(): void
    {
        [$actor, $year] = $this->actorAndYear();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/reports/statutory-financial-statement-readiness?'.http_build_query([
            'fiscal_year_id' => $year->id,
            'form_key' => 'owner-balance-sheet',
        ]))
            ->assertOk()
            ->assertHeader('X-Accounting-Report-Classification', 'operational-draft')
            ->assertHeader('X-Accounting-Statutory-Output-Available', 'false')
            ->assertHeader('X-Accounting-Report-Non-Certifying', 'true')
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('meta.statutory_output_available', false)
            ->assertJsonPath('meta.legal_compliance_certified', false)
            ->assertJsonPath('definition', null)
            ->assertJsonPath('definition_evidence_ready', false)
            ->assertJsonPath('execution_ready', false)
            ->assertJsonPath('missing_conditions.0.key', 'published_effective_definition');
    }

    public function test_published_catalogue_evidence_is_visible_but_never_enables_statutory_execution(): void
    {
        [$actor, $year] = $this->actorAndYear();
        $profile = app(AccountingRegimeService::class)->forFiscalYear($actor->company_id, $year->id);
        $lifecycle = app(StatutoryFinancialStatementDefinitionLifecycleService::class);
        $definition = $lifecycle->createDraft($actor, $this->contract($profile));
        $definition = $lifecycle->publish($actor, $lifecycle->approve($actor, $definition));
        Sanctum::actingAs($actor);

        $response = $this->getJson('/api/v1/reports/statutory-financial-statement-readiness?'.http_build_query([
            'fiscal_year_id' => $year->id,
            'form_key' => 'owner-balance-sheet',
        ]))
            ->assertOk()
            ->assertHeader('X-Accounting-Report-Classification', 'operational-draft')
            ->assertHeader('X-Accounting-Statutory-Output-Available', 'false')
            ->assertHeader('X-Accounting-Report-Non-Certifying', 'true')
            ->assertJsonPath('definition.id', $definition->id)
            ->assertJsonPath('definition.definition_version', '2026.1')
            ->assertJsonPath('definition_evidence_ready', true)
            ->assertJsonPath('execution_ready', false)
            ->assertJsonPath('meta.statutory_output_available', false);

        $keys = collect($response->json('missing_conditions'))->pluck('key')->all();
        $this->assertContains('authoritative_ledger_extraction_and_mapping_execution', $keys);
        $this->assertContains('report_run_approval_signature_retention', $keys);
    }

    public function test_catalogue_with_nonempty_but_unverifiable_evidence_stays_not_definition_ready(): void
    {
        [$actor, $year] = $this->actorAndYear();
        $profile = app(AccountingRegimeService::class)->forFiscalYear($actor->company_id, $year->id);
        $contract = $this->contract($profile);
        $contract['line_mapping_contract'] = ['untyped' => 'not-an-evidence-reference'];
        $contract['presentation_contract'] = ['sign_convention' => 'owner-controlled'];
        $lifecycle = app(StatutoryFinancialStatementDefinitionLifecycleService::class);
        $lifecycle->publish($actor, $lifecycle->approve($actor, $lifecycle->createDraft($actor, $contract)));
        Sanctum::actingAs($actor);

        $response = $this->getJson('/api/v1/reports/statutory-financial-statement-readiness?fiscal_year_id='.$year->id.'&form_key=owner-balance-sheet')
            ->assertOk()
            ->assertJsonPath('definition_evidence_ready', false);
        $keys = collect($response->json('missing_conditions'))->pluck('key')->all();
        $this->assertContains('mapping_evidence', $keys);
        $this->assertContains('comparative_evidence', $keys);
    }

    /** @return array{User,FiscalYear} */
    private function actorAndYear(): array
    {
        $company = Company::create(['name' => 'Statement readiness tenant', 'tax_code' => 'STAT-READY']);
        $year = FiscalYear::withoutGlobalScopes()->create(['company_id' => $company->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->givePermissionTo('reports.view');
        return [$actor, $year];
    }

    /** @return array<string,mixed> */
    private function contract(AccountingRegimeProfile $profile): array
    {
        return [
            'accounting_regime_profile_id' => $profile->id, 'form_key' => 'owner-balance-sheet', 'definition_version' => '2026.1',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'provenance_contract' => ['catalogue_reference' => 'controlled-catalogue-v1', 'source_received_at' => '2026-01-01'],
            'form_contract' => ['owner_form_identifier' => 'controlled-form', 'period_basis' => 'as-of-date'],
            'line_definitions' => [['owner_line_identifier' => 'line-1']],
            'line_mapping_contract' => ['mapping_reference' => 'approved-mapping-v1'],
            'presentation_contract' => ['sign_convention' => 'owner-controlled', 'comparative_basis' => 'owner-approved-comparative-contract'],
            'notes_requirement_contract' => ['notes_reference' => 'approved-notes-v1'],
            'regulatory_dependencies' => [['dependency' => 'TT99/2025/TT-BTC', 'verification_status' => 'owner-review-required']],
        ];
    }
}
