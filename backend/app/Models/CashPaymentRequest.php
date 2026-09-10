<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashPaymentRequest extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'request_number', 'request_date', 'requester_name',
        'department', 'reason', 'amount', 'deadline', 'status',
        'submitted_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'request_date' => 'date',
        'deadline' => 'date',
        'submitted_at' => 'datetime',
        'amount' => 'decimal:2',
    ];
}
