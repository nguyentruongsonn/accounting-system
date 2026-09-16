<?php

namespace App\Services;

use App\Models\EInvoiceProviderConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Readiness only. It never connects to a provider or resolves a secret. */
final class EInvoiceProviderAdapterReadinessService
{
    public function __construct(private readonly EInvoiceProviderConfigurationLifecycleService $configLifecycle) {}

    /** @return array<string,mixed> */
    public function forCompany(int $companyId, ?CarbonImmutable $at = null): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $at ??= CarbonImmutable::now();
        $configs = EInvoiceProviderConfiguration::withoutGlobalScope('company')->where('company_id', $companyId)->where('status', 'approved')->whereDate('effective_from', '<=', $at->toDateString())->whereDate('effective_to', '>=', $at->toDateString())->orderBy('id')->get();
        if ($configs->count() !== 1) return ['ready' => false, 'reason' => $configs->isEmpty() ? 'approved_provider_configuration_missing' : 'approved_provider_configuration_ambiguous', 'provider_configuration_id' => null, 'transport_enabled' => false];
        $config = $configs->first();
        if (! $this->configLifecycle->contractHashMatches($config)) {
            return ['ready' => false, 'reason' => 'approved_provider_configuration_integrity_invalid', 'provider_configuration_id' => null, 'transport_enabled' => false];
        }
        if (! config('accounting.enable_einvoice_provider_adapter', false)) return ['ready' => false, 'reason' => 'provider_adapter_transport_disabled', 'provider_configuration_id' => $config->id, 'transport_enabled' => false, 'contract_hash' => $config->contract_hash];
        return ['ready' => true, 'reason' => null, 'provider_configuration_id' => $config->id, 'transport_enabled' => true, 'contract_hash' => $config->contract_hash];
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }
}
