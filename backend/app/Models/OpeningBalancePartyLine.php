<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OpeningBalancePartyLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['due_date' => 'date', 'debit_amount' => 'decimal:2', 'credit_amount' => 'decimal:2'];
}
