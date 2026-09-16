<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('ledger', 2); // ap | ar
            $table->string('adjustment_kind', 30); // credit_note | write_off
            $table->string('voucher_number', 80);
            $table->date('voucher_date');
            $table->date('accounting_date');
            $table->string('reference_document_type', 40); // purchase_invoice | sales_invoice
            $table->unsignedBigInteger('reference_document_id');
            $table->foreignId('reversal_of_id')->nullable()->constrained('debt_adjustments')->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->string('debit_account', 20);
            $table->string('credit_account', 20);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft'); // draft | posted | voided
            $table->boolean('is_posted')->default(false);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'voucher_number'], 'debt_adjustments_company_voucher_unique');
            $table->index(['company_id', 'ledger', 'reference_document_type', 'reference_document_id'], 'debt_adjustments_open_item_idx');
            $table->unique(['reversal_of_id'], 'debt_adjustments_one_reversal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_adjustments');
    }
};
