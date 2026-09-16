<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only missing-contract or source-lineage evidence, never a journal adjustment. */
final class FixedAssetGlReconciliationException extends Model
{
    use BelongsToCompany;

    protected $table = 'fixed_asset_gl_reconciliation_exceptions';

    protected $fillable = ['uuid', 'company_id', 'reconciliation_run_id', 'exception_code', 'severity', 'reason', 'evidence', 'recorded_at'];

    protected $casts = ['evidence' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Fixed-asset-to-GL reconciliation exceptions are append-only.'));
        self::deleting(fn (): never => throw new LogicException('Fixed-asset-to-GL reconciliation exceptions are append-only.'));
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FixedAssetGlReconciliationRun::class, 'reconciliation_run_id');
    }
}
