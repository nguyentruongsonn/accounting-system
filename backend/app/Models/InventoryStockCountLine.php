<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockCountLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_stock_count_id', 'item_id', 'unit', 'counted_quantity', 'description',
    ];

    protected $casts = [
        'counted_quantity' => 'decimal:6',
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(InventoryStockCount::class, 'inventory_stock_count_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
