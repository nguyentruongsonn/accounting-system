<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use BelongsToCompany, HasVoucherReferences;

    protected $fillable = [
        'company_id',
        'voucher_number',
        'voucher_date',
        'posting_date',
        'month',
        'description',
        'total_amount',
        'is_posted',
        'journal_entry_id',
    ];

    public function lines()
    {
        return $this->hasMany(PayrollLine::class);
    }
}
