<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Non-secret control-plane reference for a provider adapter. This does not
 * establish connectivity, signing authority, issuance, or tax compliance.
 */
class EInvoiceProviderConfiguration extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['company_id', 'provider_code', 'secret_reference', 'endpoint_reference', 'callback_secret_reference', 'effective_from', 'effective_to', 'capability_contract', 'regulatory_dependencies', 'created_by'];

    protected $hidden = ['secret_reference', 'callback_secret_reference'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'capability_contract' => 'array', 'regulatory_dependencies' => 'array', 'approved_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $config): void {
            if ($config->status !== 'draft' || $config->approved_at !== null || $config->approved_by !== null || $config->contract_hash !== null) {
                throw new LogicException('Provider configurations must begin as unsigned drafts.');
            }
            $config->assertSafeDraft();
        });
        static::updating(function (self $config): void {
            if ($config->getOriginal('approved_at') !== null) {
                throw new LogicException('Approved provider configurations are immutable; create a successor draft.');
            }
            if ($config->isDirty(['status', 'approved_at', 'approved_by', 'contract_hash'])) {
                throw new LogicException('Use EInvoiceProviderConfigurationLifecycleService for lifecycle changes.');
            }
            $config->assertSafeDraft();
        });
    }

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

    private function assertSafeDraft(): void
    {
        if (trim((string) $this->provider_code) === '') {
            throw new LogicException('A provider code is required.');
        }
        $from = $this->effective_from?->toDateString() ?? (string) $this->getRawOriginal('effective_from');
        $to = $this->effective_to?->toDateString() ?? (string) $this->getRawOriginal('effective_to');
        if ($from === '' || $to === '' || $from > $to) {
            throw new LogicException('Provider configuration effective dates are invalid.');
        }
        foreach (['secret_reference', 'endpoint_reference', 'callback_secret_reference'] as $field) {
            $value = $this->{$field};
            if ($value !== null && (str_contains((string) $value, "\n") || strlen((string) $value) > 255)) {
                throw new LogicException("{$field} must be an opaque reference, never a credential or payload.");
            }
        }
        if (! is_array($this->capability_contract) || ! is_array($this->regulatory_dependencies)) {
            throw new LogicException('Provider capability and REGULATORY DEPENDENCY declarations are required, including empty arrays when none apply.');
        }
    }
}
