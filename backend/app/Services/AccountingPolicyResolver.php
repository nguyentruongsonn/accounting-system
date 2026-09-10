<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\AccountingPolicyVersion;

/**
 * Resolves the only posting contract that is allowed to govern a transaction.
 *
 * Callers must invoke this before deriving accounts or creating a journal
 * entry. Missing, draft, expired, or overlapping approval is intentionally a
 * hard failure: the system must not silently use enum defaults as policy.
 */
class AccountingPolicyResolver
{
    /** @return array<string, mixed> */
    public function requireForVoucher(int $companyId, string $postingDate, SystemVoucherType|string $voucherType): array
    {
        $resolved = $voucherType instanceof SystemVoucherType
            ? $voucherType
            : SystemVoucherType::resolveType($voucherType);

        if ($resolved === null) {
            throw new AccountingPolicyUnavailableException('Posting policy cannot be resolved for an unknown voucher type.');
        }

        return $this->require($companyId, $postingDate, 'posting.'.$resolved->value);
    }

    /** @return array<string, mixed> */
    public function require(int $companyId, string $postingDate, string $policyKey): array
    {
        $matches = AccountingPolicyVersion::withoutGlobalScope('company')
            ->with('accountingRegimeProfile')
            ->where('company_id', $companyId)
            ->where('policy_key', $policyKey)
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->whereDate('effective_from', '<=', $postingDate)
            ->whereDate('effective_to', '>=', $postingDate)
            ->orderBy('id')
            ->get();

        if ($matches->count() !== 1) {
            throw new AccountingPolicyUnavailableException(
                "Posting policy [{$policyKey}] for {$postingDate} must resolve to exactly one approved tenant policy; found {$matches->count()}."
            );
        }

        $policy = $matches->sole();
        if (($policy->posting_rule_contract ?? []) === []
            || $policy->required_dimensions === null
            || $policy->regulatory_dependencies === null
            || $policy->contract_hash === null) {
            throw new AccountingPolicyUnavailableException('Approved accounting policy is incomplete or has no integrity hash.');
        }

        return [
            'policy_id' => (int) $policy->id,
            'policy_key' => $policy->policy_key,
            'policy_version' => $policy->policy_version,
            'accounting_regime' => $policy->accountingRegimeProfile->regime->value,
            'effective_from' => $policy->effective_from->toDateString(),
            'effective_to' => $policy->effective_to->toDateString(),
            'posting_rule_contract' => $policy->posting_rule_contract,
            'required_dimensions' => $policy->required_dimensions,
            'regulatory_dependencies' => $policy->regulatory_dependencies,
            'contract_hash' => $policy->contract_hash,
        ];
    }
}
