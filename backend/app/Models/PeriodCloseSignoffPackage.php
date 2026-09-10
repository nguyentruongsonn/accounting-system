<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PeriodCloseSignoffPackage extends Model
{
    use BelongsToCompany;

    protected $fillable = ['uuid', 'company_id', 'period_id', 'period_close_readiness_snapshot_id', 'period_close_signoff_policy_id', 'readiness_snapshot_hash', 'evidence_cutoff_at', 'policy_snapshot', 'package_snapshot', 'package_hash', 'prepared_by', 'prepared_at'];

    protected $casts = ['evidence_cutoff_at' => 'immutable_datetime', 'policy_snapshot' => 'array', 'package_snapshot' => 'array', 'prepared_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Period-close signoff packages are immutable; create a replacement package.'));
        static::deleting(fn (): never => throw new LogicException('Period-close signoff packages are append-only and cannot be deleted.'));
    }

    public function readinessSnapshot(): BelongsTo
    {
        return $this->belongsTo(PeriodCloseReadinessSnapshot::class, 'period_close_readiness_snapshot_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PeriodCloseSignoffPolicy::class, 'period_close_signoff_policy_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PeriodCloseSignoffEvent::class)->orderBy('id');
    }
}
