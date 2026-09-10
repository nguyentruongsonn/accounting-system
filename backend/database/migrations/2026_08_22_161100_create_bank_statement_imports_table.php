<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->string('source_format', 40);
            $table->string('statement_reference', 120);
            // Hash of canonical normalized input, never a hash of a raw file.
            $table->char('content_hash', 64);
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('accepted');
            $table->unsignedInteger('line_count');
            $table->json('source_metadata')->nullable();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'bank_stmt_import_company_idem_unique');
            $table->unique(['company_id', 'bank_account_id', 'content_hash'], 'bank_stmt_import_content_unique');
            $table->index(['company_id', 'bank_account_id', 'imported_at'], 'bank_stmt_import_account_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_imports');
    }
};
