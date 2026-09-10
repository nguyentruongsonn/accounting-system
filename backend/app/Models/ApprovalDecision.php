<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ApprovalDecision extends Model
{
    protected $fillable = ['approval_request_id', 'approval_request_step_id', 'decided_by', 'decision', 'evidence', 'evidence_hash', 'decided_at'];

    protected $casts = ['evidence' => 'array', 'decided_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Approval decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Approval decisions are append-only.'));
    }
}
