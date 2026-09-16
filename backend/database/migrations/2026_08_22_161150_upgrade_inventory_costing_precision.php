<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The old schema used integer inventory values.  MWA now has an exact
     * DECIMAL evidence path, so retain the four-place calculated rate and
     * two-place quantities/amounts instead of silently truncating them.
     */
    public function up(): void
    {
        Schema::table('inventory_receipt_lines', function (Blueprint $table) {
            $table->decimal('quantity', 18, 2)->default(1)->change();
            $table->decimal('unit_price', 20, 4)->default(0)->change();
            $table->decimal('amount', 20, 2)->default(0)->change();
        });
        Schema::table('inventory_issue_lines', function (Blueprint $table) {
            $table->decimal('quantity', 18, 2)->default(1)->change();
            $table->decimal('unit_price', 20, 4)->default(0)->change();
            $table->decimal('amount', 20, 2)->default(0)->change();
        });
        Schema::table('inventory_receipts', function (Blueprint $table) {
            $table->decimal('total_amount', 20, 2)->default(0)->change();
        });
        Schema::table('inventory_issues', function (Blueprint $table) {
            $table->decimal('total_amount', 20, 2)->default(0)->change();
        });
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->decimal('debit_amount', 20, 2)->default(0)->change();
            $table->decimal('credit_amount', 20, 2)->default(0)->change();
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->decimal('total_amount', 20, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        // Precision reduction would destroy posted accounting evidence.
    }
};
