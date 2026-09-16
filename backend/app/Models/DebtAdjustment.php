<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A controlled AP/AR credit-note or write-off adjustment. It is a source
 * document, not an inferred general-journal effect.
 */
class DebtAdjustment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'ledger', 'adjustment_kind', 'voucher_number', 'voucher_date',
        'accounting_date', 'reference_document_type', 'reference_document_id',
        'reversal_of_id', 'amount', 'debit_account', 'credit_account', 'description',
        'status', 'is_posted', 'journal_entry_id', 'created_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'accounting_date' => 'date',
        'is_posted' => 'boolean',
        'posted_at' => 'immutable_datetime',
    ];

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $adjustment): void {
            if ($adjustment->getOriginal('is_posted')) {
                throw new LogicException('Posted debt adjustments are immutable and must be reversed.');
            }
        });

        static::deleting(function (self $adjustment): void {
            if ($adjustment->is_posted) {
                throw new LogicException('Posted debt adjustments cannot be deleted.');
            }
        });
    }
}
