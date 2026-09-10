<?php

namespace Tests\Feature;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\User;
use App\Services\ManagementReportDefinitionLifecycleService;
use App\Services\ManagementReportDefinitionRegistry;
use App\Support\ApiErrorResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ManagementReportDefinitionRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_fails_closed_without_a_signed_published_exact_contract(): void
    {
        $user = User::factory()->create(['company_id' => 1]);
        app(ManagementReportDefinitionLifecycleService::class)->createDraft(
            $user,
            'accounts_payable_aging.v2',
            'draft-1',
            ['sources' => ['purchase_invoices']],
            [],
        );

        $this->expectException(ReportDefinitionUnavailableException::class);
        app(ManagementReportDefinitionRegistry::class)
            ->requireExecutable($user->company_id, 'accounts_payable_aging.v2');
    }

    public function test_registry_returns_the_latest_signed_published_definition_with_both_contracts(): void
    {
        $user = User::factory()->create(['company_id' => 1]);
        $definitions = app(ManagementReportDefinitionLifecycleService::class);
        $definition = $definitions->createDraft(
            $user,
            'accounts_receivable_aging.v2',
            '2026.08.1',
            ['sources' => ['sales_invoices'], 'cutoff' => 'as_of_date'],
            ['outstanding' => 'allocation-aware', 'buckets' => ['current', '1_30']],
        );
        $definition = $definitions->publish($user, $definition);

        $resolved = app(ManagementReportDefinitionRegistry::class)
            ->requireExecutable($user->company_id, 'accounts_receivable_aging.v2');

        $this->assertTrue($definition->is($resolved));
        $this->assertSame('2026.08.1', $resolved->definition_version);
    }

    public function test_definition_unavailable_has_a_stable_non_leaking_409_contract(): void
    {
        $response = app(ApiErrorResponder::class)->toResponse(
            new ReportDefinitionUnavailableException('accounts_payable_aging.v2'),
            Request::create('/api/v2/purchase/ap-aging', 'GET'),
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('The requested report definition is not available.', $response->getData(true)['error']);
        $this->assertSame('DEFINITION_UNAVAILABLE', $response->getData(true)['error_code']);
        $this->assertArrayHasKey('request_id', $response->getData(true));
        $this->assertStringNotContainsString('accounts_payable_aging.v2', $response->getContent());
    }
}
