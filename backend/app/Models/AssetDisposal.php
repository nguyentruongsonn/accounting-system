<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDisposal extends Model
{
    use BelongsToCompany, HasFactory, HasVoucherReferences;

    protected $table = 'asset_disposals';

    protected $fillable = [
        'company_id',
        'branch_id',
        'voucher_number',
        'voucher_date',
        'accounting_date',
        'disposal_date',
        'fixed_asset_id',
        'asset_code',
        'asset_name',
        'department_code',
        'disposal_type',
        'disposal_reason',
        'original_cost',
        'accumulated_depreciation',
        'net_value',
        'disposal_price',
        'tax_rate',
        'tax_amount',
        'total_income',
        'customer_id',
        'customer_name',
        'buyer_id',
        'buyer_name',
        'payment_method',
        'asset_account',
        'depreciation_account',
        'expense_account',
        'income_account',
        'receivable_account',
        'tax_account',
        'is_posted',
        'status',
        'journal_entry_id',
        'referenced_vouchers',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'accounting_date' => 'date',
        'disposal_date' => 'date',
        'original_cost' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'net_value' => 'decimal:2',
        'disposal_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_income' => 'decimal:2',
        'is_posted' => 'boolean',
        'referenced_vouchers' => 'array',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'buyer_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ==========================================
    // Scopes
    // ==========================================

    public function scopePosted($query)
    {
        return $query->where('is_posted', true);
    }

    public function scopeByAsset($query, int $assetId)
    {
        return $query->where('fixed_asset_id', $assetId);
    }
}
