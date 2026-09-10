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
        Schema::create('voucher_references', function (Blueprint $table) {
            $table->id();
            
            // Chứng từ nguồn (VD: CashReceipt, CashPayment, BankReceipt...)
            $table->string('source_type', 100);
            $table->unsignedBigInteger('source_id');
            
            // Chứng từ đích được tham chiếu tới (VD: SalesInvoice, PurchaseInvoice, PurchaseOrder...)
            $table->string('target_type', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            
            // Thông tin hiển thị nhanh snapshot của chứng từ tham chiếu
            $table->string('target_voucher_type', 100)->nullable(); // 'Hóa đơn bán hàng', 'Đơn mua hàng'...
            $table->string('target_voucher_number', 100)->nullable(); // 'HDBH00012'
            $table->date('target_voucher_date')->nullable();
            $table->bigInteger('target_total_amount')->default(0);
            $table->string('description', 255)->nullable();
            
            $table->timestamps();

            // Indexes for fast bi-directional lookups
            $table->index(['source_type', 'source_id'], 'idx_voucher_ref_source');
            $table->index(['target_type', 'target_id'], 'idx_voucher_ref_target');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_references');
    }
};
