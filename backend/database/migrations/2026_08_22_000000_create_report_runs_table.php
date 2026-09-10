<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An immutable logical-report snapshot.  This is intentionally separate
     * from audit_logs: audit_logs records access, while this table preserves
     * the exact logical report result that was issued at that access point.
     */
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            // A SET NULL cascade would update immutable evidence and is blocked
            // by the append-only trigger. Keep the actor reference explicit;
            // any future anonymisation/purge must use an approved retention flow.
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('report', 80);
            $table->string('delivery', 40);
            $table->string('snapshot_schema', 50)->default('report-run.v1');
            $table->json('filters');
            $table->json('period');
            $table->json('regime');
            $table->char('control_hash', 64);
            $table->char('output_hash', 64);
            $table->string('output_identity', 80);
            // This is a canonical logical JSON snapshot, not a byte-for-byte
            // copy of an XLSX/PDF renderer artifact.
            $table->json('snapshot');
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index(['company_id', 'fiscal_year_id', 'report', 'issued_at'], 'report_runs_company_period_report_issued_idx');
            $table->index(['company_id', 'output_hash'], 'report_runs_company_output_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
