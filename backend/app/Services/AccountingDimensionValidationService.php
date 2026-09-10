<?php

namespace App\Services;

use App\Exceptions\AccountingDimensionUnavailableException;
use App\Models\AccountingDimensionValue;
use App\Models\AccountingPolicyDimensionRequirement;
use App\Models\AccountingPolicyVersion;

/**
 * Validates only explicit policy requirements. Any gap in the chain
 * policy -> dimension definition -> active value blocks the transaction.
 */
class AccountingDimensionValidationService
{
    /**
     * @param  array<string, int|string>  $dimensionValues  keyed by tenant dimension code
     */
    public function assertSatisfied(int $companyId, int $policyId, string $postingDate, array $dimensionValues): void
    {
        $policy = AccountingPolicyVersion::withoutGlobalScope('company')
            ->where('company_id', $companyId)->find($policyId);
        if ($policy === null || $policy->status !== 'approved' || $policy->approved_at === null
            || $postingDate < $policy->effective_from->toDateString() || $postingDate > $policy->effective_to->toDateString()) {
            throw new AccountingDimensionUnavailableException('An approved effective tenant accounting policy is required to validate dimensions.');
        }

        $requiredCodes = $this->requiredCodes($policy->required_dimensions);
        if ($requiredCodes === []) {
            return;
        }

        $requirements = AccountingPolicyDimensionRequirement::withoutGlobalScope('company')
            ->with('definition')
            ->where('company_id', $companyId)
            ->where('accounting_policy_version_id', $policy->id)
            ->where('is_required', true)
            ->whereDate('effective_from', '<=', $postingDate)
            ->whereDate('effective_to', '>=', $postingDate)
            ->get();

        $byCode = $requirements->keyBy(fn (AccountingPolicyDimensionRequirement $r): string => (string) $r->definition?->code);
        if ($requirements->count() !== count($requiredCodes) || array_diff($requiredCodes, $byCode->keys()->all()) !== []) {
            throw new AccountingDimensionUnavailableException('Policy dimension requirements are not fully approved, effective, and mapped to tenant definitions.');
        }

        foreach ($requiredCodes as $code) {
            $requirement = $byCode->get($code);
            $definition = $requirement?->definition;
            $valueId = $dimensionValues[$code] ?? null;
            if ($definition === null || $definition->status !== 'active'
                || $postingDate < $definition->effective_from->toDateString() || $postingDate > $definition->effective_to->toDateString()
                || ! is_scalar($valueId) || ! ctype_digit((string) $valueId)) {
                throw new AccountingDimensionUnavailableException("Required dimension [{$code}] has no active tenant value.");
            }

            $value = AccountingDimensionValue::withoutGlobalScope('company')
                ->where('id', (int) $valueId)
                ->where('company_id', $companyId)
                ->where('accounting_dimension_definition_id', $definition->id)
                ->first();
            if ($value === null || $value->status !== 'active'
                || $postingDate < $value->effective_from->toDateString() || $postingDate > $value->effective_to->toDateString()) {
                throw new AccountingDimensionUnavailableException("Required dimension [{$code}] does not have an active effective tenant value.");
            }
        }
    }

    /** @param mixed $declared @return list<string> */
    private function requiredCodes(mixed $declared): array
    {
        if (! is_array($declared) || array_filter($declared, static fn ($code): bool => ! is_string($code) || trim($code) === '') !== []) {
            throw new AccountingDimensionUnavailableException('Approved policy dimension declaration is malformed.');
        }

        $codes = array_values(array_unique(array_map(static fn (string $code): string => trim($code), $declared)));
        if (count($codes) !== count($declared)) {
            throw new AccountingDimensionUnavailableException('Approved policy dimension declaration contains duplicate codes.');
        }

        return $codes;
    }
}
