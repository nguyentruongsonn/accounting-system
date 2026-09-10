<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesQuote extends Model
{
    use BelongsToCompany, HasVoucherReferences;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'quote_number',
        'quote_date',
        'expiry_date',
        'customer_id',
        'customer_code',
        'customer_name',
        'customer_address',
        'tax_code',
        'contact_person',
        'contact_phone',
        'contact_email',
        'employee_id',
        'employee_name',
        'payment_terms',
        'delivery_address',
        'delivery_terms',
        'description',
        'sub_total',
        'discount_amount',
        'vat_amount',
        'total_amount',
        'grand_total',
        'status', // draft, sent, approved, ordered, rejected, cancelled, expired
        'currency',
        'exchange_rate',
        'terms_and_conditions',
        'referenced_vouchers',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quote_date' => 'date',
        'expiry_date' => 'date',
        'sub_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'referenced_vouchers' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesQuoteLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'sales_quote_id');
    }
}
