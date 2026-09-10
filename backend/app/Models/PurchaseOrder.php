<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToCompany, HasFactory, HasVoucherReferences;

    protected $guarded = ['id'];

    protected $casts = [
        'order_date' => 'date',
        'delivery_date' => 'date',
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'referenced_vouchers' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
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
