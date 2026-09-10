<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_movement_events', 'posting_cycle')) {
            Schema::table('inventory_movement_events', function (Blueprint $table): void {
                $table->unsignedInteger('posting_cycle')->default(0)->after('movement_type');
            });
        }

        if (Schema::hasIndex('inventory_movement_events', 'ime_source_warehouse_type_uq')) {
            Schema::table('inventory_movement_events', function (Blueprint $table): void {
                $table->dropUnique('ime_source_warehouse_type_uq');
            });
        }

        if (! Schema::hasIndex('inventory_movement_events', 'ime_source_warehouse_type_cycle_uq')) {
            Schema::table('inventory_movement_events', function (Blueprint $table): void {
                $table->unique(
                    ['source_type', 'source_id', 'source_line_id', 'warehouse_id', 'movement_type', 'posting_cycle'],
                    'ime_source_warehouse_type_cycle_uq',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('inventory_movement_events', 'ime_source_warehouse_type_cycle_uq')) {
            Schema::table('inventory_movement_events', function (Blueprint $table): void {
                $table->dropUnique('ime_source_warehouse_type_cycle_uq');
            });
        }

        if (! Schema::hasIndex('inventory_movement_events', 'ime_source_warehouse_type_uq')) {
            Schema::table('inventory_movement_events', function (Blueprint $table): void {
                $table->unique(
                    ['source_type', 'source_id', 'source_line_id', 'warehouse_id', 'movement_type'],
                    'ime_source_warehouse_type_uq',
                );
            });
        }

        if (Schema::hasColumn('inventory_movement_events', 'posting_cycle')) {
            Schema::table('inventory_movement_events', function (Blueprint $table): void {
                $table->dropColumn('posting_cycle');
            });
        }
    }
};
