<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A tenant-owned, effective-dated accounting policy contract.
 *
 * This is a control-plane record, not an assertion that its contents satisfy
 * TT99, tax law, e-invoice law, or any other regulation. Those sources must
 * be captured as REGULATORY DEPENDENCY records and approved by the tenant.
 */
class AccountingPolicyVersion extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'company_id',
        'created_by',
        'accounting_regime_profile_id',
        'policy_key',
        'policy_version',
        'effective_from',
        'effective_to',
        'posting_rule_contract',
        'required_dimensions',
        'regulatory_dependencies',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'posting_rule_contract' => 'array',
        'required_dimensions' => 'array',
        'regulatory_dependencies' => 'array',
        'approved_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $policy): void {
            if ($policy->status !== 'draft' || $policy->approved_by !== null
                || $policy->approved_at !== null || $policy->contract_hash !== null) {
                throw new LogicException('Accounting policies must be created as unsigned drafts.');
            }

            $policy->assertProfileAndEffectiveRange();
        });

        static::updating(function (self $policy): void {
            if ($policy->getOriginal('approved_at') !== null) {
                throw new LogicException('Approved accounting policies are immutable; create a successor draft.');
            }

            if ($policy->isDirty(['status', 'approved_by', 'approved_at', 'contract_hash'])) {
                throw new LogicException('Use AccountingPolicyLifecycleService to approve an accounting policy.');
            }

            $policy->assertProfileAndEffectiveRange();
        });

        static::deleting(function (self $policy): void {
            if ($policy->approved_at !== null) {
                throw new LogicException('Approved accounting policies cannot be deleted.');
            }
        });
    }

    public function accountingRegimeProfile(): BelongsTo
    {
        return $this->belongsTo(AccountingRegimeProfile::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dimensionRequirements(): HasMany
    {
        return $this->hasMany(AccountingPolicyDimensionRequirement::class);
    }

    private function assertProfileAndEffectiveRange(): void
    {
        $profile = AccountingRegimeProfile::withoutGlobalScope('company')
            ->where('company_id', $this->company_id)
            ->find($this->accounting_regime_profile_id);

        if ($profile === null) {
            throw ValidationException::withMessages([
                'accounting_regime_profile_id' => 'Hồ sơ chế độ kế toán phải thuộc doanh nghiệp hiện tại.',
            ]);
        }

        $from = $this->effective_from?->toDateString() ?? (string) $this->getRawOriginal('effective_from');
        $to = $this->effective_to?->toDateString() ?? (string) $this->getRawOriginal('effective_to');
        if ($from === '' || $to === '' || $from > $to
            || $from < $profile->effective_from->toDateString()
            || $to > $profile->effective_to->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => 'Hiệu lực policy phải nằm hoàn toàn trong hiệu lực hồ sơ chế độ kế toán.',
            ]);
        }
    }
}
