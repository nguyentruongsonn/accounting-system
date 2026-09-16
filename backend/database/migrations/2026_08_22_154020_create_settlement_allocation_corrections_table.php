<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_allocation_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('reverses_allocation_id')->constrained('settlement_allocations')->restrictOnDelete();
            $table->string('source_document_type', 40);
            $table->unsignedBigInteger('source_document_id');
            $table->string('source_line_type', 40);
            $table->unsignedBigInteger('source_line_id');
            $table->string('voucher_number', 80);
            $table->date('voucher_date');
            $table->date('accounting_date');
            $table->decimal('amount', 20, 2);
            // Preserve the native payment-line precision (cash=0, bank=2).
            $table->unsignedTinyInteger('amount_scale');
            $table->string('debit_account', 20);
            $table->string('credit_account', 20);
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_posted')->default(false);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['reverses_allocation_id'], 'settlement_corrections_one_allocation_unique');
            $table->unique(['company_id', 'voucher_number'], 'settlement_corrections_company_voucher_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_allocation_corrections');
    }
};
