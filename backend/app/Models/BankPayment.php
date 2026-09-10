<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankPayment extends Model
{
    use BelongsToCompany, HasVoucherReferences;

    protected $fillable = [
        'company_id',
        'voucher_type',
        'contact_type',
        'contact_id',
        'contact_name',
        'bank_account_id',
        'employee_id',
        'employee_name',
        'voucher_number',
        'voucher_date',
        'posting_date',
        'payee_name',
        'payee_address',
        'payee_bank_account',
        'payee_bank_name',
        'payee_branch',
        'fee_bearer',
        'description',
        'attached_docs',
        'currency',
        'exchange_rate',
        'amount',
        'status',
        'is_posted',
        'journal_entry_id',
        'referenced_vouchers',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'posting_date' => 'date',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'is_posted' => 'boolean',
        'referenced_vouchers' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->posting_date)) {
                $model->posting_date = $model->voucher_date ?? now()->toDateString();
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankPaymentLine::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
