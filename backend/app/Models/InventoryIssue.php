<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryIssue extends Model
{
    use BelongsToCompany, HasVoucherReferences;

    protected $fillable = [
        'company_id',
        'voucher_type',
        'contact_type',
        'contact_id',
        'contact_name',
        'receiver_name',
        'receiver_address',
        'employee_id',
        'employee_name',
        'warehouse_id',
        'voucher_number',
        'voucher_date',
        'posting_date',
        'description',
        'attached_docs',
        'currency',
        'exchange_rate',
        'total_amount',
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
        'total_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'is_posted' => 'boolean',
        'referenced_vouchers' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryIssueLine::class);
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
