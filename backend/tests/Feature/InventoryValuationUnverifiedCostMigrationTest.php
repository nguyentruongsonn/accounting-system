<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryValuationUnverifiedCostMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_cost_flag_migration_is_safe_to_retry_and_rollback(): void
    {
        $migration = require database_path('migrations/2026_09_03_000020_add_unverified_cost_flag_to_inventory_valuation_runs.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('inventory_valuation_runs', 'has_unverified_cost'));

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('inventory_valuation_runs', 'has_unverified_cost'));

        $migration->down();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('inventory_valuation_runs', 'has_unverified_cost'));
    }
}
