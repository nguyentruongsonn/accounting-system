<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EInvoiceProviderConfiguration;
use App\Services\AuditService;
use App\Services\EInvoiceProviderAdapterReadinessService;
use App\Services\EInvoiceProviderConfigurationLifecycleService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Control-plane API. It never accepts credential material or invokes a provider. */
class EInvoiceProviderConfigurationController extends Controller
{
    public function __construct(private readonly EInvoiceProviderConfigurationLifecycleService $lifecycle, private readonly EInvoiceProviderAdapterReadinessService $readiness, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => EInvoiceProviderConfiguration::withoutGlobalScope('company')->where('company_id', TenantContext::companyId($request))->orderByDesc('id')->paginate(min((int) $request->input('per_page', 50), 100)), 'secrets_not_exposed' => true]);
    }

    public function readiness(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->readiness->forCompany(TenantContext::companyId($request)), 'transport_not_called' => true, 'legal_compliance_not_asserted' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        $config = $this->lifecycle->createDraft($request->user(), $this->validated($request) + ['company_id' => TenantContext::companyId($request)]);
        $this->audit->record($config, 'einvoice.provider_configuration_draft_created', [], $this->shape($config), null, ['secrets_not_stored' => true, 'transport_not_called' => true]);

        return response()->json(['data' => $config, 'secrets_not_exposed' => true], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $config = $this->config($request, $id);
        if ($config->status !== 'draft') {
            throw new ConflictHttpException('Approved provider configurations are immutable; create a successor draft.');
        }
        $before = $this->shape($config);
        $config = $this->lifecycle->updateDraft($request->user(), $config, $this->validated($request));
        $this->audit->record($config, 'einvoice.provider_configuration_draft_updated', $before, $this->shape($config), null, ['secrets_not_stored' => true]);

        return response()->json(['data' => $config, 'secrets_not_exposed' => true]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $config = $this->lifecycle->approve($request->user(), $this->config($request, $id));
        $this->audit->record($config, 'einvoice.provider_configuration_approved', [], $this->shape($config), null, ['maker_checker_enforced' => true, 'transport_not_called' => true]);

        return response()->json(['data' => $config, 'secrets_not_exposed' => true]);
    }

    public function disable(Request $request, int $id): JsonResponse
    {
        $config = $this->lifecycle->disable($request->user(), $this->config($request, $id));
        $this->audit->record($config, 'einvoice.provider_configuration_disabled', [], $this->shape($config), null, ['maker_checker_enforced' => true]);

        return response()->json(['data' => $config, 'secrets_not_exposed' => true]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate(['provider_code' => ['required', 'string', 'max:80'], 'secret_reference' => ['nullable', 'string', 'max:255', 'not_regex:/\s/'], 'endpoint_reference' => ['nullable', 'string', 'max:255', 'not_regex:/\s/'], 'callback_secret_reference' => ['nullable', 'string', 'max:255', 'not_regex:/\s/'], 'effective_from' => ['required', 'date'], 'effective_to' => ['required', 'date', 'after_or_equal:effective_from'], 'capability_contract' => ['required', 'array'], 'regulatory_dependencies' => ['required', 'array']]);
    }

    private function config(Request $request, int $id): EInvoiceProviderConfiguration
    {
        return EInvoiceProviderConfiguration::withoutGlobalScope('company')->where('company_id', TenantContext::companyId($request))->findOrFail($id);
    }

    /** @return array<string,mixed> */
    private function shape(EInvoiceProviderConfiguration $config): array
    {
        return ['id' => $config->id, 'provider_code' => $config->provider_code, 'status' => $config->status, 'effective_from' => $config->effective_from?->toDateString(), 'effective_to' => $config->effective_to?->toDateString(), 'contract_hash' => $config->contract_hash];
    }
}
