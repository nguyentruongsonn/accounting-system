<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Immutable evidence of an AP/AR-to-GL reconciliation capability assessment. */
final class ApArSubledgerGlReconciliationRun extends Model
{
    use BelongsToCompany;

    protected $table = 'apar_subledger_gl_reconciliation_runs';

    protected $fillable = [
        'uuid', 'company_id', 'ledger', 'as_of_date', 'status', 'algorithm_version',
        'contract_hash', 'source_completeness', 'divergence_count', 'snapshot',
        'snapshot_hash', 'input_cutoff_at', 'input_boundary', 'requested_by', 'recorded_at',
    ];

    protected $casts = [
        'as_of_date' => 'immutable_date',
        'source_completeness' => 'array',
        'snapshot' => 'array',
        'input_cutoff_at' => 'immutable_datetime',
        'input_boundary' => 'array',
        'divergence_count' => 'integer',
        'recorded_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('AP/AR subledger-to-GL reconciliation runs are append-only.'));
        self::deleting(fn (): never => throw new LogicException('AP/AR subledger-to-GL reconciliation runs are append-only.'));
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ApArSubledgerGlReconciliationException::class, 'reconciliation_run_id');
    }
}
