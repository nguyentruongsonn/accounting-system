<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    protected $table = 'purchase_return_lines';

    protected $fillable = [
        'purchase_return_id',
        'line_order',
        'item_id',
        'item_code',
        'item_name',
        'description',
        'unit',
        'warehouse_id',
        'warehouse_code',
        'debit_account',
        'credit_account',
        'quantity',
        'unit_price',
        'amount',
        'discount_rate',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'tax_account',
        'invoice_number',
        'invoice_date',
        'order_id',
        'contract_id',
    ];

    protected $casts = [
        'line_order' => 'integer',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'invoice_date' => 'date',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    public function purchaseContract(): BelongsTo
    {
        return $this->belongsTo(PurchaseContract::class, 'contract_id');
    }
}
