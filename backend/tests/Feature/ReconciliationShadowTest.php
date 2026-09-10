<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Period;
use App\Models\ReconciliationRun;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ReconciliationShadowService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReconciliationShadowTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private FiscalYear $fiscalYearA;

    private FiscalYear $fiscalYearB;

    private Period $periodA;

    private Period $periodB;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['gl.reconciliations.view', 'gl.reconciliations.execute'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->companyA = Company::findOrFail(1);
        $this->companyB = Company::create([
            'name' => 'Shadow Tenant B',
            'tax_code' => 'SHADOW-B',
            'address' => 'B',
        ]);
        $this->fiscalYearA = FiscalYear::findOrFail(1);
        $this->fiscalYearB = FiscalYear::create([
            'company_id' => $this->companyB->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $this->periodA = Period::create([
            'fiscal_year_id' => $this->fiscalYearA->id,
            'period' => 1,
            'period_number' => 1,
            'name' => 'January 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
        ]);
        $this->periodB = Period::create([
            'fiscal_year_id' => $this->fiscalYearB->id,
            'period' => 1,
            'period_number' => 1,
            'name' => 'January 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
        ]);

        foreach ([$this->companyA, $this->companyB] as $company) {
            foreach ([
                ['code' => '1111', 'is_parent' => false, 'is_active' => true],
                ['code' => '511', 'is_parent' => false, 'is_active' => true],
            ] as $account) {
                ChartOfAccount::create([
                    'company_id' => $company->id,
                    'code' => $account['code'],
                    'name' => $account['code'],
                    'type' => $account['code'] === '511' ? 'revenue' : 'asset',
                    'nature' => $account['code'] === '511' ? 'credit' : 'debit',
                    'level' => 1,
                    'is_parent' => $account['is_parent'],
                    'is_active' => $account['is_active'],
                ]);
            }
        }
    }

    public function test_routes_require_authentication_and_granular_permissions(): void
    {
        $unauthenticated = $this->getJson('/api/v1/gl/reconciliations')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Unauthenticated.');
        $this->assertCorrelatedSafeError($unauthenticated);
        $this->assertCorrelatedSafeError(
            $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id])
                ->assertUnauthorized()
                ->assertJsonPath('error', 'Unauthenticated.')
        );

        $this->actingAsTenant($this->companyA);
        $this->assertCorrelatedSafeError(
            $this->getJson('/api/v1/gl/reconciliations')
                ->assertForbidden()
                ->assertJsonPath('error', 'This action is unauthorized.')
        );
        $this->assertCorrelatedSafeError(
            $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], ['Idempotency-Key' => 'rbac-1'])
                ->assertForbidden()
                ->assertJsonPath('error', 'This action is unauthorized.')
        );

        $this->actingAsTenant($this->companyA, ['gl.reconciliations.view']);
        $this->getJson('/api/v1/gl/reconciliations')->assertOk();
        $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], ['Idempotency-Key' => 'rbac-2'])
            ->assertForbidden();

        $this->actingAsTenant($this->companyA, ['gl.reconciliations.execute']);
        $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], ['Idempotency-Key' => 'rbac-3'])
            ->assertCreated();
        $this->getJson('/api/v1/gl/reconciliations')->assertForbidden();
    }

    public function test_close_readiness_is_tenant_scoped_read_only_and_never_claims_close_eligibility(): void
    {
        $unauthenticated = $this->getJson("/api/v1/gl/periods/{$this->periodA->id}/close-readiness")
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Unauthenticated.');
        $this->assertCorrelatedSafeError($unauthenticated);

        $this->actingAsTenant($this->companyA);
        $forbidden = $this->getJson("/api/v1/gl/periods/{$this->periodA->id}/close-readiness")
            ->assertForbidden()
            ->assertJsonPath('error', 'This action is unauthorized.');
        $this->assertCorrelatedSafeError($forbidden);

        $this->actingAsTenant($this->companyA, ['gl.reconciliations.view', 'gl.reconciliations.execute']);
        $initial = $this->getJson("/api/v1/gl/periods/{$this->periodA->id}/close-readiness")
            ->assertOk()
            ->assertJsonPath('data.schema', 'period-close-readiness.v1')
            ->assertJsonPath('data.mode', 'informational_only')
            ->assertJsonPath('data.close_gate.enforcement', 'off')
            ->assertJsonPath('data.close_gate.status', 'not_evaluated')
            ->assertJsonPath('data.close_gate.close_permitted_by_this_endpoint', false)
            ->assertJsonPath('data.reconciliation_shadow.run_available', false)
            ->assertJsonPath('data.reconciliation_shadow.latest_run', null);

        $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'close-readiness-run',
        ])->assertCreated();

        $this->getJson("/api/v1/gl/periods/{$this->periodA->id}/close-readiness")
            ->assertOk()
            ->assertJsonPath('data.close_gate.close_permitted_by_this_endpoint', false)
            ->assertJsonPath('data.reconciliation_shadow.run_available', true)
            ->assertJsonPath('data.reconciliation_shadow.latest_run.status', 'completed')
            ->assertJsonPath('data.reconciliation_shadow.latest_run.failed_result_count', 0);

        $this->actingAsTenant($this->companyB, ['gl.reconciliations.view']);
        $foreign = $this->getJson("/api/v1/gl/periods/{$this->periodA->id}/close-readiness")
            ->assertNotFound()
            ->assertJsonPath('error', 'Resource not found.');
        $this->assertCorrelatedSafeError($foreign);
    }

    public function test_accountant_can_record_a_server_readiness_snapshot_without_receiving_close_authority(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.view', 'gl.reconciliations.execute']);

        $response = $this->postJson("/api/v1/gl/periods/{$this->periodA->id}/close-readiness/evaluate")
            ->assertCreated()
            ->assertJsonPath('data.period_id', $this->periodA->id)
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.eligible_to_close', false)
            ->assertJsonPath('data.snapshot.schema', 'period-close-readiness.v1');

        $snapshotId = $response->json('data.id');
        $this->assertDatabaseHas('period_close_readiness_snapshots', [
            'id' => $snapshotId,
            'company_id' => $this->companyA->id,
            'period_id' => $this->periodA->id,
            'eligible_to_close' => false,
        ]);

        $this->getJson("/api/v1/gl/periods/{$this->periodA->id}/close-readiness")
            ->assertOk()
            ->assertJsonPath('data.latest_evaluation.id', $snapshotId)
            ->assertJsonPath('data.latest_evaluation.eligible_to_close', false);

        // The evaluation route is evidence only; the existing lifecycle
        // mutation remains protected by the admin-only close permission.
        $this->postJson('/api/v1/gl/periods/close', ['period_id' => $this->periodA->id])
            ->assertForbidden();
    }

    public function test_balanced_run_is_immutable_hashed_audited_and_idempotent(): void
    {
        $user = $this->actingAsTenant($this->companyA, ['gl.reconciliations.view', 'gl.reconciliations.execute']);
        $this->createEntry($this->companyA, $this->fiscalYearA, 'JE-OK', [
            ['account_code' => '1111', 'debit_amount' => 125000, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 125000],
        ]);

        $first = $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'balanced-immutable-1',
            'X-Request-ID' => 'shadow-correlation-1',
        ])->assertCreated()
            ->assertJsonPath('meta.enforcement', 'off')
            ->assertJsonPath('meta.replayed', false)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.failed_result_count', 0)
            ->assertJsonPath('data.posted_entry_count', 1);

        $uuid = $first->json('data.uuid');
        $hash = $first->json('data.snapshot_hash');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);

        $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'balanced-immutable-1',
        ])->assertOk()
            ->assertJsonPath('meta.replayed', true)
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('data.snapshot_hash', $hash);

        $this->assertDatabaseCount('reconciliation_runs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->companyA->id,
            'user_id' => $user->id,
            'action' => 'reconciliation.shadow.completed',
            'correlation_id' => 'shadow-correlation-1',
        ]);
        $this->getJson("/api/v1/gl/reconciliations/{$uuid}/results")
            ->assertOk()
            ->assertJsonPath('meta.snapshot_hash', $hash)
            ->assertJsonPath('meta.enforcement', 'off');

        $run = ReconciliationRun::firstOrFail();
        $this->expectException(LogicException::class);
        $run->update(['status' => 'failed']);
    }

    public function test_database_triggers_reject_query_builder_and_raw_mutations_after_normal_execution(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.execute']);

        $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'database-append-only',
        ])->assertCreated();

        $runId = (int) DB::table('reconciliation_runs')->value('id');
        $resultId = (int) DB::table('reconciliation_check_results')->value('id');
        $this->assertGreaterThan(0, $runId);
        $this->assertGreaterThan(0, $resultId);

        $this->assertDatabaseMutationRejected(
            fn () => DB::table('reconciliation_runs')->where('id', $runId)->update(['status' => 'tampered'])
        );
        $this->assertDatabaseMutationRejected(
            fn () => DB::delete('DELETE FROM reconciliation_runs WHERE id = ?', [$runId])
        );
        $this->assertDatabaseMutationRejected(
            fn () => DB::table('reconciliation_check_results')->where('id', $resultId)->update(['status' => 'tampered'])
        );
        $this->assertDatabaseMutationRejected(
            fn () => DB::delete('DELETE FROM reconciliation_check_results WHERE id = ?', [$resultId])
        );

        $this->assertDatabaseHas('reconciliation_runs', ['id' => $runId, 'status' => 'completed']);
        $this->assertDatabaseHas('reconciliation_check_results', ['id' => $resultId]);
    }

    public function test_append_only_migration_can_be_removed_and_reinstalled_safely(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.execute']);
        $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'database-trigger-smoke',
        ])->assertCreated();

        $runId = (int) DB::table('reconciliation_runs')->value('id');
        $migration = require database_path('migrations/2026_08_21_210000_enforce_reconciliation_append_only_at_database_level.php');
        $reinstalled = false;

        $migration->down();

        try {
            $this->assertSame(
                1,
                DB::table('reconciliation_runs')->where('id', $runId)->update(['status' => 'rollback_smoke'])
            );

            $migration->up();
            $reinstalled = true;
            $migration->up(); // Deployment retry is safe after partial/non-transactional DDL.

            $this->assertDatabaseMutationRejected(
                fn () => DB::table('reconciliation_runs')->where('id', $runId)->delete()
            );
        } finally {
            if (! $reinstalled) {
                $migration->up();
            }
        }
    }

    public function test_idempotency_key_conflicts_when_normalized_request_differs(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.execute']);
        $periodTwo = Period::create([
            'fiscal_year_id' => $this->fiscalYearA->id,
            'period' => 2,
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'status' => 'open',
        ]);

        $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], ['Idempotency-Key' => 'same-key'])
            ->assertCreated();
        $conflict = $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $periodTwo->id], ['Idempotency-Key' => 'same-key'])
            ->assertConflict()
            ->assertJsonPath('error', 'The Idempotency-Key was already used for a different request.');
        $this->assertCorrelatedSafeError($conflict);
        $this->assertDatabaseCount('reconciliation_runs', 1);
    }

    public function test_run_rejects_client_computed_control_inputs_and_requires_idempotency_key(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.execute']);

        $missing = $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'The given data was invalid.')
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertCorrelatedSafeError($missing);
        $invalidKey = $this->postJson('/api/v1/gl/reconciliations', [
            'period_id' => $this->periodA->id,
        ], ['Idempotency-Key' => 'contains spaces'])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'The given data was invalid.')
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertCorrelatedSafeError($invalidKey);
        $clientComputed = $this->postJson('/api/v1/gl/reconciliations', [
            'period_id' => $this->periodA->id,
            'status' => 'completed',
            'results' => [['status' => 'pass']],
            'enforcement' => 'block_close',
        ], ['Idempotency-Key' => 'client-computed'])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'The given data was invalid.')
            ->assertJsonValidationErrors(['status', 'results', 'enforcement']);
        $this->assertCorrelatedSafeError($clientComputed);
        $this->assertDatabaseCount('reconciliation_runs', 0);
    }

    public function test_large_legal_amounts_use_exact_decimal_arithmetic_without_integer_overflow(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.view', 'gl.reconciliations.execute']);
        // Maximum DECIMAL(18,2) has 16 integer digits. Keep each persisted
        // amount schema-legal while making the aggregate exceed IEEE-754's
        // safe integer range.
        $large = 9000000000000000;
        $this->createEntry($this->companyA, $this->fiscalYearA, 'JE-LARGE-1', [
            ['account_code' => '1111', 'debit_amount' => $large, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => $large],
        ]);
        $this->createEntry($this->companyA, $this->fiscalYearA, 'JE-LARGE-2', [
            ['account_code' => '1111', 'debit_amount' => $large, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => $large],
        ]);

        $response = $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'large-exact-decimal',
        ])->assertCreated()
            ->assertJsonPath('data.total_debit', '18000000000000000.00')
            ->assertJsonPath('data.total_credit', '18000000000000000.00');
        $results = $this->getJson('/api/v1/gl/reconciliations/'.$response->json('data.uuid').'/results')->json('data');
        $aggregate = collect($results)->firstWhere('check_code', 'GL.AGGREGATE_BALANCE');
        $this->assertSame('pass', $aggregate['status']);
        $this->assertSame('0.00', $aggregate['difference']);
    }

    public function test_tenant_isolation_applies_to_execute_show_results_and_client_company_is_ignored(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.view', 'gl.reconciliations.execute']);
        $response = $this->postJson('/api/v1/gl/reconciliations', [
            'period_id' => $this->periodA->id,
            'company_id' => $this->companyB->id,
        ], ['Idempotency-Key' => 'tenant-a'])->assertCreated();
        $uuid = $response->json('data.uuid');
        $this->assertDatabaseHas('reconciliation_runs', ['company_id' => $this->companyA->id, 'uuid' => $uuid]);

        $this->actingAsTenant($this->companyB, ['gl.reconciliations.view', 'gl.reconciliations.execute']);
        $this->assertCorrelatedSafeError(
            $this->getJson("/api/v1/gl/reconciliations/{$uuid}")
                ->assertNotFound()
                ->assertJsonPath('error', 'Resource not found.')
        );
        $this->assertCorrelatedSafeError(
            $this->getJson("/api/v1/gl/reconciliations/{$uuid}/results")
                ->assertNotFound()
                ->assertJsonPath('error', 'Resource not found.')
        );
        $foreignPeriod = $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], ['Idempotency-Key' => 'foreign-period'])
            ->assertNotFound()
            ->assertJsonPath('error', 'Resource not found.');
        $this->assertCorrelatedSafeError($foreignPeriod);
        $this->getJson('/api/v1/gl/reconciliations')->assertJsonCount(0, 'data');
    }

    public function test_adversarial_ledger_and_source_defects_are_snapshotted_without_enforcement(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.view', 'gl.reconciliations.execute']);
        ChartOfAccount::create([
            'company_id' => $this->companyA->id,
            'code' => '1000', 'name' => 'Parent', 'type' => 'asset', 'nature' => 'debit',
            'level' => 1, 'is_parent' => true, 'is_active' => true,
        ]);
        ChartOfAccount::create([
            'company_id' => $this->companyA->id,
            'code' => '9999', 'name' => 'Inactive', 'type' => 'asset', 'nature' => 'debit',
            'level' => 1, 'is_parent' => false, 'is_active' => false,
        ]);

        $defect = $this->createEntry($this->companyA, $this->fiscalYearA, 'JE-DEFECT', [
            ['account_code' => '1000', 'debit_amount' => 10, 'credit_amount' => 10],
            ['account_code' => '9999', 'debit_amount' => 0, 'credit_amount' => 0],
            ['account_code' => 'MISSING', 'debit_amount' => 10, 'credit_amount' => 0],
        ]);
        $empty = $this->createEntry($this->companyA, $this->fiscalYearA, 'JE-EMPTY', []);
        $empty->delete();

        $foreignFiscalYear = FiscalYear::create([
            'company_id' => $this->companyA->id,
            'year' => 2025,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'open',
        ]);
        $this->createEntry($this->companyA, $foreignFiscalYear, 'JE-WRONG-FY', [
            ['account_code' => '1111', 'debit_amount' => 1, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 1],
        ]);
        $outside = $this->createEntry($this->companyA, $this->fiscalYearA, 'JE-OUTSIDE-FY', [], '2027-01-01');

        foreach ([['JE-DUP-1', '2026-01-20'], ['JE-DUP-2', '2026-02-20']] as [$number, $date]) {
            $entry = $this->createEntry($this->companyA, $this->fiscalYearA, $number, [
                ['account_code' => '1111', 'debit_amount' => 1, 'credit_amount' => 0],
                ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 1],
            ], $date);
            DB::table('journal_entries')->where('id', $entry->id)->update([
                'source_document_type' => CashReceipt::class,
                'source_document_id' => 999999,
            ]);
        }
        $unknown = $this->createEntry($this->companyA, $this->fiscalYearA, 'JE-UNKNOWN', [
            ['account_code' => '1111', 'debit_amount' => 1, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 1],
        ]);
        DB::table('journal_entries')->where('id', $unknown->id)->update([
            'source_document_type' => 'Legacy\\UnknownVoucher',
            'source_document_id' => 42,
        ]);

        $response = $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'adversarial-snapshot',
        ])->assertCreated()
            ->assertJsonPath('meta.enforcement', 'off');
        $uuid = $response->json('data.uuid');
        $results = $this->getJson("/api/v1/gl/reconciliations/{$uuid}/results")->assertOk()->json('data');
        $byCode = collect($results)->keyBy('check_code');

        $this->assertSame('fail', $byCode['GL.ENTRY_BALANCE_AND_PRESENCE']['status']);
        $this->assertContains($empty->id, $byCode['GL.ENTRY_BALANCE_AND_PRESENCE']['evidence']['empty_entry_ids']);
        $this->assertSame('fail', $byCode['GL.LINE_SIDE_VALIDITY']['status']);
        $this->assertSame('fail', $byCode['GL.ACCOUNT_VALIDITY']['status']);
        $this->assertSame('fail', $byCode['GL.PERIOD_FISCAL_INTEGRITY']['status']);
        $this->assertContains($outside->id, $byCode['GL.PERIOD_FISCAL_INTEGRITY']['evidence']['outside_fiscal_year_entry_ids']);
        $this->assertSame('fail', $byCode['GL.SOFT_DELETED_POSTED']['status']);
        $this->assertSame('fail', $byCode['SOURCE.DUPLICATE_JE_LINK']['status']);
        $this->assertSame('fail', $byCode['SOURCE.ORPHAN_JE_LINK']['status']);
        $this->assertSame('not_available', $byCode['SOURCE.UNSUPPORTED_MODELS']['status']);
        $this->assertContains('Legacy\\UnknownVoucher', $byCode['SOURCE.UNSUPPORTED_MODELS']['evidence']['unsupported_source_types']);
        $this->assertSame('completed', $response->json('data.status'));
        $this->assertGreaterThan(0, $response->json('data.failed_result_count'));
    }

    public function test_known_source_header_mismatch_is_detected_in_both_directions(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.view', 'gl.reconciliations.execute']);
        $entry = $this->createEntry($this->companyA, $this->fiscalYearA, 'JE-HEADER', [
            ['account_code' => '1111', 'debit_amount' => 10, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 10],
        ]);
        $sourceId = DB::table('cash_receipts')->insertGetId([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'PT-SHADOW-1',
            'voucher_date' => '2026-01-10',
            'posting_date' => '2026-01-10',
            'total_amount' => 10,
            'status' => 'posted',
            'is_posted' => true,
            'journal_entry_id' => $entry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $outsideEntry = $this->createEntry($this->companyA, $this->fiscalYearA, 'JE-HEADER-OUTSIDE', [
            ['account_code' => '1111', 'debit_amount' => 5, 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 5],
        ], '2026-02-10');
        DB::table('cash_receipts')->insert([
            'company_id' => $this->companyA->id,
            'voucher_number' => 'PT-SHADOW-OUTSIDE-JE',
            'voucher_date' => '2026-01-11',
            'posting_date' => '2026-01-11',
            'total_amount' => 5,
            'status' => 'posted',
            'is_posted' => true,
            'journal_entry_id' => $outsideEntry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_entries')->where('id', $entry->id)->update([
            'source_document_type' => CashReceipt::class,
            'source_document_id' => $sourceId + 1000,
        ]);

        $response = $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'header-mismatch',
        ])->assertCreated();
        $results = $this->getJson('/api/v1/gl/reconciliations/'.$response->json('data.uuid').'/results')->json('data');
        $result = collect($results)->firstWhere('check_code', 'SOURCE.HEADER_JE_MISMATCH');

        $this->assertSame('fail', $result['status']);
        $this->assertNotEmpty($result['evidence']['mismatches']);
    }

    public function test_audit_failure_rolls_back_run_and_results_atomically(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.execute']);
        $this->app->instance(AuditService::class, new class extends AuditService
        {
            public function record(
                Model $model,
                string $action,
                array $before = [],
                array $after = [],
                ?string $correlationId = null,
                array $metadata = []
            ): AuditLog {
                throw new RuntimeException('Injected reconciliation audit failure');
            }
        });

        $response = $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'audit-failure',
        ])->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.');
        $this->assertCorrelatedSafeError($response);
        $this->assertStringNotContainsString('Injected reconciliation audit failure', $response->getContent());
        $this->assertDatabaseCount('reconciliation_runs', 0);
        $this->assertDatabaseCount('reconciliation_check_results', 0);
    }

    public function test_injected_service_failure_never_leaks_sql_class_path_or_secret(): void
    {
        $this->actingAsTenant($this->companyA, ['gl.reconciliations.execute']);
        $service = Mockery::mock(ReconciliationShadowService::class);
        $service->shouldReceive('execute')->once()->andThrow(new RuntimeException(
            'SQLSTATE[HY000] secret=reconciliation-sentinel at C:\\private\\Reconcile.php:91'
        ));
        $this->app->instance(ReconciliationShadowService::class, $service);

        $response = $this->postJson('/api/v1/gl/reconciliations', ['period_id' => $this->periodA->id], [
            'Idempotency-Key' => 'internal-failure',
        ])->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.');

        $this->assertCorrelatedSafeError($response);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('reconciliation-sentinel', $response->getContent());
        $this->assertStringNotContainsString('Reconcile.php', $response->getContent());
        $this->assertStringNotContainsString(RuntimeException::class, $response->getContent());
    }

    private function assertCorrelatedSafeError(TestResponse $response): void
    {
        $requestId = $response->json('request_id');
        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));
    }

    /** @param list<string> $permissions */
    private function actingAsTenant(Company $company, array $permissions = []): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param list<array{account_code: string, debit_amount: int, credit_amount: int}> $lines */
    private function createEntry(
        Company $company,
        FiscalYear $fiscalYear,
        string $number,
        array $lines,
        string $postingDate = '2026-01-15'
    ): JournalEntry {
        $entry = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $number,
            'voucher_date' => $postingDate,
            'posting_date' => $postingDate,
            'description' => $number,
            'total_amount' => collect($lines)->sum('debit_amount'),
            'status' => 'posted',
        ]);
        foreach ($lines as $line) {
            JournalEntryLine::create(['journal_entry_id' => $entry->id, ...$line]);
        }

        return $entry;
    }

    private function assertDatabaseMutationRejected(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('The database accepted a mutation of append-only reconciliation evidence.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }
}
