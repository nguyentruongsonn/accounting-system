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
        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('debit_account', 20)->default('131'); // Receivable from customer
            $table->string('credit_account', 20); // E.g., 5111, 5112, 5113
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
        Schema::dropIfExists('sales_invoice_lines');
    }
};
