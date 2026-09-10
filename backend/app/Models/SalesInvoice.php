<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use BelongsToCompany, HasVoucherReferences;

    protected $fillable = [
        'company_id',
        'customer_id',
        'customer_name',
        'customer_address',
        'receiver_name',
        'employee_id',
        'employee_name',
        'voucher_type',
        'payment_method',
        'invoice_number',
        'invoice_symbol',
        'invoice_code',
        'delivery_voucher_number',
        'invoice_date',
        'accounting_date',
        'due_date',
        'sub_total',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'status',
        'payment_status',
        'description',
        'attached_docs',
        'currency',
        'exchange_rate',
        'functional_currency_code',
        'functional_total_amount_raw',
        'functional_total_amount_scale',
        'original_total_amount_raw',
        'original_total_amount_scale',
        'is_export_slip',
        'is_posted',
        'journal_entry_id',
        'referenced_vouchers',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'accounting_date' => 'date',
        'due_date' => 'date',
        'sub_total' => 'float',
        'discount_amount' => 'float',
        'tax_amount' => 'float',
        'total_amount' => 'float',
        'exchange_rate' => 'float',
        'is_export_slip' => 'boolean',
        'is_posted' => 'boolean',
        'referenced_vouchers' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function eInvoiceDocuments(): HasMany
    {
        return $this->hasMany(EInvoiceDocument::class, 'accounting_document_id')
            ->where('accounting_document_type', self::class);
    }

    /** Explicit, append-only analytic-dimension evidence; current revision is resolved by the service. */
    public function accountingDimensionAssignments(): HasMany
    {
        return $this->hasMany(AccountingDocumentDimensionAssignment::class, 'accounting_document_id')
            ->where('accounting_document_type', self::class);
    }
}
