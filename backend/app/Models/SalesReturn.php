<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    use BelongsToCompany, HasVoucherReferences;

    protected $table = 'sales_returns';

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'customer_name',
        'customer_address',
        'tax_code',
        'receiver_name',
        'employee_id',
        'voucher_type',
        'payment_method',
        'bank_account_id',
        'voucher_number',
        'voucher_date',
        'accounting_date',
        'reason',
        'description',
        'attached_docs',
        'sub_total',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'grand_total',
        'cogs_total_amount',
        'is_posted',
        'is_inward',
        'is_import_slip',
        'is_decrease_debt',
        'status',
        'reference_invoice_id',
        'journal_entry_id',
        'referenced_vouchers',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'accounting_date' => 'date',
        'sub_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'cogs_total_amount' => 'decimal:2',
        'is_posted' => 'boolean',
        'is_inward' => 'boolean',
        'is_import_slip' => 'boolean',
        'is_decrease_debt' => 'boolean',
        'referenced_vouchers' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class, 'sales_return_id')->orderBy('line_order')->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function referenceInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'reference_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
