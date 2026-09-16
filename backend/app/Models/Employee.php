<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'is_customer',
        'is_supplier',
        'department',
        'position',
        'gender',
        'birth_date',
        'id_card_number',
        'id_card_date',
        'id_card_place',
        'passport_number',
        'address',
        'email',
        'phone',
        'landline_phone',
        'account_email',
        'account_phone',
        'base_salary',
        'contract_salary',
        'salary_coefficient',
        'insurance_salary',
        'tax_code',
        'contract_type',
        'dependents_count',
        'personal_deduction',
        'bank_accounts',
        'dependents',
        'status',
    ];

    protected $casts = [
        'is_customer' => 'boolean',
        'is_supplier' => 'boolean',
        'bank_accounts' => 'array',
        'dependents' => 'array',
        'base_salary' => 'decimal:2',
        'contract_salary' => 'decimal:2',
        'salary_coefficient' => 'decimal:2',
        'insurance_salary' => 'decimal:2',
        'personal_deduction' => 'decimal:2',
        'dependents_count' => 'integer',
    ];
}
