<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\ReconciliationRun;
use App\Models\User;
use App\Services\PeriodCloseReadinessService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PeriodCloseReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::findOrFail(1);
        $fiscalYear = FiscalYear::where('company_id', $this->company->id)->firstOrFail();
        $this->period = Period::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => 1,
            'period_number' => 1,
            'name' => 'January 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
    }

    public function test_missing_reconciliation_evidence_is_recorded_as_fail_closed(): void
    {
        $snapshot = app(PeriodCloseReadinessService::class)->evaluate($this->company->id, $this->period->id);

        $this->assertSame('blocked', $snapshot->status);
        $this->assertFalse($snapshot->eligible_to_close);
        $this->assertSame('fail', $this->check($snapshot->snapshot, 'RECONCILIATION.COMPLETED_EVIDENCE')['status']);
        $this->assertSame('fail', $this->check($snapshot->snapshot, 'RECONCILIATION.ENFORCED_CLOSE_GATE')['status']);
        $this->assertDatabaseHas('period_close_readiness_snapshots', [
            'id' => $snapshot->id,
            'company_id' => $this->company->id,
            'period_id' => $this->period->id,
            'eligible_to_close' => false,
        ]);
    }

    public function test_direct_readiness_evaluation_rejects_an_authenticated_foreign_company(): void
    {
        $actor = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAs($actor);

        $this->expectException(ValidationException::class);
        app(PeriodCloseReadinessService::class)->evaluate($this->company->id + 1, $this->period->id);
    }

    public function test_clean_shadow_reconciliation_cannot_claim_close_readiness_when_enforcement_is_off(): void
    {
        $run = $this->shadowRun();
        $snapshot = app(PeriodCloseReadinessService::class)->evaluate($this->company->id, $this->period->id);

        $this->assertSame($run->id, $snapshot->reconciliation_run_id);
        $this->assertFalse($snapshot->eligible_to_close);
        $this->assertSame('pass', $this->check($snapshot->snapshot, 'RECONCILIATION.COMPLETED_EVIDENCE')['status']);
        $this->assertSame('pass', $this->check($snapshot->snapshot, 'RECONCILIATION.RESULTS_WITHOUT_BLOCKERS')['status']);
        $gate = $this->check($snapshot->snapshot, 'RECONCILIATION.ENFORCED_CLOSE_GATE');
        $this->assertSame('fail', $gate['status']);
        $this->assertSame('off', $gate['details']['observed_enforcement']);
    }

    public function test_generic_controlled_run_cannot_claim_readiness_without_named_same_cutoff_reconciliation_domains(): void
    {
        $run = $this->shadowRun('controlled', 'block_close');

        $snapshot = app(PeriodCloseReadinessService::class)->evaluate($this->company->id, $this->period->id);

        $this->assertFalse($snapshot->eligible_to_close);
        $this->assertSame('fail', $this->check($snapshot->snapshot, 'RECONCILIATION.NAMED_DOMAINS_SAME_CUTOFF')['status']);
    }

    public function test_readiness_evidence_is_append_only_in_model_layer(): void
    {
        $snapshot = app(PeriodCloseReadinessService::class)->evaluate($this->company->id, $this->period->id);

        $this->expectException(LogicException::class);
        $snapshot->update(['status' => 'ready']);
    }

    public function test_readiness_evidence_is_append_only_at_database_level(): void
    {
        $snapshot = app(PeriodCloseReadinessService::class)->evaluate($this->company->id, $this->period->id);

        try {
            DB::table('period_close_readiness_snapshots')->where('id', $snapshot->id)->update(['status' => 'ready']);
            $this->fail('The database accepted mutation of close-readiness evidence.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    private function shadowRun(string $basis = 'shadow', string $enforcement = 'off'): ReconciliationRun
    {
        return ReconciliationRun::withoutGlobalScope('company')->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'period_id' => $this->period->id,
            'basis' => $basis,
            'status' => 'completed',
            'idempotency_key' => 'close-readiness-shadow',
            'request_hash' => str_repeat('a', 64),
            'algorithm_version' => 'gl-shadow-integrity.v1',
            'input_cutoff_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
            'posted_entry_count' => 0,
            'posted_line_count' => 0,
            'total_debit' => '0.00',
            'total_credit' => '0.00',
            'result_count' => 1,
            'failed_result_count' => 0,
            'warning_result_count' => 0,
            'not_available_result_count' => 0,
            'snapshot_hash' => str_repeat('b', 64),
            'snapshot' => ['enforcement' => $enforcement],
        ]);
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function check(array $snapshot, string $code): array
    {
        foreach ($snapshot['checks'] as $check) {
            if ($check['code'] === $code) {
                return $check;
            }
        }

        $this->fail("Missing check [{$code}].");
    }
}
