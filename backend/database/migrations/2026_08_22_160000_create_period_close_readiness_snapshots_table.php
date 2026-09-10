<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_close_readiness_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('period_id')->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_run_id')->nullable()->constrained('reconciliation_runs')->restrictOnDelete();
            // This is evidence, not an approval. Current service can only
            // create `blocked` snapshots until an owner-approved enforced
            // reconciliation gate exists.
            $table->string('status', 30);
            $table->boolean('eligible_to_close')->default(false);
            $table->string('schema_version', 50);
            $table->char('snapshot_hash', 64);
            $table->json('snapshot');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['company_id', 'period_id', 'created_at'], 'close_ready_company_period_created_idx');
            $table->index(['company_id', 'period_id', 'eligible_to_close'], 'close_ready_company_period_eligible_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_close_readiness_snapshots');
    }
};
