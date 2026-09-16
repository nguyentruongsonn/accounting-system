<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    use BelongsToCompany, HasVoucherReferences;

    protected $table = 'production_orders';

    protected $fillable = [
        'company_id',
        'order_number',
        'start_date',
        'end_date',
        'item_id',
        'planned_quantity',
        'actual_quantity',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'planned_quantity' => 'integer',
        'actual_quantity' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function costAllocations()
    {
        return $this->hasMany(CostAllocation::class);
    }
}
