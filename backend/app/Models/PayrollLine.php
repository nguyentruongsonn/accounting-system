<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_id',
        'employee_id',
        'employee_name',
        'department',
        'basic_salary',
        'allowance',
        'deduction',
        'net_salary',
        'debit_account',
        'credit_account',
    ];

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }
}
