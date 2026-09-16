<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OpeningBalanceAccountLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['debit_amount' => 'decimal:2', 'credit_amount' => 'decimal:2'];
}
