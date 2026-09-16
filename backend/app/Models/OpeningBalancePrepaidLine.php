<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OpeningBalancePrepaidLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];
}
