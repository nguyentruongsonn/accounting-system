<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'reconciliation_runs' => 'Reconciliation runs are append-only',
        'reconciliation_check_results' => 'Reconciliation results are append-only',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Reconciliation append-only triggers are not implemented for database driver [{$driver}].");
        }

        foreach (self::TABLES as $table => $message) {
            $this->createTrigger($driver, $table, 'update', $message);
            $this->createTrigger($driver, $table, 'delete', $message);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            return;
        }

        // Drop guards before the table-creation migration removes the child and
        // parent tables. This also makes rollback independent of FK cascades.
        foreach (array_keys(self::TABLES) as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$this->quoteIdentifier(
                    $driver,
                    $this->triggerName($table, $operation)
                ));
            }
        }
    }

    private function createTrigger(string $driver, string $table, string $operation, string $message): void
    {
        $trigger = $this->quoteIdentifier($driver, $this->triggerName($table, $operation));
        $qualifiedTable = $this->quoteIdentifier($driver, DB::connection()->getTablePrefix().$table);
        $sqlOperation = strtoupper($operation);

        // MySQL DDL is not transactional. Removing a same-named trigger first
        // makes a retry recover cleanly after a partially applied migration.
        DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");

        if ($driver === 'sqlite') {
            DB::unprepared(
                "CREATE TRIGGER {$trigger} BEFORE {$sqlOperation} ON {$qualifiedTable} "
                ."BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            );

            return;
        }

        DB::unprepared(
            "CREATE TRIGGER {$trigger} BEFORE {$sqlOperation} ON {$qualifiedTable} FOR EACH ROW "
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'"
        );
    }

    private function triggerName(string $table, string $operation): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '_', DB::connection()->getTablePrefix()) ?? '';
        $base = "{$prefix}{$table}_append_only_{$operation}";

        if (strlen($base) <= 64) {
            return $base;
        }

        return substr($base, 0, 51).'_'.substr(hash('sha256', $base), 0, 12);
    }

    private function quoteIdentifier(string $driver, string $identifier): string
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return '`'.str_replace('`', '``', $identifier).'`';
        }

        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
