<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryValuationRun;
use App\Models\User;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryValuationRunApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_reads_only_its_company_valuation_runs(): void
    {
        TwoRolePermissions::seed();
        $company = Company::create(['name' => 'Valuation run API company']);
        $otherCompany = Company::create(['name' => 'Foreign valuation run company']);
        $accountant = User::factory()->create(['company_id' => $company->id]);
        $accountant->assignRole('accountant');
        Sanctum::actingAs($accountant);

        $own = InventoryValuationRun::create([
            'company_id' => $company->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
            'status' => 'invalidated',
            'has_unverified_cost' => true,
            'completed_at' => now()->subDay(),
            'invalidated_at' => now(),
            'invalidation_reason' => 'inventory_issue_posted',
        ]);
        InventoryValuationRun::create([
            'company_id' => $otherCompany->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'method' => 'fifo',
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->getJson('/api/v1/inventory/cost-calculation/runs?from_date=2026-01-01&to_date=2026-01-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.status', 'invalidated')
            ->assertJsonPath('data.0.has_unverified_cost', true)
            ->assertJsonPath('data.0.invalidation_reason', 'inventory_issue_posted');
    }

    public function test_cost_calculation_rejects_invalid_or_reversed_date_ranges_before_running(): void
    {
        TwoRolePermissions::seed();
        $company = Company::create(['name' => 'Valuation run date validation']);
        $accountant = User::factory()->create(['company_id' => $company->id]);
        $accountant->assignRole('accountant');
        Sanctum::actingAs($accountant);

        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'from_date' => '2026-02-01',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/inventory/cost-calculation/run', [
            'from_date' => 'not-a-date',
            'to_date' => '2026-01-31',
            'method' => 'weighted_average',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('inventory_valuation_runs', 0);
    }

}
