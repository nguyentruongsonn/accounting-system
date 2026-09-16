<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_valuation_runs', 'has_unverified_cost')) {
            Schema::table('inventory_valuation_runs', function (Blueprint $table): void {
                $table->boolean('has_unverified_cost')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_valuation_runs', 'has_unverified_cost')) {
            Schema::table('inventory_valuation_runs', function (Blueprint $table): void {
                $table->dropColumn('has_unverified_cost');
            });
        }
    }
};
