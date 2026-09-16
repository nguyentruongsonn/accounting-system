<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetRevaluation extends Model
{
    use BelongsToCompany, HasFactory, HasVoucherReferences;

    protected $table = 'asset_revaluations';

    protected $fillable = [
        'company_id',
        'branch_id',
        'voucher_number',
        'voucher_date',
        'accounting_date',
        'fixed_asset_id',
        'asset_code',
        'asset_name',
        'department_code',
        'old_original_cost',
        'new_original_cost',
        'cost_difference',
        'old_accumulated_depreciation',
        'new_accumulated_depreciation',
        'depreciation_difference',
        'old_net_value',
        'new_net_value',
        'old_useful_life_months',
        'new_useful_life_months',
        'old_useful_life',
        'new_useful_life',
        'old_monthly_depreciation',
        'new_monthly_depreciation',
        'decision_number',
        'decision_date',
        'reason',
        'asset_account',
        'revaluation_account',
        'depreciation_account',
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
        'decision_date' => 'date',
        'old_original_cost' => 'decimal:2',
        'new_original_cost' => 'decimal:2',
        'cost_difference' => 'decimal:2',
        'old_accumulated_depreciation' => 'decimal:2',
        'new_accumulated_depreciation' => 'decimal:2',
        'depreciation_difference' => 'decimal:2',
        'old_net_value' => 'decimal:2',
        'new_net_value' => 'decimal:2',
        'old_useful_life_months' => 'integer',
        'new_useful_life_months' => 'integer',
        'old_useful_life' => 'integer',
        'new_useful_life' => 'integer',
        'old_monthly_depreciation' => 'decimal:2',
        'new_monthly_depreciation' => 'decimal:2',
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
