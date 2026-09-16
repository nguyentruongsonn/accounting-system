<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('management_report_definitions', 'contract_hash')) {
            Schema::table('management_report_definitions', function (Blueprint $table): void {
                $table->char('contract_hash', 64)->nullable()->after('calculation_contract');
            });
        }

        // A MySQL migration can create this table before rejecting an
        // overlong generated foreign-key name. Because this migration is not
        // recorded as run in that state, it must recover only an empty partial
        // table; populated state requires an operator investigation.
        if (Schema::hasTable('management_report_effective_definitions')) {
            if (DB::table('management_report_effective_definitions')->exists()) {
                throw new RuntimeException('Cannot recover a partially-created effective-definition table that contains rows.');
            }
            Schema::drop('management_report_effective_definitions');
        }

        Schema::create('management_report_effective_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('report_key', 80);
            $table->unsignedBigInteger('management_report_definition_id');
            $table->foreign('management_report_definition_id', 'mgmt_report_effective_definition_fk')
                ->references('id')
                ->on('management_report_definitions')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'report_key'], 'management_report_effective_company_report_unique');
            $table->unique('management_report_definition_id', 'management_report_effective_definition_unique');
        });

        $this->createPublishedDefinitionTriggers();
    }

    public function down(): void
    {
        $this->dropPublishedDefinitionTriggers();
        Schema::dropIfExists('management_report_effective_definitions');
        Schema::table('management_report_definitions', function (Blueprint $table): void {
            $table->dropColumn('contract_hash');
        });
    }

    private function createPublishedDefinitionTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Management-report definition triggers are not implemented for database driver [{$driver}].");
        }

        $table = $this->quoteIdentifier($driver, DB::connection()->getTablePrefix().'management_report_definitions');
        foreach (['update', 'delete'] as $operation) {
            $trigger = $this->quoteIdentifier($driver, $this->triggerName($operation));
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            $sqlOperation = strtoupper($operation);
            $message = 'Published management report definitions are immutable';

            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$sqlOperation} ON {$table} WHEN OLD.published_at IS NOT NULL BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            } else {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$sqlOperation} ON {$table} FOR EACH ROW BEGIN IF OLD.published_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
            }
        }
    }

    private function dropPublishedDefinitionTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->quoteIdentifier($driver, $this->triggerName($operation)));
        }
    }

    private function triggerName(string $operation): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '_', DB::connection()->getTablePrefix()) ?? '';

        return "{$prefix}management_report_definition_immutable_{$operation}";
    }

    private function quoteIdentifier(string $driver, string $identifier): string
    {
        return in_array($driver, ['mysql', 'mariadb'], true)
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }
};
