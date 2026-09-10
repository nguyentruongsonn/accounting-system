<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashPayment extends Model
{
    use BelongsToCompany, HasVoucherReferences;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'voucher_type',
        'contact_type',
        'contact_id',
        'contact_name',
        'voucher_number',
        'voucher_date',
        'posting_date',
        'receiver_name',
        'receiver_address',
        'employee_id',
        'employee_name',
        'reason',
        'referenced_vouchers',
        'attached_docs',
        'currency',
        'exchange_rate',
        'total_amount',
        'status',
        'is_posted',
        'journal_entry_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'posting_date' => 'date',
        'total_amount' => 'integer',
        'referenced_vouchers' => 'array',
        'is_posted' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CashPaymentLine::class);
    }
}
