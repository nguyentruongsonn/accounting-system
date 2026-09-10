<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoice_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // The accounting document is deliberately a polymorphic link: this evidence record does not turn an accounting invoice into a tax filing.
            $table->string('accounting_document_type', 180);
            $table->unsignedBigInteger('accounting_document_id');
            $table->string('lifecycle_status', 20); // draft, issued, replaced, adjusted, cancelled
            $table->string('provider_name', 120)->nullable();
            $table->string('provider_document_id', 190)->nullable();
            $table->string('document_reference', 190)->nullable();
            $table->char('payload_hash', 64);
            $table->json('payload_snapshot')->nullable();
            $table->foreignId('supersedes_einvoice_document_id')->nullable()->constrained('e_invoice_documents')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'accounting_document_type', 'accounting_document_id'], 'einvoice_accounting_document_idx');
            $table->index(['company_id', 'lifecycle_status', 'occurred_at'], 'einvoice_lifecycle_idx');
            $table->unique(['company_id', 'provider_name', 'provider_document_id'], 'einvoice_provider_document_unique');
        });
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) throw new RuntimeException("E-invoice append-only triggers are not implemented for database driver [{$driver}].");
        foreach (['update', 'delete'] as $operation) {
            $name = "e_invoice_documents_append_only_{$operation}";
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            if ($driver === 'sqlite') DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON e_invoice_documents BEGIN SELECT RAISE(ABORT, 'E-invoice documents are append-only'); END");
            else DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON e_invoice_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E-invoice documents are append-only'");
        }
    }
    public function down(): void
    {
        foreach (['update', 'delete'] as $operation) DB::unprepared("DROP TRIGGER IF EXISTS e_invoice_documents_append_only_{$operation}");
        Schema::dropIfExists('e_invoice_documents');
    }
};
