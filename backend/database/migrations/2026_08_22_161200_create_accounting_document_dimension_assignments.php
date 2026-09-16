<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_document_dimension_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // This is deliberately a small allowlisted accounting-document
            // surface, not an open polymorphic attachment table.
            $table->string('accounting_document_type', 180);
            $table->unsignedBigInteger('accounting_document_id');
            // A save creates a new immutable snapshot. The greatest revision
            // is the selected snapshot while the source document is a draft.
            $table->unsignedInteger('revision');
            $table->foreignId('accounting_policy_version_id')
                ->constrained('accounting_policy_versions', 'id', 'acct_doc_dim_policy_fk')->restrictOnDelete();
            $table->char('policy_contract_hash', 64);
            $table->date('posting_date');
            $table->string('dimension_code', 80);
            $table->foreignId('accounting_dimension_definition_id')
                ->constrained('accounting_dimension_definitions', 'id', 'acct_doc_dim_definition_fk')->restrictOnDelete();
            $table->foreignId('accounting_dimension_value_id')
                ->constrained('accounting_dimension_values', 'id', 'acct_doc_dim_value_fk')->restrictOnDelete();
            $table->foreignId('selected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('selected_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique([
                'accounting_document_type', 'accounting_document_id', 'revision', 'accounting_dimension_definition_id',
            ], 'acct_document_dimension_assignment_revision_unique');
            $table->index(['company_id', 'accounting_document_type', 'accounting_document_id', 'revision'], 'acct_document_dimension_assignment_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_document_dimension_assignments');
    }
};
