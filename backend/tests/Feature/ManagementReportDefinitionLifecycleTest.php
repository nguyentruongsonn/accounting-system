<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ManagementReportEffectiveDefinition;
use App\Models\User;
use App\Services\ManagementReportDefinitionLifecycleService;
use App\Services\ManagementReportDefinitionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ManagementReportDefinitionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_can_be_edited_but_published_definition_cannot_be_mutated_or_deleted(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $service = app(ManagementReportDefinitionLifecycleService::class);
        $draft = $service->createDraft($actor, 'accounts_payable_aging.v2', '2026.08.1');

        $draft = $service->updateDraft(
            $actor,
            $draft,
            ['sources' => ['purchase_invoices'], 'cutoff' => 'as_of_date'],
            ['outstanding' => 'allocation-aware'],
        );
        $published = $service->publish($actor, $draft);

        $this->assertSame('published', $published->status);
        $this->assertNotNull($published->contract_hash);
        $this->assertSame($actor->id, $published->signed_by);

        $published->source_contract = ['sources' => ['tampered_source']];
        try {
            $published->save();
            $this->fail('Expected immutable published definition update to fail.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(LogicException::class);
        $published->delete();
    }

    public function test_publish_selects_one_effective_version_even_when_its_timestamp_is_older(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $service = app(ManagementReportDefinitionLifecycleService::class);
        $first = $service->createDraft(
            $actor,
            'accounts_receivable_aging.v2',
            '2026.08.1',
            ['sources' => ['sales_invoices']],
            ['outstanding' => 'allocation-aware'],
        );
        $first = $service->publish($actor, $first, CarbonImmutable::parse('2026-08-22 12:00:00'));

        $second = $service->createDraft(
            $actor,
            'accounts_receivable_aging.v2',
            '2026.08.2',
            ['sources' => ['sales_invoices', 'credit_notes']],
            ['outstanding' => 'allocation-aware'],
        );
        $second = $service->publish($actor, $second, CarbonImmutable::parse('2026-08-21 12:00:00'));

        $effective = ManagementReportEffectiveDefinition::withoutGlobalScope('company')
            ->where('company_id', $actor->company_id)
            ->where('report_key', 'accounts_receivable_aging.v2')
            ->sole();
        $resolved = app(ManagementReportDefinitionRegistry::class)
            ->requireExecutable($actor->company_id, 'accounts_receivable_aging.v2');

        $this->assertSame($second->id, $effective->management_report_definition_id);
        $this->assertTrue($second->is($resolved));
        $this->assertFalse($first->is($resolved));
    }

    public function test_other_tenant_actor_cannot_edit_or_publish_a_definition(): void
    {
        $company = Company::query()->create(['name' => 'Lifecycle other tenant']);
        $actor = User::factory()->create(['company_id' => 1]);
        $otherActor = User::factory()->create(['company_id' => $company->id]);
        $service = app(ManagementReportDefinitionLifecycleService::class);
        $draft = $service->createDraft($actor, 'accounts_payable_aging.v2', '2026.08.1');

        $this->expectException(AuthorizationException::class);
        $service->updateDraft($otherActor, $draft, ['sources' => ['tampered']], ['outstanding' => 'tampered']);
    }

    public function test_incomplete_draft_cannot_be_published(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $draft = app(ManagementReportDefinitionLifecycleService::class)
            ->createDraft($actor, 'accounts_payable_aging.v2', '2026.08.1', ['sources' => ['purchase_invoices']], []);

        $this->expectException(LogicException::class);
        app(ManagementReportDefinitionLifecycleService::class)->publish($actor, $draft);
    }

    public function test_database_rejects_raw_mutation_of_a_published_definition(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $service = app(ManagementReportDefinitionLifecycleService::class);
        $draft = $service->createDraft(
            $actor,
            'accounts_payable_aging.v2',
            '2026.08.1',
            ['sources' => ['purchase_invoices']],
            ['outstanding' => 'allocation-aware'],
        );
        $published = $service->publish($actor, $draft);

        $this->expectException(QueryException::class);
        DB::table('management_report_definitions')
            ->where('id', $published->id)
            ->update(['definition_version' => 'tampered']);
    }
}
