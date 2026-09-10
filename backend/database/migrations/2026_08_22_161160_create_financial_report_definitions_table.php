<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('report_key', 120);
            $table->string('definition_version', 80);
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to');
            // Owner-supplied provenance identifier. It is not an asserted
            // Appendix IV code and may identify an internal controlled form.
            $table->string('source_form_id', 160)->nullable();
            $table->json('source_contract')->nullable();
            $table->json('line_mapping_contract')->nullable();
            $table->json('sign_rounding_contract')->nullable();
            $table->json('comparative_contract')->nullable();
            $table->json('regulatory_dependencies')->nullable();
            $table->char('contract_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'report_key', 'definition_version'], 'fin_report_def_company_key_version_unique');
            $table->index(['company_id', 'report_key', 'status', 'effective_from', 'effective_to'], 'fin_report_def_resolution_idx');
        });

        $this->createImmutabilityTriggers();
    }

    public function down(): void
    {
        $this->dropImmutabilityTriggers();
        Schema::dropIfExists('financial_report_definitions');
    }

    private function createImmutabilityTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Financial-report definition triggers are not implemented for database driver [{$driver}].");
        }
        $table = $this->quote($driver, DB::connection()->getTablePrefix().'financial_report_definitions');
        foreach (['update', 'delete'] as $operation) {
            $trigger = $this->quote($driver, $this->triggerName($operation));
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            $op = strtoupper($operation);
            if ($driver === 'sqlite') {
                // A published row is wholly immutable. An approved row may
                // receive only the one-time publication event; its signed
                // contract and approval evidence cannot be altered.
                $when = $operation === 'delete'
                    ? 'OLD.approved_at IS NOT NULL'
                    : "OLD.published_at IS NOT NULL OR (OLD.approved_at IS NOT NULL AND (NEW.company_id IS NOT OLD.company_id OR NEW.report_key IS NOT OLD.report_key OR NEW.definition_version IS NOT OLD.definition_version OR NEW.effective_from IS NOT OLD.effective_from OR NEW.effective_to IS NOT OLD.effective_to OR NEW.source_form_id IS NOT OLD.source_form_id OR NEW.source_contract IS NOT OLD.source_contract OR NEW.line_mapping_contract IS NOT OLD.line_mapping_contract OR NEW.sign_rounding_contract IS NOT OLD.sign_rounding_contract OR NEW.comparative_contract IS NOT OLD.comparative_contract OR NEW.regulatory_dependencies IS NOT OLD.regulatory_dependencies OR NEW.contract_hash IS NOT OLD.contract_hash OR NEW.created_by IS NOT OLD.created_by OR NEW.approved_by IS NOT OLD.approved_by OR NEW.approved_at IS NOT OLD.approved_at OR NEW.status NOT IN ('approved','published') OR (NEW.status = 'approved' AND (NEW.published_by IS NOT NULL OR NEW.published_at IS NOT NULL)) OR (NEW.status = 'published' AND (NEW.published_by IS NULL OR NEW.published_at IS NULL))))";
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON {$table} WHEN {$when} BEGIN SELECT RAISE(ABORT, 'Approved financial report definitions are immutable'); END");
            } else {
                $condition = $operation === 'delete'
                    ? 'OLD.approved_at IS NOT NULL'
                    : "OLD.published_at IS NOT NULL OR (OLD.approved_at IS NOT NULL AND (NOT (NEW.company_id <=> OLD.company_id) OR NOT (NEW.report_key <=> OLD.report_key) OR NOT (NEW.definition_version <=> OLD.definition_version) OR NOT (NEW.effective_from <=> OLD.effective_from) OR NOT (NEW.effective_to <=> OLD.effective_to) OR NOT (NEW.source_form_id <=> OLD.source_form_id) OR NOT (NEW.source_contract <=> OLD.source_contract) OR NOT (NEW.line_mapping_contract <=> OLD.line_mapping_contract) OR NOT (NEW.sign_rounding_contract <=> OLD.sign_rounding_contract) OR NOT (NEW.comparative_contract <=> OLD.comparative_contract) OR NOT (NEW.regulatory_dependencies <=> OLD.regulatory_dependencies) OR NOT (NEW.contract_hash <=> OLD.contract_hash) OR NOT (NEW.created_by <=> OLD.created_by) OR NOT (NEW.approved_by <=> OLD.approved_by) OR NOT (NEW.approved_at <=> OLD.approved_at) OR NEW.status NOT IN ('approved', 'published') OR (NEW.status = 'approved' AND (NEW.published_by IS NOT NULL OR NEW.published_at IS NOT NULL)) OR (NEW.status = 'published' AND (NEW.published_by IS NULL OR NEW.published_at IS NULL))))";
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved financial report definitions are immutable'; END IF; END");
            }
        }
    }

    private function dropImmutabilityTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) return;
        foreach (['update', 'delete'] as $operation) DB::unprepared('DROP TRIGGER IF EXISTS '.$this->quote($driver, $this->triggerName($operation)));
    }

    private function triggerName(string $operation): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '_', DB::connection()->getTablePrefix()) ?? '';
        return "{$prefix}financial_report_definition_immutable_{$operation}";
    }
    private function quote(string $driver, string $identifier): string { return in_array($driver, ['mysql', 'mariadb'], true) ? '`'.str_replace('`', '``', $identifier).'`' : '"'.str_replace('"', '""', $identifier).'"'; }
};
