<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventorySubledgerGlReconciliationException;
use App\Models\User;
use App\Services\InventorySubledgerGlReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class InventorySubledgerGlReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The test schema snapshot can predate this bounded foundation.
        if (! \Schema::hasTable('inventory_subledger_gl_reconciliation_runs')) {
            (require database_path('migrations/2026_08_22_161240_create_inventory_subledger_gl_reconciliation_foundation.php'))->up();
            (require database_path('migrations/2026_08_22_161241_enforce_inventory_subledger_gl_reconciliation_append_only.php'))->up();
        }
    }

    public function test_capture_is_tenant_bound_append_only_and_never_invents_inventory_or_gl_amounts(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);

        $run = app(InventorySubledgerGlReconciliationService::class)->capture($actor, '2026-08-31');

        $this->assertSame('not_available', $run->status);
        $this->assertSame($company->id, $run->company_id);
        $this->assertSame('2026-08-31', $run->as_of_date->toDateString());
        $this->assertNull($run->snapshot['amounts']);
        $this->assertStringContainsString('No inventory quantity/value', $run->snapshot['statement']);
        $this->assertSame(64, strlen($run->contract_hash));
        $this->assertSame(64, strlen($run->snapshot_hash));
        $this->assertGreaterThan(0, InventorySubledgerGlReconciliationException::where('reconciliation_run_id', $run->id)->count());
        $this->expectException(LogicException::class);
        $run->update(['status' => 'available']);
    }

    public function test_owner_approved_valuation_mapping_and_physical_count_contracts_are_blocking_even_with_available_schema(): void
    {
        $actor = User::factory()->create(['company_id' => Company::query()->firstOrFail()->id]);
        $run = app(InventorySubledgerGlReconciliationService::class)->capture($actor, '2026-08-31');
        $codes = $run->exceptions->pluck('exception_code')->all();

        $this->assertContains('inventory_opening_cutoff_coverage_not_owner_approved', $codes);
        $this->assertContains('inventory_valuation_cogs_policy_not_owner_approved', $codes);
        $this->assertContains('inventory_control_account_mapping_not_owner_approved', $codes);
        $this->assertContains('inventory_physical_count_exception_policy_not_owner_approved', $codes);
        $this->assertFalse($run->source_completeness['inventory_control_account_mapping']['available']);
    }
}
