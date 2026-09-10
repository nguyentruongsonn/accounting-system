<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepreciationLog extends Model
{
    use BelongsToCompany, HasFactory, HasVoucherReferences;

    protected $table = 'asset_depreciation_logs';

    protected $fillable = [
        'company_id',
        'branch_id',
        'voucher_number',
        'voucher_date',
        'accounting_date',
        'month',
        'description',
        'total_amount',
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
        'total_amount' => 'decimal:2',
        'is_posted' => 'boolean',
        'referenced_vouchers' => 'array',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationLogLine::class, 'depreciation_log_id')->orderBy('line_order')->orderBy('id');
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

    public function scopeMonth($query, string $month)
    {
        return $query->where('month', $month);
    }

    public function scopePosted($query)
    {
        return $query->where('is_posted', true);
    }
}
