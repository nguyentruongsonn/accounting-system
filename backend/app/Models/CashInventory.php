<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CashInventory extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'audit_number', 'audit_date', 'purpose', 'currency',
        'account_code',
        'book_balance', 'actual_balance', 'difference', 'status',
    ];

    public function lines()
    {
        return $this->hasMany(CashInventoryLine::class, 'inventory_id');
    }
}
