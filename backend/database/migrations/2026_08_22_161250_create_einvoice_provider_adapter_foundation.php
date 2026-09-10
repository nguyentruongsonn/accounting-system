<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoice_provider_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('provider_code', 80);
            // References identify a secret managed outside the accounting DB.
            // Credentials, certificates, tokens and private keys are forbidden here.
            $table->string('secret_reference', 255)->nullable();
            $table->string('endpoint_reference', 255)->nullable();
            $table->string('callback_secret_reference', 255)->nullable();
            $table->string('status', 20)->default('draft'); // draft, approved, disabled
            $table->date('effective_from');
            $table->date('effective_to');
            $table->json('capability_contract')->nullable();
            $table->json('regulatory_dependencies')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->char('contract_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'provider_code', 'status', 'effective_from'], 'einvoice_provider_config_lookup');
        });

        Schema::create('e_invoice_provider_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('e_invoice_document_id')->constrained('e_invoice_documents')->restrictOnDelete();
            $table->foreignId('provider_configuration_id')->constrained('e_invoice_provider_configurations')->restrictOnDelete();
            $table->char('idempotency_key', 64);
            $table->char('payload_hash', 64);
            $table->string('state', 30)->default('prepared'); // prepared, retry_pending, acknowledged, terminal_failed
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'einvoice_provider_dispatch_idempotency');
            $table->index(['company_id', 'state', 'next_retry_at'], 'einvoice_provider_dispatch_retry_lookup');
        });

        Schema::create('e_invoice_provider_dispatch_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('e_invoice_provider_dispatch_id')
                ->constrained('e_invoice_provider_dispatches', 'id', 'einvoice_dispatch_event_dispatch_fk')
                ->restrictOnDelete();
            $table->string('event_type', 40); // prepared, attempt_recorded, retry_scheduled, acknowledged, terminal_failed
            $table->unsignedInteger('attempt_number')->nullable();
            $table->string('provider_result_code', 120)->nullable();
            $table->char('result_payload_hash', 64)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['company_id', 'e_invoice_provider_dispatch_id', 'occurred_at'], 'einvoice_provider_dispatch_event_lookup');
        });

        foreach (['e_invoice_provider_dispatch_events'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $name = "{$table}_append_only_{$operation}";
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
                if (DB::connection()->getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON {$table} BEGIN SELECT RAISE(ABORT, 'E-invoice provider evidence is append-only'); END");
                } else {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E-invoice provider evidence is append-only'");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $operation) DB::unprepared("DROP TRIGGER IF EXISTS e_invoice_provider_dispatch_events_append_only_{$operation}");
        Schema::dropIfExists('e_invoice_provider_dispatch_events');
        Schema::dropIfExists('e_invoice_provider_dispatches');
        Schema::dropIfExists('e_invoice_provider_configurations');
    }
};
