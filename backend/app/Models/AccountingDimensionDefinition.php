<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/** A tenant-defined analytic dimension; its accounting meaning is never inferred. */
class AccountingDimensionDefinition extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name', 'status', 'effective_from', 'effective_to', 'metadata'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'metadata' => 'array'];

    protected static function booted(): void
    {
        static::saving(function (self $definition): void {
            $from = $definition->effective_from?->toDateString() ?? (string) $definition->getRawOriginal('effective_from');
            $to = $definition->effective_to?->toDateString() ?? (string) $definition->getRawOriginal('effective_to');
            if (! in_array($definition->status, ['active', 'inactive'], true) || $from === '' || $to === '' || $from > $to) {
                throw ValidationException::withMessages(['effective_from' => 'Dimension definition requires a valid status and effective date range.']);
            }
        });
    }

    public function values(): HasMany
    {
        return $this->hasMany(AccountingDimensionValue::class);
    }

    public function policyRequirements(): HasMany
    {
        return $this->hasMany(AccountingPolicyDimensionRequirement::class);
    }
}
