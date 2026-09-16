<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only exception evidence; it does not represent a journal adjustment. */
final class BankGlReconciliationException extends Model
{
    use BelongsToCompany;

    protected $table = 'bank_gl_reconciliation_exceptions';

    protected $fillable = ['uuid', 'company_id', 'reconciliation_run_id', 'exception_code', 'severity', 'reason', 'evidence', 'recorded_at'];

    protected $casts = ['evidence' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Bank-to-GL reconciliation exceptions are append-only.'));
        self::deleting(fn (): never => throw new LogicException('Bank-to-GL reconciliation exceptions are append-only.'));
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BankGlReconciliationRun::class, 'reconciliation_run_id');
    }
}
