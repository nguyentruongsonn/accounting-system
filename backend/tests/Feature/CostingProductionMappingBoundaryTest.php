<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CostAllocation;
use App\Services\CostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CostingProductionMappingBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_costing_fails_before_any_source_or_journal_mutation_without_approved_mapping(): void
    {
        $company = Company::query()->firstOrFail();
        Config::set('app.env', 'production');

        try {
            app(CostingService::class)->allocateCosts($company->id, '2026-08');
            $this->fail('Production costing must not post legacy hard-coded account routes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_mappings', $exception->errors());
        } finally {
            Config::set('app.env', 'testing');
        }

        $this->assertFalse(CostAllocation::withoutGlobalScopes()->where('company_id', $company->id)->exists());
        $this->assertDatabaseCount('journal_entries', 0);
    }
}
