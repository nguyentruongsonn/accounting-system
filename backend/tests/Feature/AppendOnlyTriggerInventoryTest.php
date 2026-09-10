<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema-level coverage for the append-only/immutable evidence controls.
 *
 * This is intentionally a bounded schema inventory, not a legal or accounting
 * mapping. Each listed table has a migration that installs both a BEFORE
 * UPDATE and a BEFORE DELETE trigger. The test reads the installed trigger
 * metadata so a clean migrate:fresh cannot silently omit one of those guards.
 */
class AppendOnlyTriggerInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables whose migrations install both mutation guards.
     *
     * Keep this list limited to database-trigger controls. Tables with only
     * an UPDATE transition-integrity trigger (for example approval_requests)
     * are intentionally outside this append-only inventory.
     */
    private const CONTROLLED_EVIDENCE_TABLES = [
        'reconciliation_runs',
        'reconciliation_check_results',
        'report_runs',
        'management_report_definitions',
        'settlement_allocations',
        'period_close_readiness_snapshots',
        'approval_decisions',
        'bank_statement_imports',
        'bank_statement_lines',
        'bank_reconciliation_match_events',
        'bank_reconciliation_exception_events',
        'financial_report_definitions',
        'e_invoice_documents',
        'apar_subledger_gl_reconciliation_runs',
        'apar_subledger_gl_reconciliation_exceptions',
        'accounting_document_dimension_assignments',
        'period_close_signoff_packages',
        'period_close_signoff_events',
        'statutory_financial_statement_definitions',
        'bank_gl_reconciliation_runs',
        'bank_gl_reconciliation_exceptions',
        'fixed_asset_gl_reconciliation_runs',
        'fixed_asset_gl_reconciliation_exceptions',
        'inventory_subledger_gl_reconciliation_runs',
        'inventory_subledger_gl_reconciliation_exceptions',
        'e_invoice_provider_dispatch_events',
        'audit_logs',
    ];

    public function test_every_controlled_evidence_table_has_update_and_delete_triggers(): void
    {
        $driver = DB::connection()->getDriverName();
        $this->assertContains(
            $driver,
            ['sqlite', 'mysql', 'mariadb'],
            "Trigger inventory is not implemented for database driver [{$driver}]."
        );

        $missing = [];
        $overlongTriggerNames = [];
        foreach (self::CONTROLLED_EVIDENCE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missing[$table] = ['table missing'];
                continue;
            }

            $operations = $this->installedTriggerOperations($driver, $table);
            $missingOperations = array_values(array_diff(['UPDATE', 'DELETE'], $operations));
            if ($missingOperations !== []) {
                $missing[$table] = $missingOperations;
            }

            foreach ($this->installedTriggerNames($driver, $table) as $triggerName) {
                if (strlen($triggerName) > 64) {
                    $overlongTriggerNames[$table][] = $triggerName;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Required BEFORE UPDATE/BEFORE DELETE trigger coverage is missing: '.json_encode($missing, JSON_THROW_ON_ERROR)
        );
        $this->assertSame(
            [],
            $overlongTriggerNames,
            'Trigger identifiers must fit MySQL/MariaDB\'s 64-character limit: '.json_encode($overlongTriggerNames, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return list<string>
     */
    private function installedTriggerOperations(string $driver, string $table): array
    {
        $physicalTable = DB::connection()->getTablePrefix().$table;

        if ($driver === 'sqlite') {
            $rows = DB::select(
                "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?",
                [$physicalTable]
            );

            $operations = [];
            foreach ($rows as $row) {
                $definition = strtoupper((string) ($row->sql ?? ''));
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    if (preg_match('/\\bBEFORE\\s+'.preg_quote($operation, '/').'\\b/', $definition) === 1) {
                        $operations[] = $operation;
                    }
                }
            }

            return array_values(array_unique($operations));
        }

        $rows = DB::select(
            'SELECT EVENT_MANIPULATION, ACTION_TIMING '
            .'FROM information_schema.TRIGGERS '
            .'WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
            [$physicalTable]
        );

        $operations = [];
        foreach ($rows as $row) {
            if (strtoupper((string) ($row->ACTION_TIMING ?? '')) !== 'BEFORE') {
                continue;
            }

            $operation = strtoupper((string) ($row->EVENT_MANIPULATION ?? ''));
            if (in_array($operation, ['UPDATE', 'DELETE'], true)) {
                $operations[] = $operation;
            }
        }

        return array_values(array_unique($operations));
    }

    /**
     * @return list<string>
     */
    private function installedTriggerNames(string $driver, string $table): array
    {
        $physicalTable = DB::connection()->getTablePrefix().$table;

        if ($driver === 'sqlite') {
            return array_values(array_map(
                static fn (object $row): string => (string) ($row->name ?? ''),
                DB::select(
                    "SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?",
                    [$physicalTable]
                )
            ));
        }

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->TRIGGER_NAME ?? ''),
            DB::select(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
                .'WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
                [$physicalTable]
            )
        ));
    }
}
