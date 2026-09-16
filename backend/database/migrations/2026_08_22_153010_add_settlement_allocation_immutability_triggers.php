<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Settlement allocation triggers are not implemented for database driver [{$driver}].");
        }

        $table = $this->quote($driver, DB::connection()->getTablePrefix().'settlement_allocations');
        foreach (['update', 'delete'] as $operation) {
            $trigger = $this->quote($driver, $this->name($operation));
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            $verb = strtoupper($operation);
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$verb} ON {$table} WHEN OLD.status = 'posted' BEGIN SELECT RAISE(ABORT, 'Posted settlement allocations are immutable'); END");
            } else {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$verb} ON {$table} FOR EACH ROW BEGIN IF OLD.status = 'posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted settlement allocations are immutable'; END IF; END");
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
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->quote($driver, $this->name($operation)));
        }
    }

    private function name(string $operation): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '_', DB::connection()->getTablePrefix()) ?? '';

        return "{$prefix}settlement_allocation_immutable_{$operation}";
    }

    private function quote(string $driver, string $identifier): string
    {
        return in_array($driver, ['mysql', 'mariadb'], true)
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }
};
