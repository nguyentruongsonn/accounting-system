<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TRIGGERS = [
        'audit_logs_append_only_update' => 'UPDATE',
        'audit_logs_append_only_delete' => 'DELETE',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Audit-log append-only triggers are not implemented for database driver [{$driver}].");
        }

        foreach (self::TRIGGERS as $name => $operation) {
            // MySQL DDL is not transactional.  Dropping the same trigger first
            // makes a retried migration deterministic after partial DDL.
            DB::unprepared($this->dropStatement($driver, $name));

            if ($driver === 'sqlite') {
                DB::unprepared(
                    "CREATE TRIGGER {$name} BEFORE {$operation} ON audit_logs "
                    ."BEGIN SELECT RAISE(ABORT, 'Audit logs are append-only'); END"
                );
            } else {
                DB::unprepared(
                    "CREATE TRIGGER `{$name}` BEFORE {$operation} ON `audit_logs` "
                    ."FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only'"
                );
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            return;
        }

        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::unprepared($this->dropStatement($driver, $name));
        }
    }

    private function dropStatement(string $driver, string $name): string
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return "DROP TRIGGER IF EXISTS `{$name}`";
        }

        return "DROP TRIGGER IF EXISTS \"{$name}\"";
    }
};
