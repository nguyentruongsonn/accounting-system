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
        Schema::create('cash_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('voucher_number', 50);
            $table->date('voucher_date');
            $table->date('posting_date');
            $table->string('payer_name')->nullable();
            $table->string('payer_address')->nullable();
            $table->text('reason')->nullable();
            $table->bigInteger('total_amount')->default(0);
            $table->enum('status', ['draft', 'approved', 'posted', 'voided'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['company_id', 'voucher_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_receipts');
    }
};
