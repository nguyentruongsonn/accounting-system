<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseExpenseAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'source_purchase_invoice_id',
        'target_purchase_invoice_id',
        'target_purchase_invoice_line_id',
        'allocated_amount',
        'allocation_method',
        'effective_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'effective_date' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'source_purchase_invoice_id');
    }

    public function targetInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'target_purchase_invoice_id');
    }

    public function targetLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceLine::class, 'target_purchase_invoice_line_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
