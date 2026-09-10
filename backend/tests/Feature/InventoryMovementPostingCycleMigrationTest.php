<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryMovementPostingCycleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_cycle_migration_can_retry_after_rollback_without_duplicate_index_errors(): void
    {
        $migration = require database_path('migrations/2026_09_03_000013_add_inventory_movement_posting_cycle.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('inventory_movement_events', 'posting_cycle'));
        $this->assertTrue(Schema::hasIndex('inventory_movement_events', 'ime_source_warehouse_type_uq'));

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('inventory_movement_events', 'posting_cycle'));
        $this->assertTrue(Schema::hasIndex('inventory_movement_events', 'ime_source_warehouse_type_cycle_uq'));
        $this->assertFalse(Schema::hasIndex('inventory_movement_events', 'ime_source_warehouse_type_uq'));
    }
}
