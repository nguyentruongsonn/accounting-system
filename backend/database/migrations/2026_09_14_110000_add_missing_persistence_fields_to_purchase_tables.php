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
        if (Schema::hasTable('purchase_invoices')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_invoices', 'payment_term_code')) {
                    $table->string('payment_term_code', 50)->nullable()->after('payment_method');
                }
                if (! Schema::hasColumn('purchase_invoices', 'due_days')) {
                    $table->integer('due_days')->nullable()->default(0)->after('payment_term_code');
                }
                if (! Schema::hasColumn('purchase_invoices', 'spend_reason')) {
                    $table->string('spend_reason', 500)->nullable()->after('description');
                }
                if (! Schema::hasColumn('purchase_invoices', 'is_purchase_expense')) {
                    $table->boolean('is_purchase_expense')->default(false)->after('total_stock_value');
                }
                if (! Schema::hasColumn('purchase_invoices', 'is_include_invoice')) {
                    $table->boolean('is_include_invoice')->default(true)->after('is_purchase_expense');
                }
            });
        }

        if (Schema::hasTable('purchase_invoice_lines')) {
            Schema::table('purchase_invoice_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_invoice_lines', 'service_code')) {
                    $table->string('service_code', 50)->nullable()->after('item_id');
                }
                if (! Schema::hasColumn('purchase_invoice_lines', 'cost_item_code')) {
                    $table->string('cost_item_code', 50)->nullable()->after('contract_id');
                }
                if (! Schema::hasColumn('purchase_invoice_lines', 'cost_object_code')) {
                    $table->string('cost_object_code', 50)->nullable()->after('cost_item_code');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('purchase_invoices')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                $columns = array_filter([
                    Schema::hasColumn('purchase_invoices', 'payment_term_code') ? 'payment_term_code' : null,
                    Schema::hasColumn('purchase_invoices', 'due_days') ? 'due_days' : null,
                    Schema::hasColumn('purchase_invoices', 'spend_reason') ? 'spend_reason' : null,
                    Schema::hasColumn('purchase_invoices', 'is_purchase_expense') ? 'is_purchase_expense' : null,
                    Schema::hasColumn('purchase_invoices', 'is_include_invoice') ? 'is_include_invoice' : null,
                ]);
                if (! empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('purchase_invoice_lines')) {
            Schema::table('purchase_invoice_lines', function (Blueprint $table) {
                $columns = array_filter([
                    Schema::hasColumn('purchase_invoice_lines', 'service_code') ? 'service_code' : null,
                    Schema::hasColumn('purchase_invoice_lines', 'cost_item_code') ? 'cost_item_code' : null,
                    Schema::hasColumn('purchase_invoice_lines', 'cost_object_code') ? 'cost_object_code' : null,
                ]);
                if (! empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
