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
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 50)->unique();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->bigInteger('sub_total')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->string('status', 20)->default('Unpaid'); // Unpaid, Partially Paid, Paid
            $table->text('description')->nullable();
            $table->boolean('is_posted')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
