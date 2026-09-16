<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_returns')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_returns', 'branch_id')) {
                    $table->foreignId('branch_id')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('sales_returns', 'tax_code')) {
                    $table->string('tax_code', 50)->nullable()->after('customer_address');
                }
                if (! Schema::hasColumn('sales_returns', 'employee_id')) {
                    $table->foreignId('employee_id')->nullable()->after('receiver_name')->constrained('employees');
                }
                if (! Schema::hasColumn('sales_returns', 'voucher_type')) {
                    $table->string('voucher_type', 100)->default('sales_return')->after('employee_id');
                }
                if (! Schema::hasColumn('sales_returns', 'payment_method')) {
                    $table->string('payment_method', 50)->default('reduce_receivable')->after('voucher_type');
                }
                if (! Schema::hasColumn('sales_returns', 'bank_account_id')) {
                    $table->foreignId('bank_account_id')->nullable()->after('payment_method')->constrained('bank_accounts');
                }
                if (! Schema::hasColumn('sales_returns', 'description')) {
                    $table->string('description')->nullable()->after('reason');
                }
                if (! Schema::hasColumn('sales_returns', 'grand_total')) {
                    $table->decimal('grand_total', 15, 2)->default(0)->after('total_amount');
                }
                if (! Schema::hasColumn('sales_returns', 'cogs_total_amount')) {
                    $table->decimal('cogs_total_amount', 15, 2)->default(0)->after('grand_total');
                }
                if (! Schema::hasColumn('sales_returns', 'is_inward')) {
                    $table->boolean('is_inward')->default(true)->after('is_posted');
                }
                if (! Schema::hasColumn('sales_returns', 'status')) {
                    $table->string('status', 30)->default('draft')->after('is_decrease_debt');
                }
                if (! Schema::hasColumn('sales_returns', 'reference_invoice_id')) {
                    $table->foreignId('reference_invoice_id')->nullable()->after('status');
                }
                if (! Schema::hasColumn('sales_returns', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('referenced_vouchers')->constrained('users');
                }
                if (! Schema::hasColumn('sales_returns', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users');
                }
            });
        }

        if (Schema::hasTable('sales_return_lines')) {
            Schema::table('sales_return_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_return_lines', 'line_order')) {
                    $table->integer('line_order')->default(0)->after('sales_return_id');
                }
                if (! Schema::hasColumn('sales_return_lines', 'item_code')) {
                    $table->string('item_code', 50)->nullable()->after('item_id');
                }
                if (! Schema::hasColumn('sales_return_lines', 'item_name')) {
                    $table->string('item_name')->nullable()->after('item_code');
                }
                if (! Schema::hasColumn('sales_return_lines', 'cogs_debit_account')) {
                    $table->string('cogs_debit_account', 20)->default('1561')->after('cogs_account');
                }
                if (! Schema::hasColumn('sales_return_lines', 'cogs_credit_account')) {
                    $table->string('cogs_credit_account', 20)->default('632')->after('cogs_debit_account');
                }
                if (! Schema::hasColumn('sales_return_lines', 'cogs_unit_price')) {
                    $table->decimal('cogs_unit_price', 15, 2)->default(0)->after('cogs_price');
                }
                if (! Schema::hasColumn('sales_return_lines', 'invoice_number')) {
                    $table->string('invoice_number', 50)->nullable()->after('cogs_amount');
                }
                if (! Schema::hasColumn('sales_return_lines', 'invoice_date')) {
                    $table->date('invoice_date')->nullable()->after('invoice_number');
                }
                if (! Schema::hasColumn('sales_return_lines', 'sales_order_id')) {
                    $table->foreignId('sales_order_id')->nullable()->after('invoice_date');
                }
                if (! Schema::hasColumn('sales_return_lines', 'contract_id')) {
                    $table->foreignId('contract_id')->nullable()->after('sales_order_id');
                }
            });
        }

        if (Schema::hasTable('sales_discounts')) {
            Schema::table('sales_discounts', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_discounts', 'branch_id')) {
                    $table->foreignId('branch_id')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('sales_discounts', 'tax_code')) {
                    $table->string('tax_code', 50)->nullable()->after('customer_address');
                }
                if (! Schema::hasColumn('sales_discounts', 'employee_id')) {
                    $table->foreignId('employee_id')->nullable()->after('receiver_name')->constrained('employees');
                }
                if (! Schema::hasColumn('sales_discounts', 'voucher_type')) {
                    $table->string('voucher_type', 100)->default('sales_discount')->after('employee_id');
                }
                if (! Schema::hasColumn('sales_discounts', 'payment_method')) {
                    $table->string('payment_method', 50)->default('reduce_receivable')->after('voucher_type');
                }
                if (! Schema::hasColumn('sales_discounts', 'bank_account_id')) {
                    $table->foreignId('bank_account_id')->nullable()->after('payment_method')->constrained('bank_accounts');
                }
                if (! Schema::hasColumn('sales_discounts', 'description')) {
                    $table->string('description')->nullable()->after('reason');
                }
                if (! Schema::hasColumn('sales_discounts', 'grand_total')) {
                    $table->decimal('grand_total', 15, 2)->default(0)->after('total_amount');
                }
                if (! Schema::hasColumn('sales_discounts', 'status')) {
                    $table->string('status', 30)->default('draft')->after('is_decrease_debt');
                }
                if (! Schema::hasColumn('sales_discounts', 'reference_invoice_id')) {
                    $table->foreignId('reference_invoice_id')->nullable()->after('status');
                }
                if (! Schema::hasColumn('sales_discounts', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('referenced_vouchers')->constrained('users');
                }
                if (! Schema::hasColumn('sales_discounts', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users');
                }
            });
        }

        if (Schema::hasTable('sales_discount_lines')) {
            Schema::table('sales_discount_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_discount_lines', 'line_order')) {
                    $table->integer('line_order')->default(0)->after('sales_discount_id');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'item_code')) {
                    $table->string('item_code', 50)->nullable()->after('item_id');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'item_name')) {
                    $table->string('item_name')->nullable()->after('item_code');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'unit')) {
                    $table->string('unit', 50)->nullable()->after('description');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'quantity')) {
                    $table->decimal('quantity', 15, 2)->default(0)->after('credit_account');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'unit_price')) {
                    $table->decimal('unit_price', 15, 2)->default(0)->after('quantity');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'amount')) {
                    $table->decimal('amount', 15, 2)->default(0)->after('unit_price');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'invoice_number')) {
                    $table->string('invoice_number', 50)->nullable()->after('tax_account');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'invoice_date')) {
                    $table->date('invoice_date')->nullable()->after('invoice_number');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'sales_order_id')) {
                    $table->foreignId('sales_order_id')->nullable()->after('invoice_date');
                }
                if (! Schema::hasColumn('sales_discount_lines', 'contract_id')) {
                    $table->foreignId('contract_id')->nullable()->after('sales_order_id');
                }
            });
        }
    }

    public function down(): void
    {
        // No-op
    }
};
