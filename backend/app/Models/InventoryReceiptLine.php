<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReceiptLine extends Model
{
    protected $fillable = [
        'inventory_receipt_id',
        'item_id',
        'unit',
        'warehouse_id',
        'warehouse_code',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'debit_account',
        'credit_account',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(InventoryReceipt::class, 'inventory_receipt_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
