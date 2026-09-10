<?php

namespace Tests\Feature;

use App\Exceptions\StatutoryStatementDefinitionUnavailableException;
use App\Models\AccountingRegimeProfile;
use App\Models\Company;
use App\Models\StatutoryFinancialStatementDefinition;
use App\Models\User;
use App\Services\StatutoryFinancialStatementDefinitionLifecycleService;
use App\Services\StatutoryFinancialStatementDefinitionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class StatutoryFinancialStatementDefinitionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_versioned_form_catalogue_resolves_only_for_its_tenant_regime_and_effective_date(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $profile = AccountingRegimeProfile::withoutGlobalScope('company')->where('company_id', 1)->firstOrFail();
        $service = app(StatutoryFinancialStatementDefinitionLifecycleService::class);
        $draft = $service->createDraft($actor, $this->contract($profile->id));
        $published = $service->publish($actor, $service->approve($actor, $draft, CarbonImmutable::parse('2026-01-02 09:00:00')));

        $this->assertSame('published', $published->status);
        $this->assertTrue($published->is(app(StatutoryFinancialStatementDefinitionResolver::class)->requirePublished(1, $profile->id, 'owner-supplied-statement-form', '2026-06-30')));
    }

    public function test_resolver_fails_closed_without_catalogue_or_for_the_wrong_date_or_regime(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $profile = AccountingRegimeProfile::withoutGlobalScope('company')->where('company_id', 1)->firstOrFail();
        $resolver = app(StatutoryFinancialStatementDefinitionResolver::class);
        try { $resolver->requirePublished(1, $profile->id, 'owner-supplied-statement-form', '2026-06-30'); $this->fail('No controlled catalogue must fail closed.'); } catch (StatutoryStatementDefinitionUnavailableException) { $this->assertTrue(true); }
        $service = app(StatutoryFinancialStatementDefinitionLifecycleService::class);
        $service->publish($actor, $service->approve($actor, $service->createDraft($actor, $this->contract($profile->id))));
        try { $resolver->requirePublished(1, $profile->id, 'owner-supplied-statement-form', '2027-01-01'); $this->fail('Outside-effective catalogue must fail closed.'); } catch (StatutoryStatementDefinitionUnavailableException) { $this->assertTrue(true); }
        try { $resolver->requirePublished(1, 999999, 'owner-supplied-statement-form', '2026-06-30'); $this->fail('Wrong regime profile must fail closed.'); } catch (StatutoryStatementDefinitionUnavailableException) { $this->assertTrue(true); }
    }

    public function test_approval_requires_owner_provenance_lines_mappings_presentation_notes_and_dependency_record(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $profile = AccountingRegimeProfile::withoutGlobalScope('company')->where('company_id', 1)->firstOrFail();
        $service = app(StatutoryFinancialStatementDefinitionLifecycleService::class);
        $draft = $service->createDraft($actor, array_merge($this->contract($profile->id), ['line_definitions' => [], 'regulatory_dependencies' => null]));
        $this->expectException(LogicException::class);
        $service->approve($actor, $draft);
    }

    public function test_approved_contract_is_immutable_in_model_and_database_and_ranges_cannot_overlap(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $profile = AccountingRegimeProfile::withoutGlobalScope('company')->where('company_id', 1)->firstOrFail();
        $service = app(StatutoryFinancialStatementDefinitionLifecycleService::class);
        $first = $service->approve($actor, $service->createDraft($actor, $this->contract($profile->id)));
        $first->form_key = 'tampered';
        try { $first->save(); $this->fail('Approved contract must be model-immutable.'); } catch (LogicException) { $this->assertTrue(true); }
        try { DB::table('statutory_financial_statement_definitions')->where('id', $first->id)->update(['form_key' => 'tampered']); $this->fail('Approved contract must be database-immutable.'); } catch (QueryException) { $this->assertTrue(true); }
        $service->publish($actor, $first);
        $second = $service->approve($actor, $service->createDraft($actor, array_merge($this->contract($profile->id), ['definition_version' => '2026.2'])));
        $this->expectException(ValidationException::class);
        $service->publish($actor, $second);
    }

    public function test_profile_must_belong_to_tenant_and_cross_tenant_actor_cannot_manage_catalogue(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $otherCompany = Company::query()->create(['name' => 'Other statement definition tenant']);
        $other = User::factory()->create(['company_id' => $otherCompany->id]);
        $profile = AccountingRegimeProfile::withoutGlobalScope('company')->where('company_id', 1)->firstOrFail();
        $service = app(StatutoryFinancialStatementDefinitionLifecycleService::class);
        $draft = $service->createDraft($actor, $this->contract($profile->id));
        $this->expectException(AuthorizationException::class);
        $service->approve($other, $draft);
    }

    /** @return array<string,mixed> */
    private function contract(int $profileId): array
    {
        return [
            'accounting_regime_profile_id' => $profileId, 'form_key' => 'owner-supplied-statement-form', 'definition_version' => '2026.1',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'provenance_contract' => ['catalogue_reference' => 'owner-controlled-appendix-catalogue', 'source_received_at' => '2026-01-01'],
            'form_contract' => ['owner_form_identifier' => 'pending-controlled-form-id', 'period_basis' => 'as-of-date'],
            'line_definitions' => [['owner_line_identifier' => 'pending-line-id', 'label_reference' => 'owner-catalogue']],
            'line_mapping_contract' => ['mapping_reference' => 'owner-approved-mapping-catalogue'],
            'presentation_contract' => ['sign_convention' => 'owner-controlled', 'rounding_scale' => 0, 'comparative_basis' => 'owner-controlled'],
            'notes_requirement_contract' => ['notes_reference' => 'owner-controlled-disclosure-catalogue'],
            'regulatory_dependencies' => [['dependency' => 'TT99/2025/TT-BTC', 'verification_status' => 'owner-review-required']],
        ];
    }
}
