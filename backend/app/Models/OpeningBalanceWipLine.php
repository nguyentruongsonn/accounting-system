<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OpeningBalanceWipLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];
}
