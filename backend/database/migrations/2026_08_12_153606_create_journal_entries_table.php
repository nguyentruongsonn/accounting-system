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
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->string('voucher_type', 50); // e.g., 'cash_receipt', 'general_journal'
            $table->string('voucher_number', 50);
            $table->date('voucher_date');
            $table->date('posting_date');
            $table->text('description');
            $table->bigInteger('total_amount')->default(0);
            $table->enum('status', ['draft', 'approved', 'posted', 'voided'])->default('draft');
            $table->nullableMorphs('source_document'); // For relating back to the original voucher (e.g. CashReceipt)
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['company_id', 'voucher_number', 'fiscal_year_id']);
            $table->index(['company_id', 'posting_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
