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
        Schema::create('sales_discount_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_discount_id')->constrained('sales_discounts')->onDelete('cascade');
            $table->integer('line_order')->default(0);
            $table->foreignId('item_id')->nullable()->constrained('items');
            $table->string('item_code', 50)->nullable();
            $table->string('item_name')->nullable();
            $table->string('description')->nullable();
            $table->string('unit', 50)->nullable();

            // Tài khoản
            $table->string('debit_account', 20)->default('5213'); // Giảm giá hàng bán (TT200) hoặc 511 (TT133)
            $table->string('credit_account', 20)->default('131'); // Phải thu khách hàng / 1111 / 1121

            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);

            // Thuế GTGT
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->string('tax_account', 20)->default('33311');

            // Tham chiếu hóa đơn / đơn hàng
            $table->string('invoice_number', 50)->nullable();
            $table->date('invoice_date')->nullable();
            $table->foreignId('sales_order_id')->nullable();
            $table->foreignId('contract_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_discount_lines');
    }
};
