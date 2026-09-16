<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Canonical, typed settlement evidence for AP/AR open-item reporting.
 *
 * It is intentionally separate from legacy invoice_id fields. Posted rows are
 * immutable; reversals must be represented by a separately approved entry.
 */
class SettlementAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'source_document_type', 'source_document_id',
        'source_line_type', 'source_line_id', 'source_reference_key', 'target_document_type',
        'target_document_id', 'allocation_kind', 'allocation_direction', 'reverses_allocation_id', 'amount_raw', 'amount_scale',
        'currency_code', 'functional_currency_code', 'functional_amount_raw', 'functional_amount_scale',
        'original_currency_code', 'original_amount_raw', 'original_amount_scale',
        'effective_date', 'status', 'posted_at', 'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'posted_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $allocation): void {
            if ($allocation->getOriginal('status') === 'posted') {
                throw new LogicException('Posted settlement allocations are immutable.');
            }
        });

        static::deleting(function (self $allocation): void {
            if ($allocation->status === 'posted') {
                throw new LogicException('Posted settlement allocations cannot be deleted.');
            }
        });
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reverses_allocation_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_allocation_id');
    }
}
