<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OpeningBalanceFixedAssetLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'original_cost' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
    ];
}
