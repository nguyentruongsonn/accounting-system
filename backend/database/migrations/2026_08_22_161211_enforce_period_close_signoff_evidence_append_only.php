<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['period_close_signoff_packages', 'period_close_signoff_events'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Period-close signoff append-only triggers are not implemented for database driver [{$driver}].");
        }
        foreach (self::TABLES as $table) foreach (['update', 'delete'] as $operation) {
            $name = "{$table}_append_only_{$operation}";
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON {$table} BEGIN SELECT RAISE(ABORT, 'Period-close signoff evidence is append-only'); END");
            } else {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Period-close signoff evidence is append-only'");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) foreach (['update', 'delete'] as $operation) DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only_{$operation}");
    }
};
