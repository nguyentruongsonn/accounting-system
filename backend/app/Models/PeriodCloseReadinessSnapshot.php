<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable evidence from a close-readiness evaluation.
 *
 * A snapshot is deliberately not a close approval and must never be treated
 * as permission to close a period unless an enforcing close-gate integration
 * is separately implemented and approved.
 */
class PeriodCloseReadinessSnapshot extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'uuid', 'company_id', 'period_id', 'reconciliation_run_id', 'status',
        'eligible_to_close', 'schema_version', 'snapshot_hash', 'snapshot',
        'requested_by', 'evaluated_at',
    ];

    protected $casts = [
        'eligible_to_close' => 'boolean',
        'snapshot' => 'array',
        'evaluated_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Period-close readiness snapshots are immutable; evaluate again.');
        });
        static::deleting(function (): never {
            throw new LogicException('Period-close readiness snapshots are append-only and cannot be deleted.');
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function reconciliationRun(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class);
    }
}
