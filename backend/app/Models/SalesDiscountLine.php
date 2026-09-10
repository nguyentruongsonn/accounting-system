<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDiscountLine extends Model
{
    protected $table = 'sales_discount_lines';

    protected $fillable = [
        'sales_discount_id',
        'line_order',
        'item_id',
        'item_code',
        'item_name',
        'description',
        'unit',
        'debit_account',
        'credit_account',
        'quantity',
        'unit_price',
        'amount',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'tax_account',
        'invoice_number',
        'invoice_date',
        'sales_order_id',
        'contract_id',
    ];

    protected $casts = [
        'line_order' => 'integer',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'invoice_date' => 'date',
    ];

    public function salesDiscount(): BelongsTo
    {
        return $this->belongsTo(SalesDiscount::class, 'sales_discount_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
