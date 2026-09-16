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
        // 1. Purchase Orders (Đơn mua hàng)
        if (!Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(1);
                $table->string('order_number')->unique(); // ĐMH00001
                $table->date('order_date');
                $table->date('delivery_date')->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->string('supplier_code')->nullable();
                $table->string('supplier_name')->nullable();
                $table->string('supplier_address')->nullable();
                $table->string('tax_code')->nullable();
                $table->string('contact_person')->nullable();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('buyer_name')->nullable();
                $table->string('payment_terms')->nullable();
                $table->integer('due_days')->default(30);
                $table->text('delivery_address')->nullable();
                $table->text('other_terms')->nullable();
                $table->text('description')->nullable();
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('vat_amount', 18, 2)->default(0);
                $table->decimal('grand_total', 18, 2)->default(0);
                $table->string('status')->default('pending'); // pending, processing, completed, cancelled
                $table->string('currency')->default('VND');
                $table->decimal('exchange_rate', 10, 4)->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('purchase_order_lines')) {
            Schema::create('purchase_order_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
                $table->unsignedBigInteger('item_id')->nullable();
                $table->string('item_code')->nullable();
                $table->string('item_name')->nullable();
                $table->string('unit')->nullable();
                $table->decimal('quantity', 14, 2)->default(1);
                $table->decimal('received_quantity', 14, 2)->default(0);
                $table->decimal('unit_price', 18, 2)->default(0);
                $table->decimal('amount', 18, 2)->default(0);
                $table->decimal('discount_rate', 5, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(10);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->timestamps();
            });
        }

        // 2. Purchase Contracts (Hợp đồng mua hàng)
        if (!Schema::hasTable('purchase_contracts')) {
            Schema::create('purchase_contracts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(1);
                $table->string('contract_number')->unique(); // HĐM00001
                $table->string('contract_name')->nullable();
                $table->date('signed_date');
                $table->date('effective_date')->nullable();
                $table->date('end_date')->nullable();
                $table->date('delivery_deadline')->nullable();
                $table->date('payment_deadline')->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->string('supplier_code')->nullable();
                $table->string('supplier_name')->nullable();
                $table->string('supplier_address')->nullable();
                $table->string('tax_code')->nullable();
                $table->string('contact_person')->nullable();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('buyer_name')->nullable();
                $table->decimal('contract_value', 18, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('vat_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->string('status')->default('pending'); // pending (Chưa thực hiện), active (Đang thực hiện), liquidated (Đã thanh lý), cancelled (Đã hủy bỏ)
                $table->string('delivery_status')->default('not_delivered'); // not_delivered, partial, delivered
                $table->text('summary')->nullable(); // Trích yếu
                $table->decimal('liquidation_value', 18, 2)->default(0);
                $table->date('liquidation_date')->nullable();
                $table->text('liquidation_reason')->nullable();
                $table->text('delivery_address')->nullable();
                $table->text('other_terms')->nullable();
                $table->string('currency')->default('VND');
                $table->decimal('exchange_rate', 10, 4)->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('purchase_contract_lines')) {
            Schema::create('purchase_contract_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_contract_id')->constrained('purchase_contracts')->cascadeOnDelete();
                $table->unsignedBigInteger('item_id')->nullable();
                $table->string('item_code')->nullable();
                $table->string('item_name')->nullable();
                $table->string('unit')->nullable();
                $table->decimal('quantity_requested', 14, 2)->default(1);
                $table->decimal('quantity_delivered', 14, 2)->default(0);
                $table->decimal('unit_price', 18, 2)->default(0);
                $table->decimal('amount', 18, 2)->default(0);
                $table->decimal('discount_rate', 5, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(10);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('purchase_contract_payments')) {
            Schema::create('purchase_contract_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_contract_id')->constrained('purchase_contracts')->cascadeOnDelete();
                $table->integer('stage_number')->default(1);
                $table->decimal('payment_rate', 5, 2)->default(0); // Tỷ lệ %
                $table->decimal('payment_amount', 18, 2)->default(0);
                $table->date('due_date')->nullable();
                $table->date('payment_date')->nullable();
                $table->decimal('paid_amount', 18, 2)->default(0);
                $table->decimal('previous_year_paid', 18, 2)->default(0);
                $table->decimal('remaining_amount', 18, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_contract_payments');
        Schema::dropIfExists('purchase_contract_lines');
        Schema::dropIfExists('purchase_contracts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
