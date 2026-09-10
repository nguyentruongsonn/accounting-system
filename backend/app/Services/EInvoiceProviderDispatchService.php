<?php

namespace App\Services;

use App\Models\EInvoiceDocument;
use App\Models\EInvoiceProviderConfiguration;
use App\Models\EInvoiceProviderDispatch;
use App\Models\EInvoiceProviderDispatchEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Durable outbox/evidence state machine only. There is deliberately no HTTP,
 * signing, secret resolution, invoice issuance, or tax filing code here.
 */
final class EInvoiceProviderDispatchService
{
    public function __construct(private readonly EInvoiceProviderAdapterReadinessService $readiness, private readonly AuditService $audit) {}

    public function prepare(int $companyId, int $actorId, EInvoiceDocument $document): EInvoiceProviderDispatch
    {
        $companyId = $this->requireCompanyId($companyId);
        return DB::transaction(function () use ($companyId, $actorId, $document): EInvoiceProviderDispatch {
            if ((int) $document->company_id !== $companyId) throw new AuthorizationException('E-invoice evidence belongs to another tenant.');
            if (! in_array($document->lifecycle_status, ['issued', 'replaced', 'adjusted'], true)) throw new LogicException('Only controlled lifecycle evidence may enter the provider outbox.');
            if ((int) $document->recorded_by === $actorId) throw new AuthorizationException('Lifecycle-event recorder cannot prepare its own provider dispatch.');
            if (! $this->payloadHashMatches($document)) throw new LogicException('Provider dispatch is fail-closed: e-invoice evidence payload integrity is invalid.');
            $readiness = $this->readiness->forCompany($companyId, CarbonImmutable::parse($document->occurred_at));
            if (! $readiness['ready']) throw new LogicException('Provider dispatch is fail-closed: '.$readiness['reason']);
            $config = EInvoiceProviderConfiguration::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($readiness['provider_configuration_id']);
            $key = hash('sha256', implode('|', [$companyId, $config->id, $document->id, $document->payload_hash, $config->contract_hash]));
            $existing = EInvoiceProviderDispatch::withoutGlobalScope('company')->where('company_id', $companyId)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) return $existing;
            $dispatch = EInvoiceProviderDispatch::withoutGlobalScope('company')->create(['company_id' => $companyId, 'e_invoice_document_id' => $document->id, 'provider_configuration_id' => $config->id, 'idempotency_key' => $key, 'payload_hash' => $document->payload_hash, 'state' => 'prepared', 'prepared_by' => $actorId]);
            $this->event($dispatch, 'prepared', null, null, null, $actorId, ['transport_not_called' => true]);
            $this->audit->record($dispatch, 'einvoice.provider_dispatch_prepared', [], ['state' => 'prepared', 'idempotency_key' => $key, 'payload_hash' => $document->payload_hash], null, ['transport_not_called' => true, 'legal_compliance_not_asserted' => true]);
            return $dispatch;
        });
    }

    /** Record a sanitized asynchronous provider outcome; caller supplies no secret or raw payload. */
    public function recordOutcome(int $companyId, int $actorId, int $dispatchId, string $outcome, ?string $resultCode = null, ?string $resultPayloadHash = null, ?CarbonImmutable $nextRetryAt = null): EInvoiceProviderDispatch
    {
        $companyId = $this->requireCompanyId($companyId);
        return DB::transaction(function () use ($companyId, $actorId, $dispatchId, $outcome, $resultCode, $resultPayloadHash, $nextRetryAt): EInvoiceProviderDispatch {
            $dispatch = EInvoiceProviderDispatch::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($dispatchId);
            if ((int) $dispatch->company_id !== $companyId) throw new AuthorizationException('Provider dispatch belongs to another tenant.');
            if (! in_array($dispatch->state, ['prepared', 'retry_pending'], true)) throw new LogicException('A final provider dispatch cannot receive another outcome.');
            if (! in_array($outcome, ['retryable_failure', 'acknowledged', 'terminal_failure'], true)) throw new LogicException('Unsupported provider outcome.');
            if ($outcome === 'acknowledged' && (int) $dispatch->prepared_by === $actorId) throw new AuthorizationException('Dispatch preparer cannot attest its own provider acknowledgement.');
            if ($resultPayloadHash !== null && ! preg_match('/^[a-f0-9]{64}$/D', $resultPayloadHash)) throw new LogicException('Provider outcome payload hash must be a SHA-256 hex digest.');
            if ($resultCode !== null && strlen($resultCode) > 120) throw new LogicException('Provider outcome code exceeds the evidence field limit.');
            $attempt = $dispatch->attempt_count + 1;
            $state = $outcome === 'acknowledged' ? 'acknowledged' : ($outcome === 'terminal_failure' ? 'terminal_failed' : 'retry_pending');
            if ($state === 'retry_pending' && $nextRetryAt === null) throw new LogicException('Retryable outcome requires a bounded next retry timestamp.');
            $now = now();
            EInvoiceProviderDispatch::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($dispatch->id)->update(['state' => $state, 'attempt_count' => $attempt, 'next_retry_at' => $state === 'retry_pending' ? $nextRetryAt : null, 'acknowledged_at' => $state === 'acknowledged' ? $now : null, 'terminal_at' => $state === 'terminal_failed' ? $now : null, 'updated_at' => $now]);
            $updated = $dispatch->fresh();
            $this->event($updated, 'attempt_recorded', $attempt, $resultCode, $resultPayloadHash, $actorId, ['outcome' => $outcome]);
            $this->event($updated, $state === 'retry_pending' ? 'retry_scheduled' : $state, $attempt, $resultCode, $resultPayloadHash, $actorId, $state === 'retry_pending' ? ['next_retry_at' => $nextRetryAt?->toIso8601String()] : []);
            $this->audit->record($updated, 'einvoice.provider_dispatch_outcome_recorded', [], ['state' => $state, 'attempt_count' => $attempt, 'provider_result_code' => $resultCode, 'result_payload_hash' => $resultPayloadHash], null, ['raw_provider_payload_not_stored' => true, 'transport_not_called' => true]);
            return $updated;
        });
    }

    /** @param array<string,mixed> $metadata */
    private function event(EInvoiceProviderDispatch $dispatch, string $type, ?int $attempt, ?string $code, ?string $hash, ?int $actorId, array $metadata): void
    {
        EInvoiceProviderDispatchEvent::withoutGlobalScope('company')->create(['company_id' => $dispatch->company_id, 'e_invoice_provider_dispatch_id' => $dispatch->id, 'event_type' => $type, 'attempt_number' => $attempt, 'provider_result_code' => $code, 'result_payload_hash' => $hash, 'safe_metadata' => $metadata, 'recorded_by' => $actorId, 'occurred_at' => now()]);
    }

    private function payloadHashMatches(EInvoiceDocument $document): bool
    {
        try {
            $expected = hash('sha256', json_encode($document->payload_snapshot ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return false;
        }

        return is_string($document->payload_hash)
            && preg_match('/^[a-f0-9]{64}$/D', $document->payload_hash) === 1
            && hash_equals($document->payload_hash, $expected);
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
