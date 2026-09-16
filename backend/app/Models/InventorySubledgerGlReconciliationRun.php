<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Immutable, fail-closed inventory reconciliation capability evidence. */
final class InventorySubledgerGlReconciliationRun extends Model
{
    use BelongsToCompany;

    protected $table = 'inventory_subledger_gl_reconciliation_runs';

    protected $fillable = ['uuid', 'company_id', 'as_of_date', 'status', 'algorithm_version', 'contract_hash', 'source_completeness', 'divergence_count', 'snapshot', 'snapshot_hash', 'requested_by', 'recorded_at'];

    protected $casts = ['as_of_date' => 'immutable_date', 'source_completeness' => 'array', 'snapshot' => 'array', 'divergence_count' => 'integer', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Inventory subledger-to-GL reconciliation runs are append-only.'));
        self::deleting(fn (): never => throw new LogicException('Inventory subledger-to-GL reconciliation runs are append-only.'));
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(InventorySubledgerGlReconciliationException::class, 'reconciliation_run_id');
    }
}
