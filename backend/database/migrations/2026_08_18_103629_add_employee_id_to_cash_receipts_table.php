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
        Schema::table('cash_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('cash_receipts', 'employee_id')) {
                $table->string('employee_id', 100)->nullable()->after('payer_address');
            }
            if (!Schema::hasColumn('cash_receipts', 'employee_name')) {
                $table->string('employee_name', 255)->nullable()->after('employee_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->dropColumn(['employee_id', 'employee_name']);
        });
    }
};
