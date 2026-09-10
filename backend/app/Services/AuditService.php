<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditService
{
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
            'old_values' => $before,
            'new_values' => $after,
            'metadata' => $metadata,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
