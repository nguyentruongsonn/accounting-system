<?php

namespace Tests\Feature;

use App\Services\AccountService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountHierarchyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_hierarchy_lock_blocks_a_second_mariadb_connection(): void
    {
        // Test classes using RefreshDatabase can leave an in-memory PDO
        // instance resolved in the manager even when the configured default
        // is SQLite.  The acceptance contract is about the configured
        // database, so inspect that source of truth before opening a second
        // connection; otherwise this MariaDB-only test can touch a schema-less
        // SQLite PDO and fail instead of skipping.
        $configuredDriver = config('database.connections.'.DB::getDefaultConnection().'.driver');
        if (! in_array($configuredDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The two-connection lock acceptance requires MariaDB/MySQL.');
        }

        $connectionName = 'account_hierarchy_concurrency_'.bin2hex(random_bytes(4));
        config(['database.connections.'.$connectionName => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge($connectionName);

        $first = DB::connection();
        $second = DB::connection($connectionName);
        $originalDefaultConnection = DB::getDefaultConnection();
        // The legacy catalogue column is intentionally narrow; keep the
        // unique marker within the deployed code length contract.
        $marker = strtoupper(bin2hex(random_bytes(2)));
        $companyId = null;
        $firstTransactionOpen = false;

        try {
            $companyId = $second->table('companies')->insertGetId([
                'name' => 'Concurrency '.$marker,
                'tax_code' => 'CON-'.$marker,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $second->table('chart_of_accounts')->insert([
                [
                    'company_id' => $companyId,
                    'code' => 'CON-SOURCE-'.$marker,
                    'name' => 'Concurrency source',
                    'type' => 'asset',
                    'nature' => 'debit',
                    'level' => 1,
                    'is_parent' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => $companyId,
                    'code' => 'CON-TARGET-'.$marker,
                    'name' => 'Concurrency target',
                    'type' => 'asset',
                    'nature' => 'debit',
                    'level' => 1,
                    'is_parent' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $first->beginTransaction();
            $firstTransactionOpen = true;
            $first->table('chart_of_accounts')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->get(['id']);

            $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
            // Route the service under test through the second independent
            // PDO connection while the first transaction holds the lock.
            DB::setDefaultConnection($connectionName);
            try {
                app(AccountService::class)->lockHierarchy($companyId);
                $this->fail('A second connection must wait for the tenant hierarchy lock.');
            } catch (QueryException $exception) {
                $this->assertTrue(
                    in_array((string) ($exception->errorInfo[1] ?? $exception->getCode()), ['1205', '1213'], true),
                    'Expected a lock wait/deadlock error, got: '.$exception->getMessage(),
                );
            }
        } finally {
            DB::setDefaultConnection($originalDefaultConnection);
            if ($firstTransactionOpen) {
                while ($first->transactionLevel() > 0) {
                    $first->rollBack();
                }
            }
            // A lock-wait timeout can leave the second PDO inside an open
            // transaction after the failed SELECT ... FOR UPDATE.  Roll it
            // back before deleting the disposable fixture or its own company
            // lock can make cleanup time out.
            while ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
            if ($companyId !== null) {
                $second->table('chart_of_accounts')->where('company_id', $companyId)->delete();
                $second->table('companies')->where('id', $companyId)->delete();
            }
            DB::disconnect($connectionName);
            config()->offsetUnset('database.connections.'.$connectionName);
        }
    }
}
