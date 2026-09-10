<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('period_id')->constrained()->restrictOnDelete();
            $table->string('basis', 30)->default('shadow');
            $table->string('status', 30);
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->string('algorithm_version', 50);
            $table->timestamp('input_cutoff_at');
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('posted_entry_count')->default(0);
            $table->unsignedBigInteger('posted_line_count')->default(0);
            // Aggregates can exceed the precision of one legal source line.
            $table->decimal('total_debit', 30, 2)->default(0);
            $table->decimal('total_credit', 30, 2)->default(0);
            $table->unsignedInteger('result_count')->default(0);
            $table->unsignedInteger('failed_result_count')->default(0);
            $table->unsignedInteger('warning_result_count')->default(0);
            $table->unsignedInteger('not_available_result_count')->default(0);
            $table->char('snapshot_hash', 64);
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'recon_runs_company_idempotency_unique');
            $table->index(['company_id', 'period_id', 'created_at'], 'recon_runs_company_period_created_idx');
        });

        Schema::create('reconciliation_check_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->string('check_code', 100);
            $table->string('domain', 30)->default('gl');
            $table->string('status', 30);
            $table->string('algorithm_version', 50);
            $table->decimal('left_total', 30, 2)->nullable();
            $table->decimal('right_total', 30, 2)->nullable();
            $table->decimal('difference', 30, 2)->nullable();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->json('evidence');
            $table->char('fingerprint', 64);
            $table->char('result_hash', 64);
            $table->timestamps();

            $table->unique(
                ['reconciliation_run_id', 'check_code', 'fingerprint'],
                'recon_results_run_check_fingerprint_unique'
            );
            $table->index(['company_id', 'reconciliation_run_id', 'status'], 'recon_results_company_run_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_check_results');
        Schema::dropIfExists('reconciliation_runs');
    }
};
