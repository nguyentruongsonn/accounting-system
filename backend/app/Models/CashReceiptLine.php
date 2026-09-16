<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CashReceiptLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_receipt_id',
        'debit_account',
        'credit_account',
        'description',
        'amount',
        'operation',
        'loan_contract',
        'line_contact_id',
        'line_contact_name',
        'invoice_id',
        'sub_object_type',
        'sub_object_id',
        'original_currency_code', 'original_amount_raw', 'original_amount_scale',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function cashReceipt(): BelongsTo
    {
        return $this->belongsTo(CashReceipt::class);
    }

    public function subObject(): MorphTo
    {
        return $this->morphTo();
    }
}
