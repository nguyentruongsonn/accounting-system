<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_gl_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->date('as_of_date');
            // This foundation deliberately remains not_available until a
            // signed account mapping and statement coverage contract exist.
            $table->string('status', 30);
            $table->string('algorithm_version', 80);
            $table->char('contract_hash', 64);
            $table->json('source_completeness');
            $table->unsignedInteger('divergence_count')->default(0);
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['company_id', 'bank_account_id', 'as_of_date', 'recorded_at'], 'bank_gl_recon_company_account_cutoff_idx');
        });

        Schema::create('bank_gl_reconciliation_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_run_id')->constrained('bank_gl_reconciliation_runs')->restrictOnDelete();
            $table->string('exception_code', 100);
            $table->string('severity', 20);
            $table->text('reason');
            $table->json('evidence');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['company_id', 'reconciliation_run_id', 'severity'], 'bank_gl_recon_exception_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_gl_reconciliation_exceptions');
        Schema::dropIfExists('bank_gl_reconciliation_runs');
    }
};
