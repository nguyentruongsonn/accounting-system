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
        'contact_name',
        'invoice_address',
        'deliverer_name',
        'receiver_name',
        'receiver_address',
        'tax_code',
        'employee_id',
        'employee_name',
        'invoice_number',
        'invoice_date',
        'accounting_date',
        'due_date',
        'voucher_type',
        'payment_method',
        'payment_status',
        'payment_term_code',
        'due_days',
        'spend_reason',
        'payment_slip_number',
        'is_purchase_expense',
        'is_include_invoice',
        'invoice_option',
        'invoice_symbol',
        'invoice_code',
        'invoice_form',
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
        'due_days' => 'integer',
        'sub_total' => 'float',
        'tax_amount' => 'float',
        'total_amount' => 'float',
        'purchase_expense' => 'float',
        'total_stock_value' => 'float',
        'exchange_rate' => 'float',
        'is_posted' => 'boolean',
        'is_purchase_expense' => 'boolean',
        'is_include_invoice' => 'boolean',
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

    public function expenseAllocations(): HasMany
    {
        return $this->hasMany(PurchaseExpenseAllocation::class, 'source_purchase_invoice_id');
    }

    public function receivedExpenseAllocations(): HasMany
    {
        return $this->hasMany(PurchaseExpenseAllocation::class, 'target_purchase_invoice_id');
    }

    /** Immutable selected-dimension snapshots; the service resolves the latest revision. */
    public function accountingDimensionAssignments(): HasMany
    {
        return $this->hasMany(AccountingDocumentDimensionAssignment::class, 'accounting_document_id')
            ->where('accounting_document_type', self::class);
    }
}
