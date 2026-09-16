<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['inventory_subledger_gl_reconciliation_runs', 'inventory_subledger_gl_reconciliation_exceptions'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Inventory subledger-to-GL reconciliation append-only triggers are not implemented for [{$driver}].");
        }
        foreach (self::TABLES as $table) foreach (['update', 'delete'] as $operation) {
            // MySQL limits trigger identifiers to 64 characters. The
            // exception table's conventional name would exceed that limit;
            // retain the same table/operation semantics with a stable short
            // identifier instead.
            $trigger = $table === 'inventory_subledger_gl_reconciliation_exceptions'
                ? 'inventory_gl_recon_exception_append_only_'.$operation
                : $table.'_append_only_'.$operation;
            $legacyTrigger = $table.'_append_only_'.$operation;
            // MySQL rejects even DROP TRIGGER for an identifier over 64
            // characters. Such a legacy trigger can only exist on SQLite.
            if ($legacyTrigger !== $trigger && $driver === 'sqlite') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$legacyTrigger}");
            }
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            $op = strtoupper($operation);
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON {$table} BEGIN SELECT RAISE(ABORT, 'Inventory subledger-to-GL reconciliation evidence is append-only'); END");
            } else {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Inventory subledger-to-GL reconciliation evidence is append-only'");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) foreach (['update', 'delete'] as $operation) {
            $trigger = $table === 'inventory_subledger_gl_reconciliation_exceptions'
                ? 'inventory_gl_recon_exception_append_only_'.$operation
                : $table.'_append_only_'.$operation;
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
            // Also clean up the pre-64-character name on SQLite or an older
            // deployment where this migration may already have run.
            $legacyTrigger = $table.'_append_only_'.$operation;
            if ($legacyTrigger !== $trigger && DB::connection()->getDriverName() === 'sqlite') {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$legacyTrigger);
            }
        }
    }
};
