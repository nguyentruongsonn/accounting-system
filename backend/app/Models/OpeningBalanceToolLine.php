<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OpeningBalanceToolLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'original_cost' => 'decimal:2',
        'accumulated_allocation' => 'decimal:2',
        'remaining_value' => 'decimal:2',
    ];
}
