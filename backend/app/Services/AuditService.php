<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditService
{
    /** @param array<string, mixed> $metadata */
    public function recordMutation(
        Model $model,
        string $action,
        array $before,
        array $after,
        ?string $reason = null,
        array $metadata = []
    ): AuditLog {
        if ($reason !== null && trim($reason) !== '') {
            $metadata['reason'] = $reason;
        }

        return $this->record($model, $action, $before, $after, metadata: $metadata);
    }

    public function record(
        Model $model,
        string $action,
        array $before = [],
        array $after = [],
        ?string $correlationId = null,
        array $metadata = []
    ): AuditLog {
        $companyId = $model->getAttribute('company_id') ?? auth()->user()?->company_id;
        $actorCompanyId = auth()->user()?->company_id;
        if ($actorCompanyId !== null && (int) $actorCompanyId !== (int) $companyId) {
            throw new AuthorizationException('Audit evidence cannot be recorded for another tenant.');
        }
        $requestCorrelationId = request()?->attributes->get('correlation_id') ?? request()?->header('X-Request-ID');
        $resolvedCorrelationId = $correlationId
            ?? (is_string($requestCorrelationId) && $requestCorrelationId !== '' && mb_strlen($requestCorrelationId) <= 100
                ? $requestCorrelationId
                : (string) Str::uuid());

        return AuditLog::withoutGlobalScope('company')->create([
            'company_id' => $companyId,
            'correlation_id' => $resolvedCorrelationId,
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
            'old_values' => $this->safePayload($before),
            'new_values' => $this->safePayload($after),
            'metadata' => $this->safePayload($metadata),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function safePayload(array $payload): array
    {
        // Posting-control metadata contains a structured `authorization`
        // object. Redact credentials and scalar authorization headers without
        // dropping safe evidence such as `authorization_model` or the actor
        // role/permission recorded inside that object.
        $blocked = '/(^|_)(password|token|secret|api[_-]?key|private[_-]?key)(_|$)/i';
        $safe = [];
        foreach ($payload as $key => $value) {
            $keyString = (string) $key;
            if (preg_match($blocked, $keyString)
                || (preg_match('/^authorization(?:_|$)/i', $keyString)
                    && ! preg_match('/^authorization_model$/i', $keyString)
                    && ! is_array($value))) {
                continue;
            }
            $safe[$key] = is_array($value) ? $this->safePayload($value) : $value;
        }

        return $safe;
    }
}
