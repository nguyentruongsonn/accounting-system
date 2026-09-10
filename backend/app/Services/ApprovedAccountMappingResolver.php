<?php

namespace App\Services;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Models\AccountingPolicyVersion;
use App\Models\ApprovedAccountMapping;
use App\Models\ChartOfAccount;

/** Resolves only explicitly approved and unambiguous owner mappings. */
final class ApprovedAccountMappingResolver
{
    /** @param array<string,mixed> $context @param list<string> $roles @return array<string,array{mapping_id:int,account_code:string,contract_hash:string}> */
    public function require(int $companyId, int $policyId, string $postingDate, string $mappingKey, array $context, array $roles): array
    {
        $policy = AccountingPolicyVersion::withoutGlobalScope('company')->whereKey($policyId)->where('company_id', $companyId)->where('status', 'approved')->whereNotNull('approved_at')->whereDate('effective_from', '<=', $postingDate)->whereDate('effective_to', '>=', $postingDate)->first();
        if ($policy === null || $policy->contract_hash === null) throw new AccountingAccountMappingUnavailableException('No effective approved accounting policy is available for account mapping resolution.');
        $context = ApprovedAccountMapping::canonicalize($context);
        $contextHash = ApprovedAccountMapping::contextHash($context);
        $resolved = [];
        foreach (array_values(array_unique($roles)) as $role) {
            $matches = ApprovedAccountMapping::withoutGlobalScope('company')
                ->where('company_id', $companyId)->where('accounting_policy_version_id', $policy->id)
                ->where('mapping_key', $mappingKey)->where('context_hash', $contextHash)->where('account_role', $role)
                ->where('status', 'approved')->whereNotNull('approved_at')->whereNotNull('contract_hash')
                ->whereDate('effective_from', '<=', $postingDate)->whereDate('effective_to', '>=', $postingDate)->get();
            if ($matches->count() !== 1) throw new AccountingAccountMappingUnavailableException("Account mapping [{$mappingKey}/{$role}] must resolve to exactly one approved mapping; found {$matches->count()}.");
            $mapping = $matches->sole();
            if (! hash_equals($mapping->contract_hash, hash('sha256', json_encode([
                'accounting_policy_version_id' => (int) $policy->id, 'policy_key' => $policy->policy_key, 'policy_contract_hash' => $policy->contract_hash,
                'mapping_key' => $mapping->mapping_key, 'mapping_context' => ApprovedAccountMapping::canonicalize($mapping->mapping_context),
                'account_role' => $mapping->account_role, 'account_code' => $mapping->account_code,
                'effective_from' => $mapping->effective_from->toDateString(), 'effective_to' => $mapping->effective_to->toDateString(), 'regulatory_dependencies' => $mapping->regulatory_dependencies,
            ], JSON_THROW_ON_ERROR)))) throw new AccountingAccountMappingUnavailableException('Approved account mapping integrity check failed.');
            $account = ChartOfAccount::withoutGlobalScope('company')->where('company_id', $companyId)->where('code', $mapping->account_code)->where('is_active', true)->where('is_parent', false)->first();
            if ($account === null) throw new AccountingAccountMappingUnavailableException("Approved account mapping [{$mappingKey}/{$role}] refers to an inactive or missing tenant account.");
            $resolved[$role] = ['mapping_id' => (int) $mapping->id, 'account_code' => $mapping->account_code, 'contract_hash' => $mapping->contract_hash];
        }
        return $resolved;
    }
}
