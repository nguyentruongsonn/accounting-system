<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Approval decision append-only triggers are not implemented for database driver [{$driver}].");
        }
        foreach (['update', 'delete'] as $operation) {
            $name = 'approval_decisions_append_only_'.$operation;
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON approval_decisions BEGIN SELECT RAISE(ABORT, 'Approval decisions are append-only'); END");
            } else {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON approval_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approval decisions are append-only'");
            }
        }
    }
    public function down(): void { foreach (['update', 'delete'] as $operation) { DB::unprepared("DROP TRIGGER IF EXISTS approval_decisions_append_only_{$operation}"); } }
};
