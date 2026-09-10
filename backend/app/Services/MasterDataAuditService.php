<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Makes master-data changes and their audit evidence one atomic operation.
 *
 * Keeping this at the application boundary is deliberate: a model observer
 * alone cannot roll back an already-issued UPDATE when writing its audit row
 * fails. Controllers call this service for user-initiated master changes.
 */
class MasterDataAuditService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function create(Model $model, array $attributes, string $action = 'master_data.created'): Model
    {
        return DB::transaction(function () use ($model, $attributes, $action) {
            $model->fill($attributes);
            $model->save();

            $this->record($model, $action, [], $this->snapshot($model));

            return $model->fresh();
        });
    }

    public function update(Model $model, array $attributes, string $action = 'master_data.updated'): Model
    {
        return DB::transaction(function () use ($model, $attributes, $action) {
            $before = $this->snapshot($model);
            $model->fill($attributes);
            $model->save();

            $this->record($model, $action, $before, $this->snapshot($model));

            return $model->fresh();
        });
    }

    public function delete(Model $model, string $action = 'master_data.deleted'): void
    {
        DB::transaction(function () use ($model, $action): void {
            $before = $this->snapshot($model);
            $model->delete();

            $this->record($model, $action, $before, []);
        });
    }

    private function record(Model $model, string $action, array $before, array $after): void
    {
        $correlationId = request()?->attributes->get('correlation_id')
            ?? request()?->header('X-Request-ID');

        $safeCorrelationId = is_string($correlationId) && $correlationId !== '' && mb_strlen($correlationId) <= 100
            ? $correlationId
            : null;

        $this->auditService->record(
            $model,
            $action,
            $before,
            $after,
            $safeCorrelationId,
            [
                'domain' => 'master_data',
                'operation' => $action,
                'request_id' => $safeCorrelationId,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $model): array
    {
        return Arr::except($model->getAttributes(), ['password', 'remember_token']);
    }
}
