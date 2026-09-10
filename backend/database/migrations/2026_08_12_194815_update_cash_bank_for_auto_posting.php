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
        // Headers
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });
        Schema::table('bank_receipts', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });
        Schema::table('bank_payments', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });

        // Lines
        Schema::table('cash_receipt_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable(); // Can be sales_invoice or purchase_invoice
        });
        Schema::table('cash_payment_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable();
        });
        Schema::table('bank_receipt_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable();
        });
        Schema::table('bank_payment_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
