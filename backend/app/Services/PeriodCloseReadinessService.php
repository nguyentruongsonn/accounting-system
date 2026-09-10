<?php

namespace App\Services;

use App\Models\Period;
use App\Models\PeriodCloseReadinessSnapshot;
use App\Models\ReconciliationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A conservative foundation for a close workbench.
 *
 * Shadow reconciliation is intentionally never close authority.  A future
 * controlled reconciliation producer may issue a `controlled` /
 * `block_close` run; even then it must have no failed or unavailable source
 * checks before this service can issue readiness evidence. The final
 * PeriodClosingService still requires independent immutable signoff.
 */
class PeriodCloseReadinessService
{
    public const SCHEMA = 'period-close-readiness.v1';

    public function __construct(
        private readonly ControlledPeriodCloseReconciliationAggregator $controlledReconciliation,
        private readonly InventoryValuationCloseReadinessService $inventoryValuationReadiness,
    ) {}

    public function evaluate(int $companyId, int $periodId, ?int $actorId = null): PeriodCloseReadinessSnapshot
    {
        // Controllers normally resolve TenantContext, but this service is also
        // callable directly. Preserve trusted unauthenticated workers while
        // rejecting an authenticated actor's cross-company readiness read.
        $companyId = $this->requireCompanyId($companyId);

        return DB::transaction(function () use ($companyId, $periodId, $actorId): PeriodCloseReadinessSnapshot {
            $period = Period::query()
                ->with('fiscalYear')
                ->whereKey($periodId)
                ->whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
                ->lockForUpdate()
                ->first();
            if ($period === null) {
                throw new NotFoundHttpException('Accounting period was not found for the authenticated company.');
            }

            $run = ReconciliationRun::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('period_id', $period->id)
                ->where('status', 'completed')
                ->latest('id')
                ->first();

            $snapshot = $this->buildSnapshot(
                $companyId,
                $period,
                $run,
                $this->controlledReconciliation->evaluate($companyId, $period->end_date->toDateString()),
                $this->inventoryValuationReadiness->evaluate(
                    $companyId,
                    $period->start_date->toDateString(),
                    $period->end_date->toDateString(),
                ),
            );
            $eligible = (bool) data_get($snapshot, 'eligible_to_close', false);

            return PeriodCloseReadinessSnapshot::withoutGlobalScope('company')->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'period_id' => $period->id,
                'reconciliation_run_id' => $run?->id,
                'status' => $eligible ? 'ready' : 'blocked',
                'eligible_to_close' => $eligible,
                'schema_version' => self::SCHEMA,
                'snapshot_hash' => $this->hash($snapshot),
                'snapshot' => $snapshot,
                'requested_by' => $actorId,
                'evaluated_at' => now(),
            ]);
        });
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }

    /** @return array<string, mixed> */
    private function buildSnapshot(
        int $companyId,
        Period $period,
        ?ReconciliationRun $run,
        array $controlledReconciliation,
        array $inventoryValuation,
    ): array {
        $hasCompletedRun = $run !== null;
        $enforcement = $hasCompletedRun ? data_get($run->snapshot, 'enforcement') : null;
        $controlledEnforcement = $hasCompletedRun
            && $run->basis === 'controlled'
            && $enforcement === 'block_close';
        $noBlockingResults = $hasCompletedRun
            && (int) $run->failed_result_count === 0
            && (int) $run->not_available_result_count === 0
            && (int) $run->result_count > 0;
        // Generic reconciliation evidence cannot be used to skip the named
        // AP/AR, bank, inventory and fixed-asset domains.  The aggregator is
        // read-only and fail-closed; final sign-off remains independent.
        $namedDomainsControlled = (bool) data_get($controlledReconciliation, 'eligible_to_close', false);
        $inventoryValuationCurrent = (bool) ($inventoryValuation['eligible'] ?? false);
        $eligible = $controlledEnforcement && $noBlockingResults && $namedDomainsControlled && $inventoryValuationCurrent;

        return [
            'schema' => self::SCHEMA,
            'company_id' => $companyId,
            'period' => [
                'id' => $period->id,
                'start_date' => $period->start_date?->toDateString(),
                'end_date' => $period->end_date?->toDateString(),
                'status' => $period->status,
                'is_closed' => (bool) $period->is_closed,
            ],
            'eligible_to_close' => $eligible,
            'status' => $eligible ? 'ready' : 'blocked',
            'checks' => [
                [
                    'code' => 'RECONCILIATION.COMPLETED_EVIDENCE',
                    'status' => $hasCompletedRun ? 'pass' : 'fail',
                    'details' => $hasCompletedRun
                        ? ['reconciliation_run_id' => $run->id, 'basis' => $run->basis]
                        : ['reason' => 'No completed reconciliation evidence exists for this period.'],
                ],
                [
                    'code' => 'RECONCILIATION.RESULTS_WITHOUT_BLOCKERS',
                    'status' => $noBlockingResults ? 'pass' : 'fail',
                    'details' => $hasCompletedRun
                        ? ['failed_result_count' => $run->failed_result_count, 'not_available_result_count' => $run->not_available_result_count]
                        : ['reason' => 'Cannot evaluate results without a completed reconciliation run.'],
                ],
                [
                    'code' => 'RECONCILIATION.ENFORCED_CLOSE_GATE',
                    'status' => $controlledEnforcement ? 'pass' : 'fail',
                    'details' => [
                        'observed_enforcement' => $enforcement,
                        'basis' => $run?->basis,
                        'reason' => $controlledEnforcement
                            ? 'A controlled reconciliation run declares block_close enforcement; closing still requires matching immutable signoff.'
                            : 'No owner-approved enforced reconciliation close gate is configured. Shadow reconciliation evidence is not a close authorization.',
                    ],
                ],
                [
                    'code' => 'RECONCILIATION.NAMED_DOMAINS_SAME_CUTOFF',
                    'status' => $namedDomainsControlled ? 'pass' : 'fail',
                    'details' => $controlledReconciliation,
                ],
                [
                    'code' => 'INVENTORY.VALUATION_CURRENT',
                    'status' => $inventoryValuationCurrent ? 'pass' : 'fail',
                    'details' => $inventoryValuation,
                ],
            ],
            'reconciliation_run' => $hasCompletedRun ? [
                'id' => $run->id,
                'uuid' => $run->uuid,
                'basis' => $run->basis,
                'status' => $run->status,
                'enforcement' => $enforcement,
                'snapshot_hash' => $run->snapshot_hash,
            ] : null,
        ];
    }

    private function hash(array $snapshot): string
    {
        // MySQL stores JSON objects in a canonical key order, while SQLite
        // returns the text in insertion order.  Hashing the raw PHP array
        // therefore made an identical snapshot validate on SQLite but fail on
        // MySQL.  Canonicalise object keys recursively before hashing so the
        // immutable evidence hash is database-driver independent.
        return hash('sha256', json_encode($this->canonicalise($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /** @return array<string|int, mixed> */
    private function canonicalise(array $value): array
    {
        $isList = array_is_list($value);
        if (! $isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalise($item);
            }
        }

        return $value;
    }
}
