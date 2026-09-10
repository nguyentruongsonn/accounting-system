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
        Schema::create('cash_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_receipt_id')->constrained()->cascadeOnDelete();
            $table->string('debit_account', 20); // usually 111
            $table->string('credit_account', 20);
            $table->text('description')->nullable();
            $table->bigInteger('amount')->default(0);
            $table->nullableMorphs('sub_object'); // customer, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_receipt_lines');
    }
};
