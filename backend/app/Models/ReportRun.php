<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable evidence of a logical report output that was issued to an
 * authorised user. It is not an approval, signature or statutory filing.
 */
class ReportRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'uuid', 'company_id', 'fiscal_year_id', 'issued_by', 'correlation_id',
        'report', 'delivery', 'snapshot_schema', 'filters', 'period', 'regime',
        'control_hash', 'output_hash', 'output_identity', 'snapshot', 'issued_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'period' => 'array',
        'regime' => 'array',
        'snapshot' => 'array',
        'issued_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Report runs are immutable; issue a new report run.');
        });

        static::deleting(function (): never {
            throw new LogicException('Report runs are append-only and cannot be deleted.');
        });
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
