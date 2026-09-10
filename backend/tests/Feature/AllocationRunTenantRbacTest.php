<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\CostingService;
use App\Services\ToolsEquipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AllocationRunTenantRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_tool_allocation_requires_its_own_permission_and_ignores_a_foreign_company_id(): void
    {
        [$companyA, $companyB, $actor] = $this->tenantActor();
        Sanctum::actingAs($actor);

        $payload = ['company_id' => $companyB->id, 'month' => '2026-08'];
        $this->postJson('/api/v1/tools/allocate', $payload)->assertForbidden();

        $actor->givePermissionTo(Permission::findOrCreate('tools.equipment.allocate', 'web'));
        $this->mock(ToolsEquipmentService::class, function (MockInterface $service) use ($companyA): void {
            $service->shouldReceive('runMonthlyAllocation')
                ->once()
                ->with((int) $companyA->id, '2026-08')
                ->andReturn(null);
        });

        $this->postJson('/api/v1/tools/allocate', $payload)
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_costing_allocation_requires_its_own_permission_and_uses_only_the_authenticated_tenant(): void
    {
        [$companyA, $companyB, $actor] = $this->tenantActor();
        Sanctum::actingAs($actor);

        $payload = [
            'company_id' => $companyB->id,
            'month' => '2026-08',
            // An arbitrary WIP key does not select a tenant: the service will
            // validate it against company A's active production orders before
            // mutating historical allocations.
            'wip_ending' => ['999999' => 0],
        ];
        $this->postJson('/api/v1/costing/allocate', $payload)->assertForbidden();

        $actor->givePermissionTo(Permission::findOrCreate('costing.allocate', 'web'));
        $this->mock(CostingService::class, function (MockInterface $service) use ($companyA): void {
            $service->shouldReceive('allocateCosts')
                ->once()
                ->with((int) $companyA->id, '2026-08', ['999999' => 0])
                ->andReturn([]);
        });

        $this->postJson('/api/v1/costing/allocate', $payload)
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_costing_rejects_a_foreign_wip_order_before_replacing_existing_allocations(): void
    {
        [$companyA, $companyB, $actor] = $this->tenantActor();
        $actor->givePermissionTo(Permission::findOrCreate('costing.allocate', 'web'));
        Sanctum::actingAs($actor);

        // A valid order exists in A, while the requested WIP belongs to B.
        // The validation must therefore reject the reference before the
        // replacement portion of the costing transaction is entered.
        $this->productionOrder($companyA, 'LOCAL-WIP-001');
        $foreignOrderId = $this->productionOrder($companyB, 'FOREIGN-WIP-001');

        $this->postJson('/api/v1/costing/allocate', [
            'company_id' => $companyB->id,
            'month' => '2026-08',
            'wip_ending' => [(string) $foreignOrderId => 0],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'WIP ending references must belong to active production orders in the authenticated company.');

        $this->assertDatabaseCount('cost_allocations', 0);
        $this->assertSame($companyA->id, $actor->fresh()->company_id);
    }

    public function test_direct_costing_service_rejects_an_authenticated_foreign_company_before_mutation(): void
    {
        [$companyA, $companyB, $actor] = $this->tenantActor();
        Sanctum::actingAs($actor);

        try {
            app(CostingService::class)->allocateCosts($companyB->id, '2026-08');
            $this->fail('A direct costing service caller must not operate on a foreign company.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }

        $this->assertDatabaseCount('cost_allocations', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame($companyA->id, $actor->fresh()->company_id);
    }

    public function test_direct_tool_allocation_rejects_an_authenticated_foreign_company_before_mutation(): void
    {
        [$companyA, $companyB, $actor] = $this->tenantActor();
        Sanctum::actingAs($actor);

        try {
            app(ToolsEquipmentService::class)->runMonthlyAllocation($companyB->id, '2026-08');
            $this->fail('A direct tool-allocation caller must not operate on a foreign company.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }

        $this->assertDatabaseCount('allocation_logs', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame($companyA->id, $actor->fresh()->company_id);
    }

    /** @return array{Company, Company, User} */
    private function tenantActor(): array
    {
        Permission::findOrCreate('tools.equipment.allocate', 'web');
        Permission::findOrCreate('costing.allocate', 'web');
        $companyA = Company::create(['name' => 'Allocation tenant A '.uniqid(), 'tax_code' => 'AL-A-'.uniqid()]);
        $companyB = Company::create(['name' => 'Allocation tenant B '.uniqid(), 'tax_code' => 'AL-B-'.uniqid()]);
        $actor = User::factory()->create(['company_id' => $companyA->id]);

        return [$companyA, $companyB, $actor];
    }

    private function productionOrder(Company $company, string $number): int
    {
        $itemId = (int) \DB::table('items')->insertGetId([
            'company_id' => $company->id,
            'code' => 'ITEM-'.$number,
            'name' => 'Finished item '.$number,
            'type' => 'Goods',
            'cost_price' => 0,
            'selling_price' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) \DB::table('production_orders')->insertGetId([
            'company_id' => $company->id,
            'order_number' => $number,
            'start_date' => '2026-08-01',
            'item_id' => $itemId,
            'planned_quantity' => 1,
            'actual_quantity' => 0,
            'status' => 'in_progress',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
