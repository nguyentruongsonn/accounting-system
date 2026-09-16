<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'account_number',
        'bank_name',
        'bank_code',
        'province',
        'branch',
        'branch_address',
        'swift_code',
        'account_holder',
        'currency',
        'description',
        'is_active',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
