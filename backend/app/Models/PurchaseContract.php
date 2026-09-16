<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseContract extends Model
{
    use BelongsToCompany, HasFactory, HasVoucherReferences;

    protected $guarded = ['id'];

    protected $casts = [
        'signed_date' => 'date',
        'effective_date' => 'date',
        'end_date' => 'date',
        'delivery_deadline' => 'date',
        'payment_deadline' => 'date',
        'liquidation_date' => 'date',
        'contract_value' => 'float',
        'discount_amount' => 'float',
        'vat_amount' => 'float',
        'total_amount' => 'float',
        'liquidation_value' => 'float',
        'exchange_rate' => 'float',
        'referenced_vouchers' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseContractLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseContractPayment::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
