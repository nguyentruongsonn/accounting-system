<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** Tenant-owned selectable value for an explicitly defined analytic dimension. */
class AccountingDimensionValue extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'accounting_dimension_definition_id', 'code', 'name', 'status', 'effective_from', 'effective_to', 'metadata'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'metadata' => 'array'];

    protected static function booted(): void
    {
        static::saving(function (self $value): void {
            $definition = AccountingDimensionDefinition::withoutGlobalScope('company')
                ->find($value->accounting_dimension_definition_id);
            $from = $value->effective_from?->toDateString() ?? (string) $value->getRawOriginal('effective_from');
            $to = $value->effective_to?->toDateString() ?? (string) $value->getRawOriginal('effective_to');
            if ($definition === null || (int) $definition->company_id !== (int) $value->company_id
                || ! in_array($value->status, ['active', 'inactive'], true) || $from === '' || $to === '' || $from > $to
                || $from < $definition->effective_from->toDateString() || $to > $definition->effective_to->toDateString()) {
                throw ValidationException::withMessages(['accounting_dimension_definition_id' => 'Dimension value must belong to its tenant definition and remain within its effective range.']);
            }
        });
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AccountingDimensionDefinition::class, 'accounting_dimension_definition_id');
    }
}
