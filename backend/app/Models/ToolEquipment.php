<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Model;

class ToolEquipment extends Model
{
    use BelongsToCompany, HasVoucherReferences;

    protected $table = 'tool_equipments';

    protected $fillable = [
        'company_id',
        'tool_code',
        'tool_name',
        'purchase_date',
        'original_cost',
        'allocation_months',
        'monthly_allocation',
        'accumulated_allocation',
        'remaining_value',
        'tool_account',
        'expense_account',
        'is_active',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'original_cost' => 'integer',
        'allocation_months' => 'integer',
        'monthly_allocation' => 'integer',
        'accumulated_allocation' => 'integer',
        'remaining_value' => 'integer',
        'is_active' => 'boolean',
    ];
}
