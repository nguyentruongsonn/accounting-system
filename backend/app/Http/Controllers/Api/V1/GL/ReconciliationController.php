<?php

namespace App\Http\Controllers\Api\V1\GL;

use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Models\PeriodCloseReadinessSnapshot;
use App\Models\ReconciliationRun;
use App\Services\PeriodCloseReadinessService;
use App\Services\ReconciliationShadowService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationShadowService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate([
            'period_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $runs = ReconciliationRun::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->when(isset($validated['period_id']), fn ($query) => $query->where('period_id', $validated['period_id']))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        $runs->getCollection()->transform(fn (ReconciliationRun $run): array => $this->serializeRun($run));

        return response()->json($runs);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate([
            'period_id' => ['required', 'integer'],
            'basis' => ['prohibited'],
            'status' => ['prohibited'],
            'enforcement' => ['prohibited'],
            'snapshot_hash' => ['prohibited'],
            'total_debit' => ['prohibited'],
            'total_credit' => ['prohibited'],
            'results' => ['prohibited'],
            'checks' => ['prohibited'],
            'domains' => ['prohibited'],
            'policy_version_id' => ['prohibited'],
        ]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        validator(
            ['idempotency_key' => $idempotencyKey],
            ['idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/']]
        )->validate();

        $result = $this->service->execute(
            $companyId,
            (int) $validated['period_id'],
            $idempotencyKey,
            $request->user()?->id
        );

        return response()->json([
            'data' => $this->serializeRun($result['run']),
            'meta' => [
                'replayed' => $result['replayed'],
                'enforcement' => 'off',
            ],
        ], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $run = $this->service->findTenantRun(TenantContext::companyId($request), $uuid);

        return response()->json(['data' => $this->serializeRun($run)]);
    }

    public function results(Request $request, string $uuid): JsonResponse
    {
        $run = $this->service->findTenantRun(TenantContext::companyId($request), $uuid);
        $results = $run->results()->orderBy('check_code')->get()->map(fn ($result): array => [
            'check_code' => $result->check_code,
            'domain' => $result->domain,
            'status' => $result->status,
            'algorithm_version' => $result->algorithm_version,
            'left_total' => $result->left_total,
            'right_total' => $result->right_total,
            'difference' => $result->difference,
            'row_count' => $result->row_count,
            'evidence' => $result->evidence,
            'fingerprint' => $result->fingerprint,
            'result_hash' => $result->result_hash,
        ]);

        return response()->json([
            'data' => $results,
            'meta' => [
                'run_uuid' => $run->uuid,
                'snapshot_hash' => $run->snapshot_hash,
                'enforcement' => 'off',
            ],
        ]);
    }

    /**
     * Return evidence that is available for a period-close review without
     * deciding whether the period may be closed.  The current reconciliation
     * implementation is deliberately a shadow control: it has no approved
     * policy, exception/sign-off workflow, or enforcement authority.
     */
    public function closeReadiness(Request $request, int $periodId): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $period = Period::query()
            ->with('fiscalYear:id,company_id')
            ->whereKey($periodId)
            ->whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
            ->first();

        if ($period === null) {
            throw new NotFoundHttpException('Accounting period was not found for the authenticated company.');
        }

        $latestRun = ReconciliationRun::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->latest('completed_at')
            ->latest('id')
            ->first();

        $latestEvaluation = PeriodCloseReadinessSnapshot::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->latest('evaluated_at')
            ->latest('id')
            ->first();

        return response()->json([
            'data' => [
                'schema' => 'period-close-readiness.v1',
                'mode' => 'informational_only',
                'period' => [
                    'id' => $period->id,
                    'fiscal_year_id' => $period->fiscal_year_id,
                    'start_date' => $period->start_date?->toDateString(),
                    'end_date' => $period->end_date?->toDateString(),
                    'status' => $period->status,
                    'is_closed' => $period->is_closed,
                ],
                'close_gate' => [
                    'enforcement' => 'off',
                    'status' => 'not_evaluated',
                    'close_permitted_by_this_endpoint' => false,
                    'reason' => 'No owner-approved reconciliation policy, exception workflow, sign-off workflow, or close-gate policy is configured.',
                ],
                'reconciliation_shadow' => [
                    'run_available' => $latestRun !== null,
                    'latest_run' => $latestRun === null ? null : [
                        'uuid' => $latestRun->uuid,
                        'status' => $latestRun->status,
                        'completed_at' => $latestRun->completed_at?->toISOString(),
                        'algorithm_version' => $latestRun->algorithm_version,
                        'failed_result_count' => $latestRun->failed_result_count,
                        'warning_result_count' => $latestRun->warning_result_count,
                        'not_available_result_count' => $latestRun->not_available_result_count,
                        'snapshot_hash' => $latestRun->snapshot_hash,
                    ],
                    'limitations' => [
                        'A shadow run is an immutable GL/source-integrity snapshot, not a subledger-to-GL reconciliation.',
                        'Failed, warning, and not-available checks do not block period closing while enforcement is off.',
                        'No exception evidence, waiver, segregation-of-duties sign-off, or close-package record is available.',
                    ],
                ],
                'latest_evaluation' => $latestEvaluation === null ? null : $this->serializeReadinessSnapshot($latestEvaluation),
            ],
        ]);
    }

    /**
     * Evaluate the immutable close-readiness evidence from the current
     * server-side contracts. This is deliberately a POST: it records a new
     * append-only snapshot and never grants close authority by itself.
     */
    public function evaluateCloseReadiness(Request $request, int $periodId, PeriodCloseReadinessService $readinessService): JsonResponse
    {
        $snapshot = $readinessService->evaluate(
            TenantContext::companyId($request),
            $periodId,
            $request->user()?->id,
        );

        return response()->json(['data' => $this->serializeReadinessSnapshot($snapshot)], 201);
    }

    /** @return array<string, mixed> */
    private function serializeRun(ReconciliationRun $run): array
    {
        return [
            'uuid' => $run->uuid,
            'period_id' => $run->period_id,
            'basis' => $run->basis,
            'status' => $run->status,
            'algorithm_version' => $run->algorithm_version,
            'input_cutoff_at' => $run->input_cutoff_at?->toISOString(),
            'started_at' => $run->started_at?->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'posted_entry_count' => $run->posted_entry_count,
            'posted_line_count' => $run->posted_line_count,
            'total_debit' => $run->total_debit,
            'total_credit' => $run->total_credit,
            'result_count' => $run->result_count,
            'failed_result_count' => $run->failed_result_count,
            'warning_result_count' => $run->warning_result_count,
            'not_available_result_count' => $run->not_available_result_count,
            'snapshot_hash' => $run->snapshot_hash,
            'enforcement' => 'off',
            'created_at' => $run->created_at?->toISOString(),
        ];
    }

    /** @return array<string,mixed> */
    private function serializeReadinessSnapshot(PeriodCloseReadinessSnapshot $snapshot): array
    {
        return [
            'id' => (int) $snapshot->id,
            'uuid' => $snapshot->uuid,
            'period_id' => (int) $snapshot->period_id,
            'status' => $snapshot->status,
            'eligible_to_close' => (bool) $snapshot->eligible_to_close,
            'schema_version' => $snapshot->schema_version,
            'snapshot_hash' => $snapshot->snapshot_hash,
            'evaluated_at' => $snapshot->evaluated_at?->toISOString(),
            'snapshot' => $snapshot->snapshot,
        ];
    }
}
