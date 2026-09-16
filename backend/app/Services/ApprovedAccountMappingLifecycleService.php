<?php

namespace App\Services;

use App\Models\AccountingPolicyVersion;
use App\Models\ApprovedAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Lifecycle for tenant-owned account-mapping evidence, not a statutory map. */
final class ApprovedAccountMappingLifecycleService
{
    /** @param array<string,mixed> $attributes */
    public function createDraft(User $actor, array $attributes): ApprovedAccountMapping
    {
        $companyId = $this->companyId($actor);
        if (isset($attributes['company_id']) && (int) $attributes['company_id'] !== $companyId) {
            throw new AuthorizationException('Cannot create an account mapping for another tenant.');
        }
        $attributes['company_id'] = $companyId;
        $attributes['created_by'] = $actor->id;
        $attributes['status'] = 'draft';
        return ApprovedAccountMapping::withoutGlobalScope('company')->create($attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function updateDraft(User $actor, ApprovedAccountMapping $mapping, array $attributes): ApprovedAccountMapping
    {
        $this->assertOwner($actor, $mapping);
        if ($mapping->status !== 'draft' || $mapping->approved_at !== null) throw new LogicException('Only an unsigned account-mapping draft may be edited.');
        unset($attributes['company_id'], $attributes['created_by'], $attributes['status'], $attributes['approved_by'], $attributes['approved_at'], $attributes['contract_hash'], $attributes['context_hash']);
        $mapping->fill($attributes)->save();
        return $mapping->refresh();
    }

    public function approve(User $actor, ApprovedAccountMapping $mapping, ?CarbonImmutable $at = null): ApprovedAccountMapping
    {
        $this->assertOwner($actor, $mapping);
        $at ??= CarbonImmutable::now();
        return DB::transaction(function () use ($actor, $mapping, $at): ApprovedAccountMapping {
            $companyId = $this->companyId($actor);
            // Scope before locking so a direct caller cannot select a foreign
            // mapping into the approval transaction.
            $locked = ApprovedAccountMapping::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($mapping->id);
            $this->assertOwner($actor, $locked);
            if ($locked->status !== 'draft' || $locked->approved_at !== null) throw new LogicException('Only an unsigned account-mapping draft may be approved.');
            if ((int) $locked->created_by === (int) $actor->id) throw new AuthorizationException('The mapping maker cannot approve their own mapping.');
            $policy = AccountingPolicyVersion::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->find($locked->accounting_policy_version_id);
            if ($policy === null || $policy->status !== 'approved' || $policy->approved_at === null || $policy->contract_hash === null) throw new LogicException('An account mapping requires an approved, integrity-checked accounting policy.');
            $this->assertAccountExistsAndActive($locked);
            $overlap = ApprovedAccountMapping::withoutGlobalScope('company')
                ->where('company_id', $locked->company_id)->lockForUpdate()
                ->where('mapping_key', $locked->mapping_key)
                ->where('context_hash', $locked->context_hash)->where('account_role', $locked->account_role)
                ->where('status', 'approved')->whereDate('effective_from', '<=', $locked->effective_to)
                ->whereDate('effective_to', '>=', $locked->effective_from)->exists();
            if ($overlap) throw ValidationException::withMessages(['effective_from' => 'Approved account mappings must not overlap for the same tenant, key, context, and role.']);
            $contract = $this->contract($locked, $policy);
            ApprovedAccountMapping::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($locked->id)->update([
                'status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => $at,
                'contract_hash' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)), 'updated_at' => $at,
            ]);
            return ApprovedAccountMapping::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($locked->id);
        });
    }

    /** @return array<string,mixed> */
    public function contract(ApprovedAccountMapping $mapping, AccountingPolicyVersion $policy): array
    {
        return [
            'accounting_policy_version_id' => (int) $policy->id,
            'policy_key' => $policy->policy_key, 'policy_contract_hash' => $policy->contract_hash,
            'mapping_key' => $mapping->mapping_key, 'mapping_context' => ApprovedAccountMapping::canonicalize($mapping->mapping_context),
            'account_role' => $mapping->account_role, 'account_code' => $mapping->account_code,
            'effective_from' => $mapping->effective_from->toDateString(), 'effective_to' => $mapping->effective_to->toDateString(),
            'regulatory_dependencies' => $mapping->regulatory_dependencies,
        ];
    }

    private function assertAccountExistsAndActive(ApprovedAccountMapping $mapping): void
    {
        $account = ChartOfAccount::withoutGlobalScope('company')->where('company_id', $mapping->company_id)->where('code', $mapping->account_code)->where('is_active', true)->first();
        if ($account === null) throw ValidationException::withMessages(['account_code' => 'The approved account code must be an active chart-of-account entry in the same tenant.']);
    }
    private function companyId(User $actor): int { if ($actor->company_id === null) throw new AuthorizationException('The acting user is not assigned to a company.'); return (int) $actor->company_id; }
    private function assertOwner(User $actor, ApprovedAccountMapping $mapping): void { if ($this->companyId($actor) !== (int) $mapping->company_id) throw new AuthorizationException('The acting user cannot manage another tenant\'s account mappings.'); }
}
