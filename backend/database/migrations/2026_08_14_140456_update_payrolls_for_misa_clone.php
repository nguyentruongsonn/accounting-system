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
        Schema::table('payrolls', function (Blueprint $table) {
            $table->date('posting_date')->nullable()->after('voucher_date');
        });

        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('payroll_id');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('posting_date');
        });

        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }
};
