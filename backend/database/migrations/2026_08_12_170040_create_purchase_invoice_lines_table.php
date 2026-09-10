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
        Schema::create('purchase_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('debit_account', 20); // E.g., 156, 152, 642
            $table->string('credit_account', 20)->default('331'); // Payable to supplier
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->bigInteger('amount')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0); // e.g., 10.00
            $table->bigInteger('tax_amount')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_lines');
    }
};
