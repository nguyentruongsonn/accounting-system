<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_financial_statement_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_regime_profile_id')
                ->constrained('accounting_regime_profiles', 'id', 'stat_stmt_def_regime_profile_fk')
                ->restrictOnDelete();
            $table->string('form_key', 120);
            $table->string('definition_version', 80);
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to');
            // All content is owner-supplied controlled evidence.  In
            // particular, this does not embed an invented Appendix IV form.
            $table->json('provenance_contract')->nullable();
            $table->json('form_contract')->nullable();
            $table->json('line_definitions')->nullable();
            $table->json('line_mapping_contract')->nullable();
            $table->json('presentation_contract')->nullable();
            $table->json('notes_requirement_contract')->nullable();
            $table->json('regulatory_dependencies')->nullable();
            $table->char('contract_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'accounting_regime_profile_id', 'form_key', 'definition_version'], 'stat_stmt_def_company_regime_form_version_unique');
            $table->index(['company_id', 'accounting_regime_profile_id', 'form_key', 'status', 'effective_from', 'effective_to'], 'stat_stmt_def_resolution_idx');
        });
        $this->createImmutabilityTriggers();
    }

    public function down(): void
    {
        $this->dropImmutabilityTriggers();
        Schema::dropIfExists('statutory_financial_statement_definitions');
    }

    private function createImmutabilityTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) throw new RuntimeException("Statement definition triggers are not implemented for database driver [{$driver}].");
        $table = $this->quote($driver, DB::connection()->getTablePrefix().'statutory_financial_statement_definitions');
        foreach (['update', 'delete'] as $operation) {
            $trigger = $this->quote($driver, $this->triggerName($operation));
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            $op = strtoupper($operation);
            if ($driver === 'sqlite') {
                $when = $operation === 'delete' ? 'OLD.approved_at IS NOT NULL' : "OLD.published_at IS NOT NULL OR (OLD.approved_at IS NOT NULL AND (NEW.company_id IS NOT OLD.company_id OR NEW.accounting_regime_profile_id IS NOT OLD.accounting_regime_profile_id OR NEW.form_key IS NOT OLD.form_key OR NEW.definition_version IS NOT OLD.definition_version OR NEW.effective_from IS NOT OLD.effective_from OR NEW.effective_to IS NOT OLD.effective_to OR NEW.provenance_contract IS NOT OLD.provenance_contract OR NEW.form_contract IS NOT OLD.form_contract OR NEW.line_definitions IS NOT OLD.line_definitions OR NEW.line_mapping_contract IS NOT OLD.line_mapping_contract OR NEW.presentation_contract IS NOT OLD.presentation_contract OR NEW.notes_requirement_contract IS NOT OLD.notes_requirement_contract OR NEW.regulatory_dependencies IS NOT OLD.regulatory_dependencies OR NEW.contract_hash IS NOT OLD.contract_hash OR NEW.created_by IS NOT OLD.created_by OR NEW.approved_by IS NOT OLD.approved_by OR NEW.approved_at IS NOT OLD.approved_at OR NEW.status NOT IN ('approved','published') OR (NEW.status = 'approved' AND (NEW.published_by IS NOT NULL OR NEW.published_at IS NOT NULL)) OR (NEW.status = 'published' AND (NEW.published_by IS NULL OR NEW.published_at IS NULL))))";
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON {$table} WHEN {$when} BEGIN SELECT RAISE(ABORT, 'Approved statutory statement definitions are immutable'); END");
            } else {
                $condition = $operation === 'delete' ? 'OLD.approved_at IS NOT NULL' : "OLD.published_at IS NOT NULL OR (OLD.approved_at IS NOT NULL AND (NOT (NEW.company_id <=> OLD.company_id) OR NOT (NEW.accounting_regime_profile_id <=> OLD.accounting_regime_profile_id) OR NOT (NEW.form_key <=> OLD.form_key) OR NOT (NEW.definition_version <=> OLD.definition_version) OR NOT (NEW.effective_from <=> OLD.effective_from) OR NOT (NEW.effective_to <=> OLD.effective_to) OR NOT (NEW.provenance_contract <=> OLD.provenance_contract) OR NOT (NEW.form_contract <=> OLD.form_contract) OR NOT (NEW.line_definitions <=> OLD.line_definitions) OR NOT (NEW.line_mapping_contract <=> OLD.line_mapping_contract) OR NOT (NEW.presentation_contract <=> OLD.presentation_contract) OR NOT (NEW.notes_requirement_contract <=> OLD.notes_requirement_contract) OR NOT (NEW.regulatory_dependencies <=> OLD.regulatory_dependencies) OR NOT (NEW.contract_hash <=> OLD.contract_hash) OR NOT (NEW.created_by <=> OLD.created_by) OR NOT (NEW.approved_by <=> OLD.approved_by) OR NOT (NEW.approved_at <=> OLD.approved_at) OR NEW.status NOT IN ('approved', 'published') OR (NEW.status = 'approved' AND (NEW.published_by IS NOT NULL OR NEW.published_at IS NOT NULL)) OR (NEW.status = 'published' AND (NEW.published_by IS NULL OR NEW.published_at IS NULL))))";
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved statutory statement definitions are immutable'; END IF; END");
            }
        }
    }

    private function dropImmutabilityTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) return;
        foreach (['update', 'delete'] as $operation) DB::unprepared('DROP TRIGGER IF EXISTS '.$this->quote($driver, $this->triggerName($operation)));
    }

    private function triggerName(string $operation): string { $prefix = preg_replace('/[^A-Za-z0-9_]/', '_', DB::connection()->getTablePrefix()) ?? ''; return "{$prefix}statutory_statement_definition_immutable_{$operation}"; }
    private function quote(string $driver, string $identifier): string { return in_array($driver, ['mysql', 'mariadb'], true) ? '`'.str_replace('`', '``', $identifier).'`' : '"'.str_replace('"', '""', $identifier).'"'; }
};
