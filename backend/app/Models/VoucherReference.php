<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VoucherReference extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'target_voucher_type',
        'target_voucher_number',
        'target_voucher_date',
        'target_total_amount',
        'description',
    ];

    protected $casts = [
        'target_voucher_date' => 'date',
        'target_total_amount' => 'decimal:2',
    ];

    /**
     * Lấy model của chứng từ nguồn (VD: CashReceipt, CashPayment...)
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Lấy model của chứng từ đích (VD: SalesInvoice, PurchaseOrder...)
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
