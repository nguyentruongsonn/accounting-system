<?php

namespace App\Services;

use App\Models\EInvoiceProviderConfiguration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class EInvoiceProviderConfigurationLifecycleService
{
    /** @param array<string,mixed> $attributes */
    public function createDraft(User $actor, array $attributes): EInvoiceProviderConfiguration
    {
        if ((int) ($attributes['company_id'] ?? 0) !== (int) $actor->company_id) throw new AuthorizationException('Cannot configure a provider for another tenant.');
        $attributes['created_by'] = $actor->id;
        return EInvoiceProviderConfiguration::create($attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function updateDraft(User $actor, EInvoiceProviderConfiguration $config, array $attributes): EInvoiceProviderConfiguration
    {
        $this->assertTenant($actor, $config);
        if ($config->status !== 'draft' || $config->approved_at !== null) throw new LogicException('Only unsigned provider configuration drafts may be edited.');
        $config->fill($attributes)->save();
        return $config->fresh();
    }

    public function approve(User $actor, EInvoiceProviderConfiguration $config, ?CarbonImmutable $at = null): EInvoiceProviderConfiguration
    {
        $this->assertTenant($actor, $config);
        if ($config->status !== 'draft' || $config->approved_at !== null) throw new LogicException('Only an unsigned provider configuration draft may be approved.');
        if ((int) $config->created_by === (int) $actor->id) throw new AuthorizationException('Provider configuration maker cannot approve their own draft.');
        if (blank($config->secret_reference) || blank($config->endpoint_reference) || blank($config->callback_secret_reference)) {
            throw new LogicException('An approved provider configuration requires opaque secret, endpoint, and callback-secret references; secrets are never stored here.');
        }
        $contract = $this->contract($config);
        EInvoiceProviderConfiguration::withoutGlobalScope('company')->where('company_id', $actor->company_id)->whereKey($config->id)->where('status', 'draft')->whereNull('approved_at')->update([
            'status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => $at ?? now(),
            'contract_hash' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)), 'updated_at' => now(),
        ]);
        return $config->fresh();
    }

    /**
     * Recompute the approved configuration contract without exposing any
     * secret or endpoint value.  Readiness uses this to detect a raw database
     * mutation after approval; an immutable Eloquent model guard is not enough
     * protection for a release-critical provider control plane.
     */
    public function contractHashMatches(EInvoiceProviderConfiguration $config): bool
    {
        if ($config->status !== 'approved'
            || $config->approved_at === null
            || $config->approved_by === null
            || blank($config->secret_reference)
            || blank($config->endpoint_reference)
            || blank($config->callback_secret_reference)
            || ! is_array($config->capability_contract)
            || ! is_array($config->regulatory_dependencies)
            || ! is_string($config->contract_hash)
            || ! preg_match('/^[a-f0-9]{64}$/D', $config->contract_hash)) {
            return false;
        }

        try {
            return hash_equals($config->contract_hash, hash('sha256', json_encode($this->contract($config), JSON_THROW_ON_ERROR)));
        } catch (\JsonException) {
            return false;
        }
    }

    public function disable(User $actor, EInvoiceProviderConfiguration $config): EInvoiceProviderConfiguration
    {
        $this->assertTenant($actor, $config);
        if ($config->status !== 'approved' || $config->approved_at === null) throw new LogicException('Only an approved provider configuration may be disabled.');
        if ((int) $config->created_by === (int) $actor->id) throw new AuthorizationException('Provider configuration maker cannot disable the active configuration alone.');
        EInvoiceProviderConfiguration::withoutGlobalScope('company')->where('company_id', $actor->company_id)->whereKey($config->id)->where('status', 'approved')->update(['status' => 'disabled', 'updated_at' => now()]);
        return $config->fresh();
    }

    /** @return array<string,mixed> */
    private function contract(EInvoiceProviderConfiguration $config): array
    {
        return ['provider_code' => $config->provider_code, 'secret_reference_hash' => hash('sha256', (string) $config->secret_reference), 'endpoint_reference_hash' => hash('sha256', (string) $config->endpoint_reference), 'callback_secret_reference_hash' => hash('sha256', (string) $config->callback_secret_reference), 'effective_from' => $config->effective_from->toDateString(), 'effective_to' => $config->effective_to->toDateString(), 'capability_contract' => EInvoiceProviderConfiguration::canonicalize($config->capability_contract), 'regulatory_dependencies' => EInvoiceProviderConfiguration::canonicalize($config->regulatory_dependencies)];
    }

    private function assertTenant(User $actor, EInvoiceProviderConfiguration $config): void
    {
        if ((int) $actor->company_id !== (int) $config->company_id) throw new AuthorizationException('Provider configuration belongs to another tenant.');
    }
}
