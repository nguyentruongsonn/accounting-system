<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Report-run append-only triggers are not implemented for database driver [{$driver}].");
        }

        foreach (['update', 'delete'] as $operation) {
            $this->createTrigger($driver, $operation);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->quoteIdentifier($driver, $this->triggerName($operation)));
        }
    }

    private function createTrigger(string $driver, string $operation): void
    {
        $trigger = $this->quoteIdentifier($driver, $this->triggerName($operation));
        $table = $this->quoteIdentifier($driver, DB::connection()->getTablePrefix().'report_runs');
        $sqlOperation = strtoupper($operation);
        $message = 'Report runs are append-only';

        DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$sqlOperation} ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END");

            return;
        }

        DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$sqlOperation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
    }

    private function triggerName(string $operation): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '_', DB::connection()->getTablePrefix()) ?? '';

        return "{$prefix}report_runs_append_only_{$operation}";
    }

    private function quoteIdentifier(string $driver, string $identifier): string
    {
        return in_array($driver, ['mysql', 'mariadb'], true)
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }
};
