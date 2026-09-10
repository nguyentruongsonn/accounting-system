<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable AP/AR foreign-currency remeasurement evidence. */
class ApArFxRevaluation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'ledger', 'reference_document_type', 'reference_document_id', 'reversal_of_id',
        'voucher_number', 'voucher_date', 'accounting_date', 'original_currency',
        'foreign_open_amount_raw', 'foreign_open_amount_scale',
        'closing_exchange_rate_raw', 'closing_exchange_rate_scale',
        'carrying_functional_amount', 'revalued_functional_amount', 'adjustment_functional_amount',
        'effect', 'debit_account', 'credit_account', 'reason', 'status', 'is_posted',
        'journal_entry_id', 'created_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'voucher_date' => 'date', 'accounting_date' => 'date', 'is_posted' => 'boolean',
        'posted_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $revaluation): void {
            if ($revaluation->getOriginal('is_posted')) {
                throw new LogicException('Posted AP/AR FX revaluations are immutable and must be reversed through a new approved workflow.');
            }
        });
    }
}
