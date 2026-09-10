<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Event ledger for unmatched/ambiguous bank-reconciliation issues. */
class BankReconciliationExceptionEvent extends Model
{
    use BelongsToCompany;

    protected $fillable = ['uuid', 'company_id', 'bank_statement_line_id', 'exception_key', 'exception_code', 'event_type', 'severity', 'reason', 'evidence', 'recorded_by', 'recorded_at'];

    protected $casts = ['evidence' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Bank reconciliation exception events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Bank reconciliation exception events are append-only.'));
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
