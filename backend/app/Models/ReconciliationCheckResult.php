<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ReconciliationCheckResult extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'reconciliation_run_id', 'check_code', 'domain', 'status',
        'algorithm_version', 'left_total', 'right_total', 'difference', 'row_count',
        'evidence', 'fingerprint', 'result_hash',
    ];

    protected $casts = [
        'left_total' => 'decimal:2',
        'right_total' => 'decimal:2',
        'difference' => 'decimal:2',
        'row_count' => 'integer',
        'evidence' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Reconciliation results are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Reconciliation results are append-only and cannot be deleted.');
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }
}
