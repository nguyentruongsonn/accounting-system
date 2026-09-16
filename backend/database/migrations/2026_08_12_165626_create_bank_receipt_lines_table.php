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
        Schema::create('bank_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_receipt_id')->constrained('bank_receipts')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('debit_account', 20)->default('1121'); // Default to bank account
            $table->string('credit_account', 20); // E.g., 131, 511
            $table->bigInteger('amount')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_receipt_lines');
    }
};
