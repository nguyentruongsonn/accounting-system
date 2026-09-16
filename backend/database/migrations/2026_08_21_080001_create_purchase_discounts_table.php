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
        if (! Schema::hasTable('purchase_discounts')) {
            Schema::create('purchase_discounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies');
                $table->foreignId('branch_id')->nullable();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
                $table->string('supplier_name')->nullable();
                $table->string('supplier_address')->nullable();
                $table->string('tax_code', 50)->nullable();
                $table->string('deliverer_name')->nullable();
                $table->string('receiver_name')->nullable();
                $table->foreignId('employee_id')->nullable()->constrained('employees');
                $table->string('voucher_type', 100)->default('purchase_discount');
                $table->string('payment_method', 50)->default('reduce_payable'); // reduce_payable, cash, bank
                $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts');
                $table->string('voucher_number', 50)->unique();
                $table->date('voucher_date');
                $table->date('accounting_date');
                $table->string('reason')->nullable();
                $table->string('description')->nullable();
                $table->string('attached_docs')->nullable();

                $table->decimal('sub_total', 15, 2)->default(0); // Tổng tiền giảm giá hàng mua
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('total_amount', 15, 2)->default(0); // Tổng thanh toán / giảm nợ
                $table->decimal('grand_total', 15, 2)->default(0);

                $table->boolean('is_posted')->default(false);
                $table->boolean('is_decrease_debt')->default(true); // Giảm trừ công nợ phải trả NCC
                $table->string('status', 30)->default('draft');

                $table->foreignId('reference_invoice_id')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
                $table->json('referenced_vouchers')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->foreignId('updated_by')->nullable()->constrained('users');

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_discount_lines')) {
            Schema::create('purchase_discount_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_discount_id')->constrained('purchase_discounts')->onDelete('cascade');
                $table->integer('line_order')->default(0);
                $table->foreignId('item_id')->nullable()->constrained('items');
                $table->string('item_code', 50)->nullable();
                $table->string('item_name')->nullable();
                $table->string('description')->nullable();
                $table->string('unit', 50)->nullable();

                // Định khoản VAS TT200: Nợ 331 / Có 1561, 152, 632, 1331
                $table->string('debit_account', 20)->default('331'); // Phải trả người bán / 1111 / 1121
                $table->string('credit_account', 20)->default('1561'); // Giảm giá trị hàng tồn kho 1561 / 152 / 632

                $table->decimal('quantity', 15, 2)->default(0);
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);

                // Thuế GTGT giảm
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->string('tax_account', 20)->default('1331');

                // Tham chiếu hóa đơn / đơn mua
                $table->string('invoice_number', 50)->nullable();
                $table->date('invoice_date')->nullable();
                $table->foreignId('order_id')->nullable();
                $table->foreignId('contract_id')->nullable();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_discount_lines');
        Schema::dropIfExists('purchase_discounts');
    }
};
