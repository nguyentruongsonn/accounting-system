<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashAdvanceSettlement extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'settlement_number', 'settlement_date', 'employee_id',
        'employee_name', 'department', 'advance_amount', 'actual_spent',
        'refund_amount', 'extra_amount', 'reason', 'status', 'submitted_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'submitted_at' => 'datetime',
        'advance_amount' => 'decimal:2',
        'actual_spent' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'extra_amount' => 'decimal:2',
    ];
}
