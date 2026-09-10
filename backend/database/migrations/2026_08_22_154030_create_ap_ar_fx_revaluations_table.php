<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_ar_fx_revaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('ledger', 2); // ap | ar
            $table->string('reference_document_type', 40); // purchase_invoice | sales_invoice
            $table->unsignedBigInteger('reference_document_id');
            $table->foreignId('reversal_of_id')->nullable()->constrained('ap_ar_fx_revaluations')->restrictOnDelete();
            $table->string('voucher_number', 80);
            $table->date('voucher_date');
            $table->date('accounting_date');
            // Exact source values: never reconstruct these from display-only floats.
            $table->string('original_currency', 3);
            $table->string('foreign_open_amount_raw', 80);
            $table->unsignedTinyInteger('foreign_open_amount_scale');
            $table->string('closing_exchange_rate_raw', 80);
            $table->unsignedTinyInteger('closing_exchange_rate_scale');
            $table->decimal('carrying_functional_amount', 20, 2);
            $table->decimal('revalued_functional_amount', 20, 2);
            $table->decimal('adjustment_functional_amount', 20, 2);
            $table->string('effect', 8); // gain | loss
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

            $table->unique(['company_id', 'voucher_number'], 'ap_ar_fx_revaluations_company_voucher_unique');
            $table->unique(['reversal_of_id'], 'ap_ar_fx_revaluations_one_reversal_unique');
            $table->index(['company_id', 'ledger', 'reference_document_type', 'reference_document_id'], 'ap_ar_fx_revaluations_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_ar_fx_revaluations');
    }
};
