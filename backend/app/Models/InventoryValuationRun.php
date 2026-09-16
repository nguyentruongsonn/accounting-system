<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryValuationRun extends Model
{
    protected $fillable = [
        'company_id',
        'warehouse_id',
        'item_id',
        'from_date',
        'to_date',
        'method',
        'status',
        'has_unverified_cost',
        'updated_issues_count',
        'total_cost_amount',
        'completed_at',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'completed_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'total_cost_amount' => 'decimal:2',
        'has_unverified_cost' => 'boolean',
    ];
}
