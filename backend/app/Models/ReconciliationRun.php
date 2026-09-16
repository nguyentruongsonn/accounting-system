<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ReconciliationRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'uuid', 'company_id', 'period_id', 'basis', 'status', 'idempotency_key',
        'request_hash', 'algorithm_version', 'input_cutoff_at', 'started_at',
        'completed_at', 'requested_by', 'posted_entry_count', 'posted_line_count',
        'total_debit', 'total_credit', 'result_count', 'failed_result_count',
        'warning_result_count', 'not_available_result_count', 'snapshot_hash', 'snapshot',
    ];

    protected $casts = [
        'input_cutoff_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'posted_entry_count' => 'integer',
        'posted_line_count' => 'integer',
        'result_count' => 'integer',
        'failed_result_count' => 'integer',
        'warning_result_count' => 'integer',
        'not_available_result_count' => 'integer',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Reconciliation runs are immutable; execute a new shadow run.');
        });

        static::deleting(function (): never {
            throw new LogicException('Reconciliation runs are append-only and cannot be deleted.');
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ReconciliationCheckResult::class);
    }
}
