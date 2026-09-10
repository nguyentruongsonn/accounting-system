<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * One owner-approved account selection for a posting role and context.
 *
 * This record intentionally does not encode, infer, or certify TT99 account
 * mappings. The tenant owner supplies the mapping and its dependencies.
 */
class ApprovedAccountMapping extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'company_id', 'accounting_policy_version_id', 'mapping_key',
        'mapping_context', 'account_role', 'account_code', 'effective_from',
        'effective_to', 'regulatory_dependencies', 'created_by',
    ];

    protected $casts = [
        'mapping_context' => 'array',
        'regulatory_dependencies' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'approved_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $mapping): void {
            if ($mapping->status !== 'draft' || $mapping->approved_by !== null
                || $mapping->approved_at !== null || $mapping->contract_hash !== null) {
                throw new LogicException('Account mappings must be created as unsigned drafts.');
            }
            $mapping->prepareAndAssertReferences();
        });

        static::updating(function (self $mapping): void {
            if ($mapping->getOriginal('approved_at') !== null) {
                throw new LogicException('Approved account mappings are immutable; create a successor draft.');
            }
            if ($mapping->isDirty(['status', 'approved_by', 'approved_at', 'contract_hash', 'context_hash'])) {
                throw new LogicException('Use ApprovedAccountMappingLifecycleService to approve an account mapping.');
            }
            $mapping->prepareAndAssertReferences();
        });

        static::deleting(function (self $mapping): void {
            if ($mapping->approved_at !== null) {
                throw new LogicException('Approved account mappings cannot be deleted.');
            }
        });
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AccountingPolicyVersion::class, 'accounting_policy_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @param array<string,mixed> $context */
    public static function contextHash(array $context): string
    {
        return hash('sha256', json_encode(self::canonicalize($context), JSON_THROW_ON_ERROR));
    }

    /** @param mixed $value @return mixed */
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => self::canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private function prepareAndAssertReferences(): void
    {
        if (! is_array($this->mapping_context)) {
            throw ValidationException::withMessages(['mapping_context' => 'Mapping context must be explicitly declared, including an empty object.']);
        }
        $this->mapping_context = self::canonicalize($this->mapping_context);
        $this->context_hash = self::contextHash($this->mapping_context);
        if (! is_array($this->regulatory_dependencies)) {
            throw ValidationException::withMessages(['regulatory_dependencies' => 'REGULATORY DEPENDENCY records must be explicitly declared, including an empty list.']);
        }
        if (trim((string) $this->mapping_key) === '' || trim((string) $this->account_role) === '' || trim((string) $this->account_code) === '') {
            throw ValidationException::withMessages(['mapping_key' => 'Mapping key, account role, and account code are required.']);
        }
        $from = $this->effective_from?->toDateString() ?? (string) $this->getRawOriginal('effective_from');
        $to = $this->effective_to?->toDateString() ?? (string) $this->getRawOriginal('effective_to');
        if ($from === '' || $to === '' || $from > $to) {
            throw ValidationException::withMessages(['effective_from' => 'Mapping effective dates are invalid.']);
        }

        $policy = AccountingPolicyVersion::withoutGlobalScope('company')->find($this->accounting_policy_version_id);
        if ($policy === null || (int) $policy->company_id !== (int) $this->company_id) {
            throw ValidationException::withMessages(['accounting_policy_version_id' => 'The accounting policy must belong to the current tenant.']);
        }
        if ($from < $policy->effective_from->toDateString() || $to > $policy->effective_to->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => 'Mapping effective dates must stay inside the linked accounting policy period.']);
        }
    }
}
