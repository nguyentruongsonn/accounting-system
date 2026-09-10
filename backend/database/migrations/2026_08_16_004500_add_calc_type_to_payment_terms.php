<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_terms', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_terms', 'calc_type')) {
                $table->string('calc_type')->default('due_days')->after('name'); // due_days | fixed_day
            }
            if (!Schema::hasColumn('payment_terms', 'fixed_day')) {
                $table->integer('fixed_day')->nullable()->after('due_days');
            }
            if (!Schema::hasColumn('payment_terms', 'fixed_months')) {
                $table->integer('fixed_months')->nullable()->after('fixed_day');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_terms', function (Blueprint $table) {
            $table->dropColumn(['calc_type', 'fixed_day', 'fixed_months']);
        });
    }
};
