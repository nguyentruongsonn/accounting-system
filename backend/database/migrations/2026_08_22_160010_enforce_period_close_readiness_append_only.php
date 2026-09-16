<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'period_close_readiness_snapshots';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Period-close readiness append-only triggers are not implemented for database driver [{$driver}].");
        }

        foreach (['update', 'delete'] as $operation) {
            $trigger = $this->quoteIdentifier($driver, $this->triggerName($operation));
            $table = $this->quoteIdentifier($driver, DB::connection()->getTablePrefix().self::TABLE);
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE ".strtoupper($operation)." ON {$table} BEGIN SELECT RAISE(ABORT, 'Period-close readiness snapshots are append-only'); END");
            } else {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE ".strtoupper($operation)." ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Period-close readiness snapshots are append-only'");
            }
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

    private function triggerName(string $operation): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '_', DB::connection()->getTablePrefix()) ?? '';
        return "{$prefix}".self::TABLE."_append_only_{$operation}";
    }

    private function quoteIdentifier(string $driver, string $identifier): string
    {
        return in_array($driver, ['mysql', 'mariadb'], true)
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }
};
