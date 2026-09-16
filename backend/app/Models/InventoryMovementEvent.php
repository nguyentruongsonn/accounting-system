<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovementEvent extends Model
{
    protected $fillable = [
        'company_id',
        'movement_date',
        'warehouse_id',
        'item_id',
        'movement_type',
        'posting_cycle',
        'quantity_delta',
        'amount_delta',
        'source_type',
        'source_id',
        'source_line_id',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'posting_cycle' => 'integer',
        'quantity_delta' => 'decimal:4',
        'amount_delta' => 'decimal:2',
    ];
}
