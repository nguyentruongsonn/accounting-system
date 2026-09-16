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
        if (Schema::hasTable('inventory_receipt_lines')) {
            Schema::table('inventory_receipt_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_receipt_lines', 'cost_item_code')) {
                    $table->string('cost_item_code', 50)->nullable()->after('credit_account');
                }
                if (! Schema::hasColumn('inventory_receipt_lines', 'order_number')) {
                    $table->string('order_number', 50)->nullable()->after('cost_item_code');
                }
            });
        }

        if (Schema::hasTable('inventory_issue_lines')) {
            Schema::table('inventory_issue_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_issue_lines', 'cost_item_code')) {
                    $table->string('cost_item_code', 50)->nullable()->after('credit_account');
                }
                if (! Schema::hasColumn('inventory_issue_lines', 'order_number')) {
                    $table->string('order_number', 50)->nullable()->after('cost_item_code');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('inventory_receipt_lines')) {
            Schema::table('inventory_receipt_lines', function (Blueprint $table) {
                $cols = array_filter(['cost_item_code', 'order_number'], fn ($c) => Schema::hasColumn('inventory_receipt_lines', $c));
                if (! empty($cols)) {
                    $table->dropColumn(array_values($cols));
                }
            });
        }

        if (Schema::hasTable('inventory_issue_lines')) {
            Schema::table('inventory_issue_lines', function (Blueprint $table) {
                $cols = array_filter(['cost_item_code', 'order_number'], fn ($c) => Schema::hasColumn('inventory_issue_lines', $c));
                if (! empty($cols)) {
                    $table->dropColumn(array_values($cols));
                }
            });
        }
    }
};
