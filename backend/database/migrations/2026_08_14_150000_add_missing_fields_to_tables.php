<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_payments') && !Schema::hasColumn('cash_payments', 'is_posted')) {
            Schema::table('cash_payments', function (Blueprint $table) {
                $table->boolean('is_posted')->default(false)->after('status');
            });
        }

        if (Schema::hasTable('cash_receipts') && !Schema::hasColumn('cash_receipts', 'is_posted')) {
            Schema::table('cash_receipts', function (Blueprint $table) {
                $table->boolean('is_posted')->default(false)->after('status');
            });
        }

        if (Schema::hasTable('sales_invoices')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('sales_invoices', 'attached_docs')) {
                    $table->string('attached_docs')->nullable()->after('description');
                }
                if (!Schema::hasColumn('sales_invoices', 'currency')) {
                    $table->string('currency', 3)->default('VND')->after('attached_docs');
                }
                if (!Schema::hasColumn('sales_invoices', 'exchange_rate')) {
                    $table->decimal('exchange_rate', 15, 2)->default(1)->after('currency');
                }
            });
        }

        if (Schema::hasTable('sales_invoice_lines')) {
            Schema::table('sales_invoice_lines', function (Blueprint $table) {
                if (!Schema::hasColumn('sales_invoice_lines', 'item_id')) {
                    $table->unsignedBigInteger('item_id')->nullable()->after('sales_invoice_id');
                }
                if (!Schema::hasColumn('sales_invoice_lines', 'discount_rate')) {
                    $table->decimal('discount_rate', 5, 2)->default(0)->after('amount');
                }
                if (!Schema::hasColumn('sales_invoice_lines', 'discount_amount')) {
                    $table->bigInteger('discount_amount')->default(0)->after('discount_rate');
                }
                if (!Schema::hasColumn('sales_invoice_lines', 'tax_account')) {
                    $table->string('tax_account', 20)->nullable()->after('tax_amount');
                }
            });
        }
    }

    public function down(): void
    {
    }
};
