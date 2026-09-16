<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PeriodCloseSignoffEvent extends Model
{
    protected $fillable = ['period_close_signoff_package_id', 'event_type', 'approval_request_id', 'evidence', 'event_hash', 'recorded_by', 'recorded_at'];

    protected $casts = ['evidence' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Period-close signoff events are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Period-close signoff events are append-only and cannot be deleted.'));
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PeriodCloseSignoffPackage::class, 'period_close_signoff_package_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }
}
