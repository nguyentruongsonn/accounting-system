<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Period;
use App\Models\PeriodCloseReadinessSnapshot;
use App\Models\PeriodCloseSignoffEvent;
use App\Models\PeriodCloseSignoffPackage;
use Illuminate\Validation\ValidationException;

/**
 * The final close boundary consumes immutable sign-off evidence only after a
 * separately enforced readiness snapshot has passed.  It never accepts a
 * package/request id from the caller: a favourable or stale approval cannot
 * be selected by an API client.
 */
final class PeriodCloseSignoffPostingGate
{
    /** @return array<string, mixed> */
    public function requireApproved(
        int $companyId,
        Period $period,
        PeriodCloseReadinessSnapshot $readiness,
        ?int $closingActorId,
    ): array {
        $this->assertReadiness($companyId, $period, $readiness);

        $packages = PeriodCloseSignoffPackage::withoutGlobalScope('company')
            ->with(['events', 'readinessSnapshot'])
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->where('period_close_readiness_snapshot_id', $readiness->id)
            ->where('readiness_snapshot_hash', $readiness->snapshot_hash)
            ->lockForUpdate()
            ->get();

        $valid = $packages->filter(fn (PeriodCloseSignoffPackage $package): bool => $this->isValid($package, $companyId, $period, $readiness, $closingActorId))->values();
        if ($valid->count() !== 1) {
            throw ValidationException::withMessages([
                'period_close_signoff' => 'Khóa kỳ yêu cầu đúng một bộ hồ sơ phê duyệt hợp lệ, bất biến và khớp chính xác bằng chứng readiness của kỳ.',
            ]);
        }

        /** @var PeriodCloseSignoffPackage $package */
        $package = $valid->sole();
        $submitted = $package->events->firstWhere('event_type', 'submitted');
        $approved = $package->events->firstWhere('event_type', 'approved');
        $request = ApprovalRequest::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->with(['steps.decisions'])
            ->lockForUpdate()
            ->findOrFail($submitted->approval_request_id);

        return [
            'signoff_package_id' => (int) $package->id,
            'signoff_package_uuid' => $package->uuid,
            'signoff_package_hash' => $package->package_hash,
            'readiness_snapshot_id' => (int) $readiness->id,
            'readiness_snapshot_hash' => $readiness->snapshot_hash,
            'evidence_cutoff_at' => $package->evidence_cutoff_at?->toAtomString(),
            'approval_request_id' => (int) $request->id,
            'approval_policy_id' => (int) $request->approval_policy_id,
            'requested_by' => (int) $request->requested_by,
            'resolved_by' => (int) $request->resolved_by,
            'resolved_at' => $request->resolved_at?->toAtomString(),
            'submitted_event_hash' => $submitted->event_hash,
            'approved_event_hash' => $approved->event_hash,
            'decision_hashes' => $request->steps->flatMap(fn ($step) => $step->decisions)
                ->where('decision', 'approved')->pluck('evidence_hash')->values()->all(),
        ];
    }

    private function assertReadiness(int $companyId, Period $period, PeriodCloseReadinessSnapshot $readiness): void
    {
        if ($readiness->company_id !== $companyId || $readiness->period_id !== $period->id
            || ! $readiness->eligible_to_close
            || ! hash_equals($readiness->snapshot_hash, $this->hash($readiness->snapshot))) {
            throw ValidationException::withMessages(['period_close_readiness' => 'Bằng chứng readiness không hợp lệ, không thuộc kỳ hiện tại hoặc đã bị thay đổi.']);
        }

        $checks = data_get($readiness->snapshot, 'checks', []);
        if (! is_array($checks) || collect($checks)->contains(fn ($check) => ! is_array($check) || in_array($check['status'] ?? null, ['fail', 'not_available'], true))) {
            throw ValidationException::withMessages(['period_close_readiness' => 'Readiness chứa kiểm tra thất bại hoặc nguồn chưa sẵn sàng; không thể khóa kỳ.']);
        }
    }

    private function isValid(PeriodCloseSignoffPackage $package, int $companyId, Period $period, PeriodCloseReadinessSnapshot $readiness, ?int $closingActorId): bool
    {
        if (! hash_equals($package->package_hash, $this->hash($package->package_snapshot))
            || $package->company_id !== $companyId
            || $package->period_id !== $period->id
            || $package->period_close_readiness_snapshot_id !== $readiness->id
            || ! hash_equals($package->readiness_snapshot_hash, $readiness->snapshot_hash)
            || ! $package->evidence_cutoff_at
            || ! $readiness->evaluated_at
            || ! $package->evidence_cutoff_at->equalTo($readiness->evaluated_at)
            || data_get($package->package_snapshot, 'schema') !== 'period-close-signoff-package.v1'
            || (int) data_get($package->package_snapshot, 'company_id') !== $companyId
            || (int) data_get($package->package_snapshot, 'period.id') !== $period->id
            || (int) data_get($package->package_snapshot, 'readiness.id') !== $readiness->id
            || ! hash_equals((string) data_get($package->package_snapshot, 'readiness.snapshot_hash'), $readiness->snapshot_hash)
            || (string) data_get($package->package_snapshot, 'readiness.evaluated_at') !== $readiness->evaluated_at->toISOString()) {
            return false;
        }

        $submitted = $package->events->firstWhere('event_type', 'submitted');
        $approved = $package->events->firstWhere('event_type', 'approved');
        if (! $submitted || ! $approved || ! $this->eventHashMatches($submitted) || ! $this->eventHashMatches($approved)
            || $submitted->approval_request_id === null || $submitted->approval_request_id !== $approved->approval_request_id) {
            return false;
        }

        $request = ApprovalRequest::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->with(['steps.decisions'])
            ->lockForUpdate()
            ->find($submitted->approval_request_id);
        if (! $request || $request->company_id !== $companyId || $request->status !== 'approved'
            || $request->approval_key !== data_get($package->policy_snapshot, 'approval_key')
            || $request->subject_type !== PeriodCloseSignoffService::APPROVAL_SUBJECT
            || $request->subject_id !== (string) $package->id
            || ! $request->requested_by || ! $request->resolved_by || ! $request->resolved_at
            || ! $this->requestEvidenceMatches($request, $package)) {
            return false;
        }

        $sod = (bool) data_get($package->policy_snapshot, 'separation_of_duties_required') || $request->separation_of_duties_required;
        $approvers = $request->steps->flatMap(fn ($step) => $step->decisions)->where('decision', 'approved');
        foreach ($request->steps as $step) {
            if ($step->status !== 'approved') {
                return false;
            }
            $stepApprovals = $step->decisions->where('decision', 'approved');
            if ($stepApprovals->count() < $step->required_approvals || $stepApprovals->contains(fn ($decision) => ! $this->decisionHashMatches($decision, (int) $step->step_order))) {
                return false;
            }
        }
        if ($sod) {
            // In the fixed two-person workflow the accountant prepares and
            // submits, while the admin reviews and performs the close.  SoD
            // separates maker/requester from reviewer; it must not require a
            // third identity solely to click the final close action.
            if ($closingActorId === null || (int) $package->prepared_by === $closingActorId || (int) $request->requested_by === $closingActorId
                || $approvers->contains('decided_by', $package->prepared_by)
                || $approvers->contains('decided_by', $request->requested_by)) {
                return false;
            }
        }

        return true;
    }

    private function requestEvidenceMatches(ApprovalRequest $request, PeriodCloseSignoffPackage $package): bool
    {
        $evidence = $request->request_evidence;

        return is_array($evidence)
            && ($evidence['package_uuid'] ?? null) === $package->uuid
            && is_string($evidence['package_hash'] ?? null) && hash_equals($package->package_hash, $evidence['package_hash'])
            && is_string($evidence['readiness_snapshot_hash'] ?? null) && hash_equals($package->readiness_snapshot_hash, $evidence['readiness_snapshot_hash'])
            && ($evidence['evidence_cutoff_at'] ?? null) === $package->evidence_cutoff_at?->toISOString();
    }

    private function eventHashMatches(PeriodCloseSignoffEvent $event): bool
    {
        return hash_equals($event->event_hash, $this->hash([
            'package_id' => $event->period_close_signoff_package_id,
            'event_type' => $event->event_type,
            'approval_request_id' => $event->approval_request_id,
            'evidence' => $event->evidence,
            'recorded_by' => $event->recorded_by,
            'recorded_at' => $event->recorded_at?->format(DATE_ATOM),
        ]));
    }

    private function decisionHashMatches(object $decision, int $stepOrder): bool
    {
        return hash_equals($decision->evidence_hash, $this->hash([
            'request_id' => $decision->approval_request_id,
            'step' => $stepOrder,
            'actor' => $decision->decided_by,
            'decision' => $decision->decision,
            'evidence' => $decision->evidence,
            'at' => $decision->decided_at?->format(DATE_ATOM),
        ]));
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalise($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /** @return array<string|int, mixed> */
    private function canonicalise(array $value): array
    {
        if (! array_is_list($value)) {
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
