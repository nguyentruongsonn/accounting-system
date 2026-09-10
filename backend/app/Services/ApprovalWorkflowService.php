<?php

namespace App\Services;

use App\Models\ApprovalDecision;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestStep;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Reusable maker-checker workflow boundary.  It deliberately has no voucher
 * endpoint wiring: each document family must opt in only after its posting
 * lifecycle can fail closed on an approved request.
 */
final class ApprovalWorkflowService
{
    /** @param list<array{required_approvals?: int}> $steps */
    public function createDraftPolicy(User $actor, string $key, string $version, DateTimeInterface $from, DateTimeInterface $to, array $steps, bool $sod = true): ApprovalPolicy
    {
        $companyId = $this->company($actor);
        $this->validatePolicyInput($key, $version, $from, $to, $steps);
        return ApprovalPolicy::withoutGlobalScope('company')->create([
            'company_id' => $companyId, 'approval_key' => $key, 'policy_version' => $version,
            'effective_from' => $from, 'effective_to' => $to, 'steps' => $this->normaliseSteps($steps),
            'separation_of_duties_required' => $sod, 'created_by' => $actor->id,
        ]);
    }

    public function activatePolicy(User $actor, ApprovalPolicy $policy, ?DateTimeInterface $at = null): ApprovalPolicy
    {
        $companyId = $this->company($actor); $at ??= now();
        return DB::transaction(function () use ($actor, $policy, $companyId, $at): ApprovalPolicy {
            if (ApprovalPolicy::withoutGlobalScope('company')->whereKey($policy->id)->where('company_id', '!=', $companyId)->exists()) {
                throw new AuthorizationException('Cross-company approval workflow access is forbidden.');
            }
            // Scope before locking so a foreign policy cannot be selected
            // into this transaction before the tenant check runs.
            $locked = ApprovalPolicy::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($policy->id);
            $this->assertCompany($companyId, $locked->company_id);
            if ($locked->status !== 'draft') throw new LogicException('Only a draft approval policy can be activated.');
            $overlap = ApprovalPolicy::withoutGlobalScope('company')
                ->where('company_id', $companyId)->lockForUpdate()
                ->where('approval_key', $locked->approval_key)->where('status', 'active')
                ->where('effective_from', '<=', $locked->effective_to)->where('effective_to', '>=', $locked->effective_from)->exists();
            if ($overlap) throw ValidationException::withMessages(['effective_from' => 'Active approval-policy effective ranges must not overlap.']);
            ApprovalPolicy::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($locked->id)->update(['status' => 'active', 'activated_by' => $actor->id, 'activated_at' => $at, 'updated_at' => now()]);
            return ApprovalPolicy::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($locked->id);
        });
    }

    /** @param array<string,mixed>|null $evidence */
    public function request(User $actor, string $key, string $subjectType, string|int $subjectId, ?array $evidence = null, ?DateTimeInterface $at = null): ApprovalRequest
    {
        $companyId = $this->company($actor); $at ??= now();
        if (trim($subjectType) === '' || (string) $subjectId === '') throw new LogicException('An approval request requires a subject type and id.');
        return DB::transaction(function () use ($actor, $key, $subjectType, $subjectId, $evidence, $at, $companyId): ApprovalRequest {
            $policy = ApprovalPolicy::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->where('approval_key', $key)->where('status', 'active')
                ->whereDate('effective_from', '<=', $at)->whereDate('effective_to', '>=', $at)->sole();
            $snapshot = ['id' => $policy->id, 'version' => $policy->policy_version, 'steps' => $policy->steps, 'separation_of_duties_required' => $policy->separation_of_duties_required];
            $request = ApprovalRequest::withoutGlobalScope('company')->create([
                'company_id' => $companyId, 'approval_policy_id' => $policy->id, 'approval_key' => $key, 'subject_type' => $subjectType, 'subject_id' => (string) $subjectId,
                'separation_of_duties_required' => $policy->separation_of_duties_required, 'policy_snapshot' => $snapshot, 'request_evidence' => $evidence, 'requested_by' => $actor->id, 'requested_at' => $at,
            ]);
            foreach ($policy->steps as $order => $step) ApprovalRequestStep::create(['approval_request_id' => $request->id, 'step_order' => $order + 1, 'required_approvals' => $step['required_approvals']]);
            return $request->load('steps');
        });
    }

    /** @param array<string,mixed>|null $evidence */
    public function decide(User $actor, ApprovalRequest $request, int $stepOrder, string $decision, ?array $evidence = null, ?DateTimeInterface $at = null): ApprovalRequest
    {
        $companyId = $this->company($actor); $at ??= now(); $decision = strtolower($decision);
        if (! in_array($decision, ['approved', 'rejected'], true)) throw ValidationException::withMessages(['decision' => 'Decision must be approved or rejected.']);
        return DB::transaction(function () use ($actor, $request, $stepOrder, $decision, $evidence, $at, $companyId): ApprovalRequest {
            if (ApprovalRequest::withoutGlobalScope('company')->whereKey($request->id)->where('company_id', '!=', $companyId)->exists()) {
                throw new AuthorizationException('Cross-company approval workflow access is forbidden.');
            }
            // Approval decisions are tenant-owned evidence; never lock a
            // foreign request merely to reject it after loading.
            $locked = ApprovalRequest::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($request->id);
            $this->assertCompany($companyId, $locked->company_id);
            if ($locked->status !== 'pending') throw new LogicException('Only a pending approval request can be decided.');
            if ($locked->separation_of_duties_required && $locked->requested_by === $actor->id) throw new AuthorizationException('Maker-checker policy forbids a requester approving their own request.');
            $step = ApprovalRequestStep::where('approval_request_id', $locked->id)->where('step_order', $stepOrder)->lockForUpdate()->sole();
            $priorIncomplete = ApprovalRequestStep::where('approval_request_id', $locked->id)->where('step_order', '<', $stepOrder)->where('status', '!=', 'approved')->exists();
            if ($priorIncomplete || $step->status !== 'pending') throw new LogicException('Approval steps must be decided in order.');
            $hash = $this->hash(['request_id' => $locked->id, 'step' => $stepOrder, 'actor' => $actor->id, 'decision' => $decision, 'evidence' => $evidence, 'at' => $at->format(DATE_ATOM)]);
            ApprovalDecision::create(['approval_request_id' => $locked->id, 'approval_request_step_id' => $step->id, 'decided_by' => $actor->id, 'decision' => $decision, 'evidence' => $evidence, 'evidence_hash' => $hash, 'decided_at' => $at]);
            if ($decision === 'rejected') {
                ApprovalRequestStep::whereKey($step->id)->update(['status' => 'rejected', 'completed_at' => $at, 'updated_at' => now()]);
                ApprovalRequest::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($locked->id)->update(['status' => 'rejected', 'resolved_by' => $actor->id, 'resolved_at' => $at, 'updated_at' => now()]);
                return ApprovalRequest::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($locked->id);
            }
            $count = ApprovalDecision::where('approval_request_step_id', $step->id)->where('decision', 'approved')->count();
            if ($count >= $step->required_approvals) ApprovalRequestStep::whereKey($step->id)->update(['status' => 'approved', 'completed_at' => $at, 'updated_at' => now()]);
            $unfinished = ApprovalRequestStep::where('approval_request_id', $locked->id)->where('status', '!=', 'approved')->exists();
            if (! $unfinished) ApprovalRequest::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($locked->id)->update(['status' => 'approved', 'resolved_by' => $actor->id, 'resolved_at' => $at, 'updated_at' => now()]);
            return ApprovalRequest::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($locked->id);
        });
    }

    private function company(User $actor): int { if ($actor->company_id === null) throw new AuthorizationException('Acting user is not assigned to a company.'); return (int) $actor->company_id; }
    private function assertCompany(int $expected, int $actual): void { if ($expected !== $actual) throw new AuthorizationException('Cross-company approval workflow access is forbidden.'); }
    private function validatePolicyInput(string $key, string $version, DateTimeInterface $from, DateTimeInterface $to, array $steps): void { if (trim($key) === '' || trim($version) === '' || $from > $to || $steps === []) throw new LogicException('Approval policy key, version, non-empty ordered steps, and a valid effective range are required.'); $this->normaliseSteps($steps); }
    /** @param list<array{required_approvals?: int}> $steps @return list<array{required_approvals:int}> */
    private function normaliseSteps(array $steps): array { $result = []; foreach ($steps as $step) { $n = (int) ($step['required_approvals'] ?? 1); if ($n < 1 || $n > 20) throw ValidationException::withMessages(['steps' => 'Each approval step must require between 1 and 20 approvers.']); $result[] = ['required_approvals' => $n]; } return $result; }

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
