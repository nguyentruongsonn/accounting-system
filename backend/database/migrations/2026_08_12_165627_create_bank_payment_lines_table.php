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
        Schema::create('bank_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_payment_id')->constrained('bank_payments')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('debit_account', 20); // E.g., 331, 642
            $table->string('credit_account', 20)->default('1121'); // Default to bank account
            $table->bigInteger('amount')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_payment_lines');
    }
};
