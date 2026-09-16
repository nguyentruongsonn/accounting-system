<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'supplier_type', 'is_customer', 'is_supplier', 
        'is_employee', 'is_internal', 'name', 'tax_code', 'dvqhns_code',
        'identity_card_number', 'identity_card_date', 'identity_card_place', 'passport_number',
        'gender', 'birth_date', 'address', 'country', 'province', 'district', 'ward', 'same_as_main_address',
        'phone', 'mobile_phone', 'landline_phone', 'email', 'contact_group',
        'assigned_employee_id', 'website', 'legal_representative',
        'contact_person_salutation', 'contact_person_name', 'contact_person_title',
        'contact_person_phone', 'contact_person_email',
        'einvoice_contact_name', 'einvoice_contact_email', 'einvoice_contact_phone',
        'default_account', 'payment_term', 'due_days', 'debt_limit', 'debt_account',
        'bank_accounts', 'delivery_addresses', 'note',
        'custom_field_1', 'custom_field_2', 'custom_field_3', 'custom_field_4', 'custom_field_5',
        'is_active', 'created_by', 'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_customer' => 'boolean',
        'is_supplier' => 'boolean',
        'is_employee' => 'boolean',
        'is_internal' => 'boolean',
        'same_as_main_address' => 'boolean',
        'bank_accounts' => 'array',
        'delivery_addresses' => 'array',
        'due_days' => 'integer',
        'debt_limit' => 'decimal:2',
    ];
}
