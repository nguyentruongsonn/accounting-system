<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseContractLine extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function purchaseContract()
    {
        return $this->belongsTo(PurchaseContract::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
