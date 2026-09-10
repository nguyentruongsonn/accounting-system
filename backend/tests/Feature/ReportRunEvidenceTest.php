<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\ReportRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReportRunEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FiscalYear $fiscalYear;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user = User::factory()->create(['company_id' => 1]);
        $this->user->givePermissionTo('reports.view');
        Sanctum::actingAs($this->user);
        $this->fiscalYear = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $this->user->company_id)
            ->firstOrFail();
    }

    public function test_every_supported_report_creates_a_tenant_bound_immutable_logical_snapshot(): void
    {
        $query = http_build_query([
            'fiscal_year_id' => $this->fiscalYear->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
        ]);
        $requestId = 'b9ac0020-7953-4cc1-817e-c6156d8e2ad8';

        foreach ([
            '/api/v1/reports/general-journal?'.$query,
            '/api/v1/reports/general-ledger?account_code=111&'.$query,
            '/api/v1/reports/trial-balance?'.$query,
            '/api/v1/reports/balance-sheet?'.$query,
            '/api/v1/reports/income-statement?'.$query,
        ] as $url) {
            $this->withHeader('X-Request-ID', $requestId)->getJson($url)->assertOk();
        }

        $runs = ReportRun::withoutGlobalScope('company')->orderBy('id')->get();
        $this->assertCount(5, $runs);
        $this->assertSame([
            'general_journal', 'general_ledger', 'trial_balance', 'balance_sheet', 'income_statement',
        ], $runs->pluck('report')->all());

        foreach ($runs as $run) {
            $this->assertSame($this->user->company_id, $run->company_id);
            $this->assertSame($this->fiscalYear->id, $run->fiscal_year_id);
            $this->assertSame($this->user->id, $run->issued_by);
            $this->assertSame($requestId, $run->correlation_id);
            $this->assertSame('report-run.v1', $run->snapshot_schema);
            $this->assertSame('TT99', $run->regime['accounting_regime']);
            $this->assertSame('2026-01-01', $run->period['from_date']);
            $this->assertSame('2026-01-31', $run->period['to_date']);
            $this->assertSame('sha256:'.$run->output_hash, $run->output_identity);
            $this->assertSame('canonical-logical-json.v1', $run->snapshot['schema']);
            $this->assertTrue(Str::isUuid($run->uuid));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $run->control_hash);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $run->output_hash);
        }

        $audit = AuditLog::withoutGlobalScope('company')->where('action', 'report.trial_balance.view')->firstOrFail();
        $this->assertSame('report-run.v1', $audit->metadata['report_run']['snapshot_schema']);
        $trialBalanceRun = $runs->firstWhere('report', 'trial_balance');
        $this->assertEquals($trialBalanceRun->regime, $audit->metadata['regime']);
        $this->assertSame($trialBalanceRun->uuid, $audit->metadata['report_run']['uuid']);
        $this->assertSame($trialBalanceRun->control_hash, $audit->metadata['report_run']['control_hash']);
        $this->assertSame($trialBalanceRun->output_identity, $audit->metadata['report_run']['output_identity']);
    }

    public function test_same_context_and_logical_output_have_stable_hashes_but_distinct_issuance_evidence(): void
    {
        $url = '/api/v1/reports/trial-balance?'.http_build_query([
            'fiscal_year_id' => $this->fiscalYear->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
        ]);

        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertOk();
        $runs = ReportRun::withoutGlobalScope('company')->where('report', 'trial_balance')->orderBy('id')->get();

        $this->assertCount(2, $runs);
        $this->assertNotSame($runs[0]->uuid, $runs[1]->uuid);
        $this->assertSame($runs[0]->control_hash, $runs[1]->control_hash);
        $this->assertSame($runs[0]->output_hash, $runs[1]->output_hash);
    }

    public function test_report_run_records_are_immutable_in_model_and_database(): void
    {
        $this->getJson('/api/v1/reports/trial-balance?fiscal_year_id='.$this->fiscalYear->id)->assertOk();
        $run = ReportRun::withoutGlobalScope('company')->firstOrFail();

        $this->expectException(\LogicException::class);
        $run->update(['delivery' => 'tampered']);
    }

    public function test_database_guard_rejects_direct_report_run_mutation(): void
    {
        $this->getJson('/api/v1/reports/trial-balance?fiscal_year_id='.$this->fiscalYear->id)->assertOk();
        $run = ReportRun::withoutGlobalScope('company')->firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('report_runs')->where('id', $run->id)->update(['delivery' => 'tampered']);
    }

    public function test_report_run_persists_non_certifying_package_controls_and_exposes_tenant_bound_integrity_evidence(): void
    {
        $this->getJson('/api/v1/reports/trial-balance?fiscal_year_id='.$this->fiscalYear->id)->assertOk();
        $run = ReportRun::withoutGlobalScope('company')->firstOrFail();

        $this->assertSame('report-package-controls.v1', $run->snapshot['package_controls']['schema']);
        $this->assertTrue($run->snapshot['package_controls']['non_certifying']);
        $this->assertTrue($run->snapshot['package_controls']['not_a_close_gate']);

        $response = $this->getJson('/api/v1/reports/runs/'.$run->uuid.'/controls')
            ->assertOk()
            ->assertJsonPath('data.non_certifying', true)
            ->assertJsonPath('data.not_a_close_gate', true)
            ->assertJsonPath('data.snapshot_integrity.checks.0.status', 'pass')
            ->assertJsonPath('data.snapshot_integrity.checks.1.status', 'pass');

        $this->assertSame($run->uuid, $response->json('data.report_run_uuid'));
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->user->company_id,
            'action' => 'report.run.controls.view',
        ]);
    }

    public function test_report_run_control_evidence_cannot_cross_tenants(): void
    {
        $this->getJson('/api/v1/reports/trial-balance?fiscal_year_id='.$this->fiscalYear->id)->assertOk();
        $run = ReportRun::withoutGlobalScope('company')->firstOrFail();
        $otherCompany = Company::query()->create(['name' => 'Other report-evidence tenant']);
        $other = User::factory()->create(['company_id' => $otherCompany->id]);
        $other->givePermissionTo('reports.view');
        Sanctum::actingAs($other);

        $this->getJson('/api/v1/reports/runs/'.$run->uuid.'/controls')->assertNotFound();
    }
}
