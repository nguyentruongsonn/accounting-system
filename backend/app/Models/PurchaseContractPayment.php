<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseContractPayment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function purchaseContract()
    {
        return $this->belongsTo(PurchaseContract::class);
    }
}
