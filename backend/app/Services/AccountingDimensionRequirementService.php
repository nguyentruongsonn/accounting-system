<?php

namespace App\Services;

use App\Models\AccountingDimensionDefinition;
use App\Models\AccountingPolicyDimensionRequirement;
use App\Models\AccountingPolicyVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Maintains the explicit, tenant-owned bridge from policy requirement names to
 * selectable dimensions. It does not create a dimension nor alter a policy's
 * signed JSON contract on the caller's behalf.
 */
class AccountingDimensionRequirementService
{
    /**
     * @param  array<int, array{accounting_dimension_definition_id:int, is_required?:bool, effective_from?:string, effective_to?:string}>  $requirements
     * @return Collection<int, AccountingPolicyDimensionRequirement>
     */
    public function replaceDraftRequirements(User $actor, AccountingPolicyVersion $policy, array $requirements)
    {
        if ((int) $actor->company_id !== (int) $policy->company_id) {
            throw new AuthorizationException('Cannot maintain policy dimensions for another tenant.');
        }
        if ($policy->status !== 'draft' || $policy->approved_at !== null) {
            throw new LogicException('Only an unsigned policy draft may have its dimension requirements changed.');
        }

        $definitionIds = array_map(static fn (array $requirement): int => (int) ($requirement['accounting_dimension_definition_id'] ?? 0), $requirements);
        if (count($definitionIds) !== count(array_unique($definitionIds)) || in_array(0, $definitionIds, true)) {
            throw new LogicException('A policy dimension definition may be referenced once only.');
        }

        $definitions = AccountingDimensionDefinition::withoutGlobalScope('company')
            ->where('company_id', $policy->company_id)->whereIn('id', $definitionIds)->get()->keyBy('id');
        if ($definitions->count() !== count($definitionIds)) {
            throw new AuthorizationException('Every referenced dimension definition must belong to the policy tenant.');
        }

        return DB::transaction(function () use ($policy, $requirements, $definitions) {
            AccountingPolicyDimensionRequirement::withoutGlobalScope('company')
                ->where('accounting_policy_version_id', $policy->id)->delete();

            foreach ($requirements as $requirement) {
                $definition = $definitions->get((int) $requirement['accounting_dimension_definition_id']);
                AccountingPolicyDimensionRequirement::create([
                    'company_id' => $policy->company_id,
                    'accounting_policy_version_id' => $policy->id,
                    'accounting_dimension_definition_id' => $definition->id,
                    'is_required' => $requirement['is_required'] ?? true,
                    'effective_from' => $requirement['effective_from'] ?? $policy->effective_from->toDateString(),
                    'effective_to' => $requirement['effective_to'] ?? $policy->effective_to->toDateString(),
                ]);
            }

            return $policy->dimensionRequirements()->with('definition')->orderBy('id')->get();
        });
    }
}
