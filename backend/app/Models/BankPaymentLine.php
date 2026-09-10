<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankPaymentLine extends Model
{
    protected $fillable = [
        'bank_payment_id',
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

    public function bankPayment(): BelongsTo
    {
        return $this->belongsTo(BankPayment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'invoice_id');
    }
}
