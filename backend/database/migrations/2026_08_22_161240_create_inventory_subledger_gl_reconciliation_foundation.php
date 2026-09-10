<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_subledger_gl_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('as_of_date');
            // This evidence object is deliberately never a close permission.
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
            $table->index(['company_id', 'as_of_date', 'recorded_at'], 'inventory_gl_recon_company_cutoff_idx');
        });

        Schema::create('inventory_subledger_gl_reconciliation_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('companies', 'id', 'inventory_gl_exception_company_fk')
                ->restrictOnDelete();
            $table->foreignId('reconciliation_run_id')
                ->constrained('inventory_subledger_gl_reconciliation_runs', 'id', 'inventory_gl_exception_run_fk')
                ->restrictOnDelete();
            $table->string('exception_code', 110);
            $table->string('severity', 20);
            $table->text('reason');
            $table->json('evidence');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['company_id', 'reconciliation_run_id', 'severity'], 'inventory_gl_recon_exception_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_subledger_gl_reconciliation_exceptions');
        Schema::dropIfExists('inventory_subledger_gl_reconciliation_runs');
    }
};
