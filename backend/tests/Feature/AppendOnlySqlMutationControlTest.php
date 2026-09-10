<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Exercises both direct SQL mutation paths for every DB-trigger evidence
 * control in AppendOnlyTriggerInventoryTest. This deliberately avoids model
 * observers and accounting services: a writer with query-builder access must
 * still be rejected by the database trigger.
 */
class AppendOnlySqlMutationControlTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private array $fixtureIds = [];

    /** @var array<string, true> */
    private array $resolving = [];

    private const CONTROLLED_EVIDENCE_TABLES = [
        'reconciliation_runs', 'reconciliation_check_results', 'report_runs',
        'management_report_definitions', 'settlement_allocations', 'period_close_readiness_snapshots',
        'approval_decisions', 'bank_statement_imports', 'bank_statement_lines',
        'bank_reconciliation_match_events', 'bank_reconciliation_exception_events', 'financial_report_definitions',
        'e_invoice_documents', 'apar_subledger_gl_reconciliation_runs', 'apar_subledger_gl_reconciliation_exceptions',
        'accounting_document_dimension_assignments', 'period_close_signoff_packages', 'period_close_signoff_events',
        'statutory_financial_statement_definitions', 'bank_gl_reconciliation_runs', 'bank_gl_reconciliation_exceptions',
        'fixed_asset_gl_reconciliation_runs', 'fixed_asset_gl_reconciliation_exceptions',
        'inventory_subledger_gl_reconciliation_runs', 'inventory_subledger_gl_reconciliation_exceptions',
        'e_invoice_provider_dispatch_events', 'audit_logs',
    ];

    /**
     * Definition tables are transition-immutable rather than append-only from
     * their first draft. Their fixture represents the protected lifecycle
     * state that the production trigger claims to guard.
     */
    private const PROTECTED_STATE_OVERRIDES = [
        'management_report_definitions' => ['status' => 'published', 'published_at' => '2026-08-23 00:00:00'],
        'settlement_allocations' => ['status' => 'posted'],
        'financial_report_definitions' => ['status' => 'approved', 'approved_at' => '2026-08-23 00:00:00'],
        'statutory_financial_statement_definitions' => ['status' => 'approved', 'approved_at' => '2026-08-23 00:00:00'],
    ];

    private const PROTECTED_MUTATION_COLUMNS = [
        'management_report_definitions' => 'report_key',
        'financial_report_definitions' => 'report_key',
        'statutory_financial_statement_definitions' => 'form_key',
    ];

    private const CONSTRAINED_STRING_FIXTURES = [
        'decision' => 'approved',
    ];

    /**
     * MariaDB exposes JSON columns as LONGTEXT plus a JSON_VALID constraint,
     * so type metadata alone cannot identify them. Keep this bounded to the
     * evidence-table schema contract exercised by this test.
     */
    private const JSON_COLUMN_NAMES = [
        'amount_contract', 'bank_accounts', 'calculation_contract',
        'capability_contract', 'combo_details', 'comparative_contract',
        'custom_fields', 'delivery_addresses', 'dependents', 'evidence',
        'filters', 'form_contract', 'input_boundary', 'line_definitions',
        'line_mapping_contract', 'mapping_context', 'metadata', 'new_values',
        'notes_requirement_contract', 'old_values', 'package_snapshot',
        'payload_snapshot', 'period', 'policy_snapshot', 'posting_rule_contract',
        'presentation_contract', 'provenance_contract', 'referenced_vouchers',
        'regime', 'regulatory_dependencies', 'request_evidence',
        'required_dimensions', 'reviewer_roles', 'safe_metadata',
        'sign_rounding_contract', 'snapshot', 'source_completeness',
        'source_contract', 'source_metadata', 'source_payload', 'steps',
        'tier_discounts', 'unit_conversions',
    ];

    public function test_sql_cannot_update_or_delete_every_controlled_evidence_table(): void
    {
        foreach (self::CONTROLLED_EVIDENCE_TABLES as $table) {
            $id = $this->insertProtectedFixture($table);
            $this->assertAppendOnlyMutationRejected($table, $id);
        }
    }

    private function insertProtectedFixture(string $table): int
    {
        if (isset($this->fixtureIds[$table])) {
            return $this->fixtureIds[$table];
        }
        if (isset($this->resolving[$table])) {
            throw new \LogicException("Fixture foreign-key cycle encountered for {$table}.");
        }
        $this->resolving[$table] = true;

        $values = [];
        $columns = Schema::getColumns($table);
        $columnsByName = collect($columns)->keyBy('name');
        foreach ($columns as $column) {
            $name = (string) $column['name'];
            if ($name === 'id' || ($column['nullable'] ?? false) || ($column['default'] ?? null) !== null) {
                continue;
            }
            $values[$name] = $this->fixtureValue($name, (string) ($column['type_name'] ?? $column['type'] ?? ''));
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            foreach ($foreignKey['columns'] as $index => $columnName) {
                $column = $columnsByName->get($columnName);
                if ($column === null || ($column['nullable'] ?? false) || $foreignKey['foreign_table'] === $table) {
                    continue;
                }
                $values[$columnName] = $this->insertProtectedFixture($foreignKey['foreign_table']);
            }
        }

        $values = [...$values, ...(self::PROTECTED_STATE_OVERRIDES[$table] ?? [])];
        DB::table($table)->insert($values);
        unset($this->resolving[$table]);

        return $this->fixtureIds[$table] = (int) DB::table($table)->max('id');
    }

    private function fixtureValue(string $name, string $type): mixed
    {
        $type = strtolower($type);
        if (str_contains($name, 'hash')) return str_repeat('a', 64);
        if (array_key_exists($name, self::CONSTRAINED_STRING_FIXTURES)) return self::CONSTRAINED_STRING_FIXTURES[$name];
        if ($name === 'uuid' || str_ends_with($name, '_uuid')) return '00000000-0000-4000-8000-000000000001';
        if (str_contains($type, 'json') || in_array($name, self::JSON_COLUMN_NAMES, true)) return '{}';
        if (str_contains($type, 'date') && ! str_contains($type, 'time')) return '2026-08-23';
        if (str_contains($type, 'time')) return '2026-08-23 00:00:00';
        if (str_contains($type, 'bool')) return false;
        if (preg_match('/int|decimal|numeric|float|double|real/', $type) === 1 || str_ends_with($name, '_id')) return 1;

        return 'x';
    }

    private function assertAppendOnlyMutationRejected(string $table, int $id): void
    {
        foreach (['update', 'delete'] as $operation) {
            $rejected = false;
            try {
                if ($operation === 'update') {
                    $column = self::PROTECTED_MUTATION_COLUMNS[$table] ?? 'id';
                    DB::table($table)->where('id', $id)->update([
                        $column => $column === 'id' ? $id : 'tamper-'.$id,
                    ]);
                } else {
                    DB::table($table)->where('id', $id)->delete();
                }
            } catch (\Throwable $exception) {
                $rejected = true;
                $this->assertMatchesRegularExpression('/append-only|immutable/i', $exception->getMessage());
            }

            $this->assertTrue($rejected, "{$operation} unexpectedly succeeded for controlled evidence table {$table}.");
            $this->assertDatabaseHas($table, ['id' => $id]);
        }
    }
}
