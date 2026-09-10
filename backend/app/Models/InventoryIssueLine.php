<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryIssueLine extends Model
{
    protected $fillable = [
        'inventory_issue_id',
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

    public function issue(): BelongsTo
    {
        return $this->belongsTo(InventoryIssue::class, 'inventory_issue_id');
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
