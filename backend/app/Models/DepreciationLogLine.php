<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepreciationLogLine extends Model
{
    use HasFactory;

    protected $table = 'asset_depreciation_log_lines';

    protected $fillable = [
        'depreciation_log_id',
        'fixed_asset_id',
        'line_order',
        'asset_code',
        'asset_name',
        'department_code',
        'category_code',
        'original_cost',
        'depreciable_cost',
        'useful_life_months',
        'accumulated_depreciation_before',
        'monthly_depreciation',
        'accumulated_depreciation_after',
        'net_value_after',
        'expense_account',
        'depreciation_account',
        'description',
    ];

    protected $casts = [
        'line_order' => 'integer',
        'useful_life_months' => 'integer',
        'original_cost' => 'decimal:2',
        'depreciable_cost' => 'decimal:2',
        'accumulated_depreciation_before' => 'decimal:2',
        'monthly_depreciation' => 'decimal:2',
        'accumulated_depreciation_after' => 'decimal:2',
        'net_value_after' => 'decimal:2',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function depreciationLog(): BelongsTo
    {
        return $this->belongsTo(DepreciationLog::class, 'depreciation_log_id');
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
