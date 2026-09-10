<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_import_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('line_reference', 160);
            $table->date('booked_on');
            $table->date('value_on')->nullable();
            $table->string('direction', 10); // debit | credit, as reported by the bank
            $table->string('amount_raw', 80);
            $table->unsignedTinyInteger('amount_scale');
            $table->string('currency_code', 3);
            $table->string('running_balance_raw', 80)->nullable();
            $table->unsignedTinyInteger('running_balance_scale')->nullable();
            $table->string('bank_reference', 160)->nullable();
            $table->string('counterparty_name', 255)->nullable();
            $table->string('counterparty_account', 120)->nullable();
            $table->text('description')->nullable();
            $table->char('normalized_hash', 64);
            $table->json('source_payload')->nullable();
            $table->timestamps();

            $table->unique(['bank_statement_import_id', 'line_number'], 'bank_stmt_line_import_number_unique');
            $table->unique(['bank_statement_import_id', 'line_reference'], 'bank_stmt_line_import_reference_unique');
            $table->index(['company_id', 'bank_account_id', 'booked_on'], 'bank_stmt_line_account_booked_idx');
            $table->index(['company_id', 'bank_account_id', 'normalized_hash'], 'bank_stmt_line_normalized_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
