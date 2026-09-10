<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReceiptLine extends Model
{
    protected $fillable = [
        'bank_receipt_id',
        'description',
        'debit_account',
        'credit_account',
        'amount',
        'operation',
        'loan_contract',
        'line_contact_id',
        'line_contact_name',
        'invoice_id',
        'bank_account_id',
        'original_currency_code', 'original_amount_raw', 'original_amount_scale',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function bankReceipt(): BelongsTo
    {
        return $this->belongsTo(BankReceipt::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
}
