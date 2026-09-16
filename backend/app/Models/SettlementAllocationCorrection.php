<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Controlled partial reversal of one cash/bank allocation line. */
class SettlementAllocationCorrection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'reverses_allocation_id', 'source_document_type', 'source_document_id',
        'source_line_type', 'source_line_id', 'voucher_number', 'voucher_date', 'accounting_date',
        'amount', 'amount_scale', 'debit_account', 'credit_account', 'reason', 'status', 'is_posted',
        'journal_entry_id', 'created_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'voucher_date' => 'date', 'accounting_date' => 'date', 'is_posted' => 'boolean',
        'posted_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $correction): void {
            if ($correction->getOriginal('is_posted')) {
                throw new LogicException('Posted settlement corrections are immutable and must be reversed through a new approved workflow.');
            }
        });
    }
}
