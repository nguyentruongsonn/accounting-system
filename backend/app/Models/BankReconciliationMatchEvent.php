<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Event ledger: a proposal/decision is evidence only and cannot post a voucher. */
class BankReconciliationMatchEvent extends Model
{
    use BelongsToCompany;

    protected $fillable = ['uuid', 'company_id', 'bank_statement_line_id', 'supersedes_event_id', 'candidate_type', 'candidate_id', 'decision', 'amount_raw', 'amount_scale', 'reason', 'recorded_by', 'recorded_at'];

    protected $casts = ['recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Bank reconciliation match events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Bank reconciliation match events are append-only.'));
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_event_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
