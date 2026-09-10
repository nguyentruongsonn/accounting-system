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
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->string('customer_name')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('tax_code', 50)->nullable();
            $table->string('receiver_name')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->string('voucher_type', 100)->default('sales_return');
            $table->string('payment_method', 50)->default('reduce_receivable'); // reduce_receivable, cash, bank
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts');
            $table->string('voucher_number', 50)->unique();
            $table->date('voucher_date');
            $table->date('accounting_date');
            $table->string('reason')->nullable();
            $table->string('description')->nullable();
            $table->string('attached_docs')->nullable();

            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('cogs_total_amount', 15, 2)->default(0);

            $table->boolean('is_posted')->default(false);
            $table->boolean('is_inward')->default(true); // Nhập kho hàng bán trả lại
            $table->boolean('is_import_slip')->default(true); // Alias for is_inward
            $table->boolean('is_decrease_debt')->default(true); // Giảm trừ công nợ
            $table->string('status', 30)->default('draft');

            $table->foreignId('reference_invoice_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->json('referenced_vouchers')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
