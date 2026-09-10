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
        Schema::table('purchase_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_invoices', 'purchase_expense')) {
                $table->decimal('purchase_expense', 15, 2)->default(0)->after('total_amount');
            }
            if (!Schema::hasColumn('purchase_invoices', 'total_stock_value')) {
                $table->decimal('total_stock_value', 15, 2)->default(0)->after('purchase_expense');
            }
            if (!Schema::hasColumn('purchase_invoices', 'voucher_type')) {
                $table->string('voucher_type')->nullable()->after('total_stock_value');
            }
            if (!Schema::hasColumn('purchase_invoices', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('voucher_type');
            }
            if (!Schema::hasColumn('purchase_invoices', 'invoice_symbol')) {
                $table->string('invoice_symbol', 50)->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('purchase_invoices', 'invoice_code')) {
                $table->string('invoice_code', 50)->nullable()->after('invoice_symbol');
            }
        });

        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_invoice_lines', 'purchase_expense')) {
                $table->decimal('purchase_expense', 15, 2)->default(0)->after('tax_account');
            }
            if (!Schema::hasColumn('purchase_invoice_lines', 'stock_value')) {
                $table->decimal('stock_value', 15, 2)->default(0)->after('purchase_expense');
            }
            if (!Schema::hasColumn('purchase_invoice_lines', 'unit')) {
                $table->string('unit', 50)->nullable()->after('stock_value');
            }
            if (!Schema::hasColumn('purchase_invoice_lines', 'warehouse')) {
                $table->string('warehouse', 50)->nullable()->after('unit');
            }
            if (!Schema::hasColumn('purchase_invoice_lines', 'vat_group')) {
                $table->string('vat_group', 20)->nullable()->after('warehouse');
            }
            if (!Schema::hasColumn('purchase_invoice_lines', 'import_tax_rate')) {
                $table->decimal('import_tax_rate', 5, 2)->default(0)->after('vat_group');
            }
            if (!Schema::hasColumn('purchase_invoice_lines', 'import_tax_amount')) {
                $table->decimal('import_tax_amount', 15, 2)->default(0)->after('import_tax_rate');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_expense',
                'total_stock_value',
                'voucher_type',
                'payment_method',
                'invoice_symbol',
                'invoice_code'
            ]);
        });

        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_expense',
                'stock_value',
                'unit',
                'warehouse',
                'vat_group',
                'import_tax_rate',
                'import_tax_amount'
            ]);
        });
    }
};
