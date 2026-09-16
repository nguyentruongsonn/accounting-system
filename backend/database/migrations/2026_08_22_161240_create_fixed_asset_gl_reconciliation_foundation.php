<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_gl_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('as_of_date');
            // A capability evidence record; it is not authority to close a period.
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
            $table->index(['company_id', 'as_of_date', 'recorded_at'], 'fixed_asset_gl_recon_company_cutoff_idx');
        });

        Schema::create('fixed_asset_gl_reconciliation_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_run_id')
                ->constrained('fixed_asset_gl_reconciliation_runs', 'id', 'fixed_asset_gl_exception_run_fk')
                ->restrictOnDelete();
            $table->string('exception_code', 100);
            $table->string('severity', 20);
            $table->text('reason');
            $table->json('evidence');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['company_id', 'reconciliation_run_id', 'severity'], 'fixed_asset_gl_recon_exception_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_gl_reconciliation_exceptions');
        Schema::dropIfExists('fixed_asset_gl_reconciliation_runs');
    }
};
