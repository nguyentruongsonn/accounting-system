<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** Explicit link between an accounting policy and a tenant-defined dimension. */
class AccountingPolicyDimensionRequirement extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'accounting_policy_version_id', 'accounting_dimension_definition_id', 'is_required', 'effective_from', 'effective_to'];

    protected $casts = ['is_required' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];

    protected static function booted(): void
    {
        static::saving(function (self $requirement): void {
            $policy = AccountingPolicyVersion::withoutGlobalScope('company')->find($requirement->accounting_policy_version_id);
            $definition = AccountingDimensionDefinition::withoutGlobalScope('company')->find($requirement->accounting_dimension_definition_id);
            $from = $requirement->effective_from?->toDateString() ?? (string) $requirement->getRawOriginal('effective_from');
            $to = $requirement->effective_to?->toDateString() ?? (string) $requirement->getRawOriginal('effective_to');
            if ($policy === null || $policy->status !== 'draft' || $policy->approved_at !== null || $definition === null || (int) $policy->company_id !== (int) $requirement->company_id
                || (int) $definition->company_id !== (int) $requirement->company_id || $from === '' || $to === '' || $from > $to
                || $from < $policy->effective_from->toDateString() || $to > $policy->effective_to->toDateString()) {
                throw ValidationException::withMessages(['accounting_policy_version_id' => 'Policy dimension requirement must remain tenant-bound and inside the policy effective range.']);
            }
        });

        static::deleting(function (self $requirement): void {
            $policy = AccountingPolicyVersion::withoutGlobalScope('company')->find($requirement->accounting_policy_version_id);
            if ($policy !== null && ($policy->status !== 'draft' || $policy->approved_at !== null)) {
                throw new \LogicException('Approved policy dimension requirements are immutable.');
            }
        });
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AccountingPolicyVersion::class, 'accounting_policy_version_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AccountingDimensionDefinition::class, 'accounting_dimension_definition_id');
    }
}
