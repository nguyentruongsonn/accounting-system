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
                if (! Schema::hasColumn('purchase_invoices', 'tax_code')) {
                    $table->string('tax_code', 50)->nullable()->after('deliverer_name');
                }
                if (! Schema::hasColumn('purchase_invoices', 'receiver_name')) {
                    $table->string('receiver_name', 255)->nullable()->after('tax_code');
                }
                if (! Schema::hasColumn('purchase_invoices', 'invoice_address')) {
                    $table->string('invoice_address', 500)->nullable()->after('supplier_address');
                }
                if (! Schema::hasColumn('purchase_invoices', 'invoice_form')) {
                    $table->string('invoice_form', 50)->nullable()->after('invoice_code');
                }
                if (! Schema::hasColumn('purchase_invoices', 'payment_slip_number')) {
                    $table->string('payment_slip_number', 50)->nullable()->after('spend_reason');
                }
                if (! Schema::hasColumn('purchase_invoices', 'invoice_option')) {
                    $table->string('invoice_option', 100)->nullable()->after('is_include_invoice');
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
                    Schema::hasColumn('purchase_invoices', 'tax_code') ? 'tax_code' : null,
                    Schema::hasColumn('purchase_invoices', 'receiver_name') ? 'receiver_name' : null,
                    Schema::hasColumn('purchase_invoices', 'invoice_address') ? 'invoice_address' : null,
                    Schema::hasColumn('purchase_invoices', 'invoice_form') ? 'invoice_form' : null,
                    Schema::hasColumn('purchase_invoices', 'payment_slip_number') ? 'payment_slip_number' : null,
                    Schema::hasColumn('purchase_invoices', 'invoice_option') ? 'invoice_option' : null,
                ]);
                if (! empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
