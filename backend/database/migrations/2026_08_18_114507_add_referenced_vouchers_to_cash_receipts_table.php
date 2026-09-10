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
            if (!Schema::hasColumn('cash_receipts', 'referenced_vouchers')) {
                $table->json('referenced_vouchers')->nullable()->after('reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->dropColumn('referenced_vouchers');
        });
    }
};
