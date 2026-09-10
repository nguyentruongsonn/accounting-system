<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowingContract extends Model
{
    use BelongsToCompany, HasFactory, HasVoucherReferences;

    protected $fillable = [
        'company_id',
        'contract_number',
        'credit_contract',
        'lender_name',
        'purpose',
        'debit_account',
        'interest_account',
        'amount',
        'term',
        'term_unit',
        'disbursement_date',
        'maturity_date',
        'disbursement_method',
        'recipient_account',
        'recipient_bank',
        'interest_rate',
        'interest_period',
        'paid_principal',
        'remaining_principal',
        'status',
    ];
}
