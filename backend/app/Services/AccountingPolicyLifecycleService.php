<?php

namespace App\Services;

use App\Models\AccountingPolicyVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class AccountingPolicyLifecycleService
{
    /** @param array<string, mixed> $attributes */
    public function createDraft(User $actor, array $attributes): AccountingPolicyVersion
    {
        $actorCompanyId = (int) ($actor->company_id ?? 0);
        if ($actorCompanyId < 1) {
            throw new AuthorizationException('The acting user is not assigned to a company.');
        }
        if ((int) ($attributes['company_id'] ?? 0) !== $actorCompanyId) {
            throw new AuthorizationException('Cannot create an accounting policy for another tenant.');
        }

        if (Schema::hasColumn('accounting_policy_versions', 'created_by')) {
            $attributes['created_by'] = $actor->id;
        }

        return AccountingPolicyVersion::create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function updateDraft(User $actor, AccountingPolicyVersion $policy, array $attributes): AccountingPolicyVersion
    {
        if ((int) ($actor->company_id ?? 0) !== (int) $policy->company_id) {
            throw new AuthorizationException('Cannot edit an accounting policy for another tenant.');
        }
        if ($policy->created_by !== null && (int) $policy->created_by !== (int) $actor->id) {
            throw new AuthorizationException('Only the policy maker may edit their unsigned draft.');
        }
        if ($policy->approved_at !== null || $policy->status !== 'draft') {
            throw new LogicException('Only an unsigned accounting policy draft can be edited.');
        }

        unset($attributes['company_id'], $attributes['created_by'], $attributes['status'], $attributes['approved_by'], $attributes['approved_at'], $attributes['contract_hash']);
        $policy->fill($attributes)->save();

        return $policy->refresh();
    }

    public function approve(User $actor, AccountingPolicyVersion $policy, ?CarbonImmutable $at = null): AccountingPolicyVersion
    {
        return DB::transaction(function () use ($actor, $policy, $at): AccountingPolicyVersion {
            // Re-load and lock the tenant-scoped row before maker-checker and
            // contract validation. The caller's model may be stale, and an
            // unlocked approval can race another approver or a draft update.
            $policy = AccountingPolicyVersion::withoutGlobalScope('company')
                ->where('company_id', (int) $actor->company_id)
                ->lockForUpdate()
                ->findOrFail($policy->id);
            if ((int) $actor->company_id !== (int) $policy->company_id) {
                throw new AuthorizationException('Cannot approve an accounting policy for another tenant.');
            }
            if (config('accounting.enforce_accounting_policy_maker_checker', false)
                && $policy->created_by !== null
                && (int) $policy->created_by === (int) $actor->id) {
                throw new AuthorizationException('The accounting policy maker cannot approve their own policy.');
            }
            if ($policy->approved_at !== null || $policy->status !== 'draft') {
                throw new LogicException('Only an unsigned accounting policy draft can be approved.');
            }

            $contract = $this->canonicalContract($policy);
            if (($contract['posting_rule_contract'] ?? []) === []) {
                throw new LogicException('An approved accounting policy requires a non-empty posting-rule contract.');
            }
            if ($policy->required_dimensions === null || ! is_array($policy->required_dimensions)) {
                throw new LogicException('An approved accounting policy must declare its dimension requirements, including an empty list when none apply.');
            }
            if ($policy->regulatory_dependencies === null || ! is_array($policy->regulatory_dependencies)) {
                throw new LogicException('An approved accounting policy must declare REGULATORY DEPENDENCY records, including an empty list when none apply.');
            }
            $linkedRequirements = $policy->dimensionRequirements()->with('definition')->orderBy('id')->get();
            if ($linkedRequirements->isNotEmpty()) {
                $declared = array_values($policy->required_dimensions);
                $linked = $linkedRequirements->where('is_required', true)
                    ->map(fn ($requirement): ?string => $requirement->definition?->code)->all();
                sort($declared);
                sort($linked);
                if ($declared !== $linked || in_array(null, $linked, true)) {
                    throw new LogicException('Explicit policy dimension requirements must exactly match the policy dimension declaration before approval.');
                }
            }

            $approvedAt = $at ?? CarbonImmutable::now();
            AccountingPolicyVersion::withoutGlobalScope('company')
                ->whereKey($policy->id)
                ->where('status', 'draft')
                ->whereNull('approved_at')
                ->update([
                    'status' => 'approved',
                    'approved_by' => $actor->id,
                    'approved_at' => $approvedAt,
                    'contract_hash' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)),
                    'updated_at' => $approvedAt,
                ]);

            return $policy->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function canonicalContract(AccountingPolicyVersion $policy): array
    {
        return [
            'policy_key' => $policy->policy_key,
            'policy_version' => $policy->policy_version,
            'accounting_regime_profile_id' => (int) $policy->accounting_regime_profile_id,
            'effective_from' => $policy->effective_from->toDateString(),
            'effective_to' => $policy->effective_to->toDateString(),
            'posting_rule_contract' => $policy->posting_rule_contract ?? [],
            'required_dimensions' => $policy->required_dimensions,
            'dimension_requirements' => $policy->dimensionRequirements()->with('definition')->orderBy('id')->get()
                ->map(fn ($requirement): array => [
                    'dimension_code' => $requirement->definition?->code,
                    'is_required' => (bool) $requirement->is_required,
                    'effective_from' => $requirement->effective_from->toDateString(),
                    'effective_to' => $requirement->effective_to->toDateString(),
                ])->all(),
            'regulatory_dependencies' => $policy->regulatory_dependencies,
        ];
    }
}
