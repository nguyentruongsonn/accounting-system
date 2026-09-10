<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Period;
use App\Models\PeriodCloseReadinessSnapshot;
use App\Models\PeriodCloseSignoffEvent;
use App\Models\PeriodCloseSignoffPackage;
use App\Models\PeriodCloseSignoffPolicy;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records a controlled close-review package, without granting close authority.
 *
 * This provides evidence of an owner-configured review and uses the generic
 * ApprovalWorkflow only through this boundary. PeriodClosingService consumes
 * the approved signoff lineage through its posting gate; reconciliation
 * enforcement, readiness and a close-gate owner decision are still required
 * before any package can permit closing.
 */
final class PeriodCloseSignoffService
{
    public const APPROVAL_SUBJECT = 'period_close_signoff_package';
    /** @var list<string> */
    private const INTERNAL_REVIEWER_ROLES = ['admin', 'accountant'];

    public function __construct(private readonly ApprovalWorkflowService $approvalWorkflow) {}

    /** @param list<string> $reviewerRoles */
    public function createDraftPolicy(User $actor, string $version, DateTimeInterface $from, DateTimeInterface $to, array $reviewerRoles, bool $sod = true, string $approvalKey = 'gl.period-close.signoff'): PeriodCloseSignoffPolicy
    {
        $companyId = $this->company($actor);
        $roles = $this->normaliseRoles($reviewerRoles);
        if (trim($version) === '' || $from > $to || trim($approvalKey) === '') throw new LogicException('A policy version, valid effective range, approval key, and at least one reviewer role are required.');
        return PeriodCloseSignoffPolicy::withoutGlobalScope('company')->create([
            'company_id' => $companyId, 'policy_version' => $version, 'effective_from' => $from, 'effective_to' => $to,
            'reviewer_roles' => $roles, 'separation_of_duties_required' => $sod, 'approval_key' => $approvalKey, 'created_by' => $actor->id,
        ]);
    }

    public function activatePolicy(User $actor, PeriodCloseSignoffPolicy $policy, ?DateTimeInterface $at = null): PeriodCloseSignoffPolicy
    {
        $companyId = $this->company($actor); $at ??= now();
        return DB::transaction(function () use ($actor, $policy, $at, $companyId): PeriodCloseSignoffPolicy {
            // Scope the row before taking the lock. A foreign policy must not
            // be loaded/locked merely to reject it after the fact.
            $locked = PeriodCloseSignoffPolicy::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($policy->id);
            $this->assertCompany($companyId, $locked->company_id);
            if ($locked->status !== 'draft') throw new LogicException('Only a draft close-signoff policy can be activated.');
            if ($locked->separation_of_duties_required && $locked->created_by === $actor->id) throw new AuthorizationException('Maker-checker policy forbids its author from activating it.');
            $overlap = PeriodCloseSignoffPolicy::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->where('status', 'active')
                ->where('effective_from', '<=', $locked->effective_to)->where('effective_to', '>=', $locked->effective_from)->exists();
            if ($overlap) throw ValidationException::withMessages(['effective_from' => 'Active close-signoff policy effective ranges must not overlap.']);
            PeriodCloseSignoffPolicy::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($locked->id)->update(['status' => 'active', 'activated_by' => $actor->id, 'activated_at' => $at, 'updated_at' => now()]);
            return PeriodCloseSignoffPolicy::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($locked->id);
        });
    }

    public function prepare(User $actor, int $periodId, int $readinessSnapshotId, ?DateTimeInterface $at = null): PeriodCloseSignoffPackage
    {
        $companyId = $this->company($actor); $at ??= now();
        return DB::transaction(function () use ($actor, $periodId, $readinessSnapshotId, $at, $companyId): PeriodCloseSignoffPackage {
            $period = Period::query()->with('fiscalYear:id,company_id')->whereKey($periodId)
                ->whereHas('fiscalYear', fn ($q) => $q->where('company_id', $companyId))->lockForUpdate()->first();
            if ($period === null) throw new NotFoundHttpException('Accounting period was not found for the authenticated company.');
            if ($period->is_closed) throw ValidationException::withMessages(['period_id' => 'A close-signoff package cannot be prepared for an already closed period.']);
            $readiness = PeriodCloseReadinessSnapshot::withoutGlobalScope('company')->whereKey($readinessSnapshotId)->where('company_id', $companyId)->where('period_id', $period->id)->lockForUpdate()->first();
            if ($readiness === null) throw new NotFoundHttpException('Close-readiness evidence was not found for this company and period.');
            if (! hash_equals($readiness->snapshot_hash, $this->hash($readiness->snapshot))) throw new LogicException('Close-readiness evidence hash validation failed.');
            $policy = PeriodCloseSignoffPolicy::withoutGlobalScope('company')->where('company_id', $companyId)->where('status', 'active')
                ->whereDate('effective_from', '<=', $at)->whereDate('effective_to', '>=', $at)->lockForUpdate()->sole();
            $reviewerRoles = $this->normaliseRoles((array) $policy->reviewer_roles);
            $policySnapshot = ['id' => $policy->id, 'version' => $policy->policy_version, 'reviewer_roles' => $reviewerRoles, 'separation_of_duties_required' => $policy->separation_of_duties_required, 'approval_key' => $policy->approval_key];
            $snapshot = [
                'schema' => 'period-close-signoff-package.v1', 'company_id' => $companyId,
                'period' => ['id' => $period->id, 'start_date' => $period->start_date?->toDateString(), 'end_date' => $period->end_date?->toDateString()],
                'readiness' => ['id' => $readiness->id, 'uuid' => $readiness->uuid, 'schema_version' => $readiness->schema_version, 'status' => $readiness->status, 'eligible_to_close' => $readiness->eligible_to_close, 'snapshot_hash' => $readiness->snapshot_hash, 'evaluated_at' => $readiness->evaluated_at?->toISOString()],
                'policy' => $policySnapshot,
                'limitations' => ['A package documents review evidence only.', 'It does not freeze source data, waive failed checks, or authorize period close.', 'Period closing remains fail-closed until a separately approved enforcing integration exists.'],
            ];
            return PeriodCloseSignoffPackage::withoutGlobalScope('company')->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'period_id' => $period->id, 'period_close_readiness_snapshot_id' => $readiness->id, 'period_close_signoff_policy_id' => $policy->id,
                'readiness_snapshot_hash' => $readiness->snapshot_hash, 'evidence_cutoff_at' => $readiness->evaluated_at, 'policy_snapshot' => $policySnapshot, 'package_snapshot' => $snapshot, 'package_hash' => $this->hash($snapshot), 'prepared_by' => $actor->id, 'prepared_at' => $at,
            ]);
        });
    }

    public function submit(User $actor, PeriodCloseSignoffPackage $package, ?array $evidence = null, ?DateTimeInterface $at = null): PeriodCloseSignoffPackage
    {
        $companyId = $this->company($actor); $at ??= now();
        return DB::transaction(function () use ($actor, $package, $evidence, $at, $companyId): PeriodCloseSignoffPackage {
            $locked = $this->lockedPackage($package, $companyId);
            if ($this->state($locked) !== 'prepared') throw new LogicException('Only a prepared close-signoff package can be submitted.');
            if (! hash_equals($locked->package_hash, $this->hash($locked->package_snapshot))) throw new LogicException('Close-signoff package hash validation failed.');
            $request = $this->approvalWorkflow->request($actor, (string) data_get($locked->policy_snapshot, 'approval_key'), self::APPROVAL_SUBJECT, $locked->id, [
                'package_uuid' => $locked->uuid, 'package_hash' => $locked->package_hash, 'readiness_snapshot_hash' => $locked->readiness_snapshot_hash, 'evidence_cutoff_at' => $locked->evidence_cutoff_at?->toISOString(), 'submission_evidence' => $evidence,
            ], $at);
            $this->event($locked, 'submitted', $actor, $at, $request, ['approval_request_id' => $request->id, 'package_hash' => $locked->package_hash]);
            return $locked->fresh('events');
        });
    }

    public function decide(User $actor, PeriodCloseSignoffPackage $package, int $stepOrder, string $decision, ?array $evidence = null, ?DateTimeInterface $at = null): PeriodCloseSignoffPackage
    {
        $companyId = $this->company($actor); $at ??= now();
        return DB::transaction(function () use ($actor, $package, $stepOrder, $decision, $evidence, $at, $companyId): PeriodCloseSignoffPackage {
            $locked = $this->lockedPackage($package, $companyId);
            if ($this->state($locked) !== 'submitted') throw new LogicException('Only a submitted close-signoff package can be reviewed.');
            $roles = $this->normaliseRoles((array) data_get($locked->policy_snapshot, 'reviewer_roles', []));
            if (! $actor->hasAnyRole($roles)) throw new AuthorizationException('The reviewer does not hold a role configured for this close-signoff policy.');
            $submitted = $locked->events()->where('event_type', 'submitted')->sole();
            // The approval request is part of the tenant-owned signoff
            // lineage; constrain it before locking so a malformed event
            // cannot make a foreign request part of this transaction.
            $request = ApprovalRequest::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($submitted->approval_request_id);
            if ($request->company_id !== $companyId || $request->approval_key !== data_get($locked->policy_snapshot, 'approval_key') || $request->subject_type !== self::APPROVAL_SUBJECT || $request->subject_id !== (string) $locked->id) throw new LogicException('Close-signoff approval request integrity validation failed.');
            $result = $this->approvalWorkflow->decide($actor, $request, $stepOrder, $decision, $evidence, $at);
            if (in_array($result->status, ['approved', 'rejected'], true)) $this->event($locked, $result->status, $actor, $at, $result, ['approval_request_id' => $result->id, 'approval_status' => $result->status]);
            return $locked->fresh('events');
        });
    }

    public function state(PeriodCloseSignoffPackage $package): string
    {
        $events = $package->relationLoaded('events') ? $package->events : $package->events()->get();
        if ($events->contains('event_type', 'rejected')) return 'rejected';
        if ($events->contains('event_type', 'approved')) return 'approved_evidence_only';
        return $events->contains('event_type', 'submitted') ? 'submitted' : 'prepared';
    }

    private function lockedPackage(PeriodCloseSignoffPackage $package, int $companyId): PeriodCloseSignoffPackage
    {
        // Scope before locking/loading to keep direct service callers from
        // selecting a foreign close package into the transaction.
        return PeriodCloseSignoffPackage::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->with('events')
            ->lockForUpdate()
            ->findOrFail($package->id);
    }
    private function event(PeriodCloseSignoffPackage $package, string $type, User $actor, DateTimeInterface $at, ?ApprovalRequest $request, array $evidence): void { PeriodCloseSignoffEvent::create(['period_close_signoff_package_id' => $package->id, 'event_type' => $type, 'approval_request_id' => $request?->id, 'evidence' => $evidence, 'event_hash' => $this->hash(['package_id' => $package->id, 'event_type' => $type, 'approval_request_id' => $request?->id, 'evidence' => $evidence, 'recorded_by' => $actor->id, 'recorded_at' => $at->format(DATE_ATOM)]), 'recorded_by' => $actor->id, 'recorded_at' => $at]); }
    /** @param list<mixed> $roles @return list<string> */
    private function normaliseRoles(array $roles): array
    {
        $roles = array_values(array_unique(array_filter(array_map(fn ($role) => trim((string) $role), $roles))));
        if ($roles === []) throw ValidationException::withMessages(['reviewer_roles' => 'At least one reviewer role is required.']);
        if (array_diff($roles, self::INTERNAL_REVIEWER_ROLES) !== []) {
            throw ValidationException::withMessages(['reviewer_roles' => 'Close-signoff reviewers must use only the internal admin or accountant roles.']);
        }

        return array_values(array_filter(self::INTERNAL_REVIEWER_ROLES, fn (string $role) => in_array($role, $roles, true)));
    }
    private function company(User $actor): int { if ((int) ($actor->company_id ?? 0) < 1) throw new AuthorizationException('Acting user is not assigned to a company.'); return (int) $actor->company_id; }
    private function assertCompany(int $expected, int $actual): void { if ($expected !== $actual) throw new AuthorizationException('Cross-company close-signoff access is forbidden.'); }
    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        // JSON object key order is not stable across database drivers (MySQL
        // canonicalises JSON objects; SQLite preserves insertion order).
        // Hash the canonical representation so immutable evidence validates
        // identically on both engines.
        return hash('sha256', json_encode($this->canonicalise($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
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
