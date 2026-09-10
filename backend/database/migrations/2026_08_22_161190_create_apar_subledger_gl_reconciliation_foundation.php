<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apar_subledger_gl_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('ledger', 2);
            $table->date('as_of_date');
            // A foundation can only say not_available until the complete
            // reducer and 131/331 account mapping are owner-approved.
            $table->string('status', 30);
            $table->string('algorithm_version', 60);
            $table->char('contract_hash', 64);
            $table->json('source_completeness');
            $table->unsignedInteger('divergence_count')->default(0);
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['company_id', 'ledger', 'as_of_date', 'recorded_at'], 'apar_gl_recon_company_ledger_cutoff_idx');
        });

        Schema::create('apar_subledger_gl_reconciliation_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_run_id')
                ->constrained('apar_subledger_gl_reconciliation_runs', 'id', 'apar_gl_recon_exception_run_fk')
                ->restrictOnDelete();
            $table->string('exception_code', 100);
            $table->string('severity', 20);
            $table->text('reason');
            $table->json('evidence');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['company_id', 'reconciliation_run_id', 'severity'], 'apar_gl_recon_exception_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apar_subledger_gl_reconciliation_exceptions');
        Schema::dropIfExists('apar_subledger_gl_reconciliation_runs');
    }
};
