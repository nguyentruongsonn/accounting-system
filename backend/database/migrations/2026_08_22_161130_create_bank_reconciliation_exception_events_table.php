<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_exception_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Keep the nullable column declaration separate from the FK so the
            // explicit name stays below MySQL's 64-character identifier limit.
            // The implicit name would be
            // bank_reconciliation_exception_events_bank_statement_line_id_foreign
            // (67 chars) and fails on MySQL while SQLite accepts it.
            $table->foreignId('bank_statement_line_id')->nullable();
            $table->foreign('bank_statement_line_id', 'bank_recon_exception_line_fk')
                ->references('id')
                ->on('bank_statement_lines')
                ->restrictOnDelete();
            $table->string('exception_key', 100);
            $table->string('exception_code', 60);
            $table->string('event_type', 20); // opened | resolved | waived | reopened
            $table->string('severity', 20); // info | warning | blocking
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['company_id', 'exception_key', 'recorded_at'], 'bank_recon_exception_key_time_idx');
            $table->index(['company_id', 'exception_code', 'recorded_at'], 'bank_recon_exception_code_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_exception_events');
    }
};
