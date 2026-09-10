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
        Schema::table('cash_receipt_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('cash_receipt_lines', 'loan_contract')) {
                $table->string('loan_contract', 255)->nullable()->after('operation');
            }
            if (!Schema::hasColumn('cash_receipt_lines', 'line_contact_id')) {
                $table->string('line_contact_id', 100)->nullable()->after('loan_contract');
            }
            if (!Schema::hasColumn('cash_receipt_lines', 'line_contact_name')) {
                $table->string('line_contact_name', 255)->nullable()->after('line_contact_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['loan_contract', 'line_contact_id', 'line_contact_name']);
        });
    }
};
