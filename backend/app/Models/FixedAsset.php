<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    use BelongsToCompany, HasFactory, HasVoucherReferences;

    protected $table = 'fixed_assets';

    protected $fillable = [
        'company_id',
        'voucher_number',
        'voucher_date',
        'asset_code',
        'asset_name',
        'category_code',
        'department_code',
        'quantity',
        'supplier_id',
        'supplier_name',
        'purchase_date',
        'start_depreciation_date',
        'original_cost',
        'depreciable_cost',
        'useful_life_months',
        'monthly_depreciation',
        'accumulated_depreciation',
        'net_value',
        'asset_account',
        'depreciation_account',
        'expense_account',
        'credit_account',
        'is_active',
        'is_posted',
        'status',
        'disposal_date',
        'disposal_reason',
        'journal_entry_id',
        'referenced_vouchers',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'purchase_date' => 'date',
        'start_depreciation_date' => 'date',
        'disposal_date' => 'date',
        'quantity' => 'integer',
        'useful_life_months' => 'integer',
        'original_cost' => 'decimal:2',
        'depreciable_cost' => 'decimal:2',
        'monthly_depreciation' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'net_value' => 'decimal:2',
        'is_active' => 'boolean',
        'is_posted' => 'boolean',
        'referenced_vouchers' => 'array',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function depreciationLines(): HasMany
    {
        return $this->hasMany(DepreciationLogLine::class, 'fixed_asset_id');
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(AssetDisposal::class, 'fixed_asset_id');
    }

    public function revaluations(): HasMany
    {
        return $this->hasMany(AssetRevaluation::class, 'fixed_asset_id');
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDepreciating($query)
    {
        return $query->where('is_active', true)->where('net_value', '>', 0);
    }

    public function scopeByDepartment($query, string $departmentCode)
    {
        return $query->where('department_code', $departmentCode);
    }

    public function scopeByCategory($query, string $categoryCode)
    {
        return $query->where('category_code', $categoryCode);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    public function isFullyDepreciated(): bool
    {
        return (float) $this->net_value <= 0;
    }

    public function isDisposed(): bool
    {
        return $this->status === 'disposed' || ! $this->is_active;
    }
}
