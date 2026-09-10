<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use BelongsToCompany, HasVoucherReferences;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'quote_id',
        'sales_quote_id',
        'order_number',
        'order_date',
        'delivery_date',
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
        'due_days',
        'delivery_address',
        'other_terms',
        'description',
        'sub_total',
        'discount_amount',
        'vat_amount',
        'total_amount',
        'grand_total',
        'status', // pending, confirmed, processing, delivering, completed, cancelled
        'delivery_status', // not_delivered, partial, delivered
        'invoice_status', // not_invoiced, partial, invoiced
        'invoiced_status',
        'currency',
        'exchange_rate',
        'referenced_vouchers',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'delivery_date' => 'date',
        'sub_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'due_days' => 'integer',
        'exchange_rate' => 'decimal:4',
        'referenced_vouchers' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class, 'sales_quote_id');
    }
}
