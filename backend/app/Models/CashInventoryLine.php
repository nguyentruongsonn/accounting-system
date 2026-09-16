<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashInventoryLine extends Model
{
    protected $fillable = [
        'inventory_id', 'denomination', 'quantity', 'amount',
    ];

    public function inventory()
    {
        return $this->belongsTo(CashInventory::class, 'inventory_id');
    }
}
