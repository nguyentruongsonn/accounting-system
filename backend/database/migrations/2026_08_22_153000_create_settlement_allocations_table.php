<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('source_document_type', 40);
            $table->unsignedBigInteger('source_document_id');
            $table->string('source_line_type', 40)->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            // Canonical non-null idempotency identity. SQL UNIQUE semantics
            // permit multiple NULL tuples, so the nullable source-line fields
            // alone cannot protect adjustment evidence from duplicate retries.
            $table->string('source_reference_key', 220);
            $table->string('target_document_type', 40);
            $table->unsignedBigInteger('target_document_id');
            $table->string('allocation_kind', 30);
            // The source systems currently mix INTEGER and DECIMAL money.
            // Preserve the canonical decimal text plus its declared scale;
            // do not silently round it into a new accounting money policy.
            $table->string('amount_raw', 80);
            $table->unsignedTinyInteger('amount_scale');
            $table->string('currency_code', 3)->nullable();
            $table->date('effective_date');
            $table->string('status', 20)->default('posted');
            $table->timestamp('posted_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'source_document_type', 'source_document_id', 'source_line_type', 'source_line_id', 'target_document_type', 'target_document_id'],
                'settlement_allocations_source_target_unique',
            );
            $table->unique(['company_id', 'source_reference_key'], 'settlement_allocations_source_reference_unique');
            $table->index(['company_id', 'target_document_type', 'target_document_id', 'effective_date'], 'settlement_allocations_target_cutoff_idx');
            $table->index(['company_id', 'source_document_type', 'source_document_id'], 'settlement_allocations_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_allocations');
    }
};
