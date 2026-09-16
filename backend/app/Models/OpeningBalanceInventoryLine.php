<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OpeningBalanceInventoryLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'total_value' => 'decimal:2'];
}
