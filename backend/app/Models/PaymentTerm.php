<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTerm extends Model
{
    use BelongsToCompany, HasFactory;

    protected $guarded = ['id'];
}
