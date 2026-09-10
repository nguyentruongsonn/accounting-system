<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    use BelongsToCompany, HasVoucherReferences;

    protected $fillable = [
        'company_id',
        'supplier_id',
        'supplier_name',
        'supplier_address',
        'deliverer_name',
        'employee_id',
        'employee_name',
        'invoice_number',
        'invoice_date',
        'accounting_date',
        'due_date',
        'voucher_type',
        'payment_method',
        'invoice_symbol',
        'invoice_code',
        'description',
        'attached_docs',
        'currency',
        'exchange_rate',
        'functional_currency_code',
        'functional_total_amount_raw',
        'functional_total_amount_scale',
        'original_total_amount_raw',
        'original_total_amount_scale',
        'sub_total',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'purchase_expense',
        'total_stock_value',
        'status',
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
        'tax_amount' => 'float',
        'total_amount' => 'float',
        'purchase_expense' => 'float',
        'total_stock_value' => 'float',
        'exchange_rate' => 'float',
        'is_posted' => 'boolean',
        'referenced_vouchers' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

    /** Immutable selected-dimension snapshots; the service resolves the latest revision. */
    public function accountingDimensionAssignments(): HasMany
    {
        return $this->hasMany(AccountingDocumentDimensionAssignment::class, 'accounting_document_id')
            ->where('accounting_document_type', self::class);
    }
}
