<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only exception ledger; it never represents an accounting adjustment. */
final class ApArSubledgerGlReconciliationException extends Model
{
    use BelongsToCompany;

    protected $table = 'apar_subledger_gl_reconciliation_exceptions';

    protected $fillable = [
        'uuid', 'company_id', 'reconciliation_run_id', 'exception_code', 'severity',
        'reason', 'evidence', 'recorded_at',
    ];

    protected $casts = ['evidence' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('AP/AR reconciliation exceptions are append-only.'));
        self::deleting(fn (): never => throw new LogicException('AP/AR reconciliation exceptions are append-only.'));
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ApArSubledgerGlReconciliationRun::class, 'reconciliation_run_id');
    }
}
