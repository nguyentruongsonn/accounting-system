<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory quantities may be fractional (for example metres or
     * kilograms).  Preserve four decimal places end-to-end so availability
     * and valuation do not silently round a valid issue before it is posted.
     */
    public function up(): void
    {
        foreach (['inventory_receipt_lines', 'inventory_issue_lines'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'quantity')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('quantity', 18, 4)->default(1)->change();
            });
        }
    }

    public function down(): void
    {
        // Reducing precision can destroy posted inventory evidence.  Keep the
        // migration irreversible for the same reason as the costing upgrade.
    }
};
