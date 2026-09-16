<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'accounting_document_dimension_assignments';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Document-dimension append-only triggers are not implemented for [{$driver}].");
        }

        foreach (['update', 'delete'] as $operation) {
            $trigger = self::TABLE.'_append_only_'.$operation;
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            $op = strtoupper($operation);
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON ".self::TABLE." BEGIN SELECT RAISE(ABORT, 'Accounting document dimension evidence is append-only'); END");
            } else {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$op} ON `".self::TABLE."` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accounting document dimension evidence is append-only'");
            }
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::TABLE.'_append_only_'.$operation);
        }
    }
};
