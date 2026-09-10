<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only blocker evidence; never an adjustment or a waiver. */
final class InventorySubledgerGlReconciliationException extends Model
{
    use BelongsToCompany;

    protected $table = 'inventory_subledger_gl_reconciliation_exceptions';

    protected $fillable = ['uuid', 'company_id', 'reconciliation_run_id', 'exception_code', 'severity', 'reason', 'evidence', 'recorded_at'];

    protected $casts = ['evidence' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Inventory subledger-to-GL reconciliation exceptions are append-only.'));
        self::deleting(fn (): never => throw new LogicException('Inventory subledger-to-GL reconciliation exceptions are append-only.'));
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(InventorySubledgerGlReconciliationRun::class, 'reconciliation_run_id');
    }
}
