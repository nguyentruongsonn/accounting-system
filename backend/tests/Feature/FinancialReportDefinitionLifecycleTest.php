<?php

namespace Tests\Feature;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\Company;
use App\Models\FinancialReportDefinition;
use App\Models\User;
use App\Services\FinancialReportDefinitionLifecycleService;
use App\Services\FinancialReportDefinitionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class FinancialReportDefinitionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_effective_definition_has_signed_versioned_contract_and_resolves_by_date(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $service = app(FinancialReportDefinitionLifecycleService::class);
        $draft = $service->createDraft($actor, $this->contract());
        $approved = $service->approve($actor, $draft, CarbonImmutable::parse('2026-01-02 09:00:00'));
        $published = $service->publish($actor, $approved, CarbonImmutable::parse('2026-01-02 10:00:00'));

        $this->assertSame('published', $published->status);
        $this->assertSame($actor->id, $published->approved_by);
        $this->assertSame($actor->id, $published->published_by);
        $this->assertNotNull($published->contract_hash);
        $this->assertTrue($published->is(app(FinancialReportDefinitionResolver::class)->requirePublished(1, 'balance_sheet', '2026-06-30')));
    }

    public function test_resolver_fails_closed_for_draft_or_outside_effective_range(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $service = app(FinancialReportDefinitionLifecycleService::class);
        $draft = $service->createDraft($actor, $this->contract());
        try {
            app(FinancialReportDefinitionResolver::class)->requirePublished(1, 'balance_sheet', '2026-06-30');
            $this->fail('Draft must never be executable.');
        } catch (ReportDefinitionUnavailableException) { $this->assertTrue(true); }

        $service->publish($actor, $service->approve($actor, $draft));
        try {
            app(FinancialReportDefinitionResolver::class)->requirePublished(1, 'balance_sheet', '2027-01-01');
            $this->fail('Out-of-range definition must not resolve.');
        } catch (ReportDefinitionUnavailableException) { $this->assertTrue(true); }

    }

    public function test_approval_requires_all_definition_dimensions_but_never_invents_regulatory_mapping(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $service = app(FinancialReportDefinitionLifecycleService::class);
        $draft = $service->createDraft($actor, array_merge($this->contract(), ['source_form_id' => null, 'regulatory_dependencies' => null]));
        $this->expectException(LogicException::class);
        $service->approve($actor, $draft);
    }

    public function test_approved_contract_is_immutable_in_model_and_database_while_publication_appends_evidence(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $service = app(FinancialReportDefinitionLifecycleService::class);
        $approved = $service->approve($actor, $service->createDraft($actor, $this->contract()));
        $approved->source_form_id = 'tampered';
        try {
            $approved->save();
            $this->fail('Approved contract model mutation must fail.');
        } catch (LogicException) { $this->assertTrue(true); }
        $this->expectException(QueryException::class);
        DB::table('financial_report_definitions')->where('id', $approved->id)->update(['source_form_id' => 'tampered']);
    }

    public function test_published_ranges_cannot_overlap_and_cross_tenant_actor_cannot_manage_definition(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $company = Company::query()->create(['name' => 'Other financial reporting tenant']);
        $other = User::factory()->create(['company_id' => $company->id]);
        $service = app(FinancialReportDefinitionLifecycleService::class);
        $first = $service->approve($actor, $service->createDraft($actor, $this->contract()));
        $service->publish($actor, $first);
        $second = $service->approve($actor, $service->createDraft($actor, array_merge($this->contract(), ['definition_version' => '2026.2'])));
        try {
            $service->publish($actor, $second);
            $this->fail('Overlapping published definitions must fail.');
        } catch (ValidationException) { $this->assertTrue(true); }
        $this->expectException(AuthorizationException::class);
        $service->publish($other, $second);
    }

    /** @return array<string,mixed> */
    private function contract(): array
    {
        return [
            'report_key' => 'balance_sheet', 'definition_version' => '2026.1',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'source_form_id' => 'owner-controlled-financial-report-form',
            'source_contract' => ['ledger' => 'posted_journal_entries', 'cutoff' => 'as_of_date'],
            // Deliberately neutral contract examples: no fabricated form line,
            // account mapping, or claim of Appendix IV approval is stored.
            'line_mapping_contract' => ['owner_approved_mapping_reference' => 'pending-controlled-catalogue'],
            'sign_rounding_contract' => ['presentation_scale' => 0, 'sign_convention' => 'owner-controlled'],
            'comparative_contract' => ['comparison_basis' => 'owner-controlled'],
            'regulatory_dependencies' => [],
        ];
    }
}
