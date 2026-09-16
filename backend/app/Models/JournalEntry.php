<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use BelongsToCompany, HasVoucherReferences;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'voucher_type',
        'voucher_number',
        'voucher_date',
        'posting_date',
        'description',
        'total_amount',
        'status',
        'source_document_type',
        'source_document_id',
        'reversal_of_id',
        'reversed_by_entry_id',
        'reversal_reason',
        'reversed_by',
        'reversed_at',
        'referenced_vouchers',
        'attached_docs',
        'currency',
        'exchange_rate',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'posting_date' => 'date',
        'total_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'referenced_vouchers' => 'array',
        'reversed_at' => 'datetime',
    ];

    public function sourceDocument(): MorphTo
    {
        return $this->morphTo();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedByEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
