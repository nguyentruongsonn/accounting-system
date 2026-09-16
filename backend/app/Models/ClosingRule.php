<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClosingRule extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'rule_code',
        'rule_name',
        'rule_type', // revenue, expense, result
        'debit_account',
        'credit_account',
        'source_account',
        'target_account',
        'closing_side', // debit, credit, both
        'transfer_type', // turnover, balance_debit, balance_credit, formula
        'sequence',
        'is_active',
        'description',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];
}
