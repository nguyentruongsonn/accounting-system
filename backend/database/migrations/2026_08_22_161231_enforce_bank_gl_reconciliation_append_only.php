<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['bank_gl_reconciliation_runs', 'bank_gl_reconciliation_exceptions'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Bank-to-GL reconciliation append-only triggers are not implemented for [{$driver}].");
        }
        foreach (self::TABLES as $table) foreach (['update', 'delete'] as $operation) {
            $trigger = $table.'_append_only_'.$operation;
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            $op = strtoupper($operation);
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON {$table} BEGIN SELECT RAISE(ABORT, 'Bank-to-GL reconciliation evidence is append-only'); END");
            } else {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bank-to-GL reconciliation evidence is append-only'");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) foreach (['update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_append_only_'.$operation);
        }
    }
};
