<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_match_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('supersedes_event_id')->nullable()->constrained('bank_reconciliation_match_events')->restrictOnDelete();
            $table->string('candidate_type', 30); // bank_payment | bank_receipt
            $table->unsignedBigInteger('candidate_id');
            $table->string('decision', 20); // proposed | confirmed | rejected | reversed
            $table->string('amount_raw', 80);
            $table->unsignedTinyInteger('amount_scale');
            $table->text('reason')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['company_id', 'bank_statement_line_id', 'recorded_at'], 'bank_match_line_time_idx');
            $table->index(['company_id', 'candidate_type', 'candidate_id'], 'bank_match_candidate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_match_events');
    }
};
