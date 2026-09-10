<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Immutable bank-reported movement, deliberately separate from accounting vouchers. */
class BankStatementLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['uuid', 'company_id', 'bank_account_id', 'bank_statement_import_id', 'line_number', 'line_reference', 'booked_on', 'value_on', 'direction', 'amount_raw', 'amount_scale', 'currency_code', 'running_balance_raw', 'running_balance_scale', 'bank_reference', 'counterparty_name', 'counterparty_account', 'description', 'normalized_hash', 'source_payload'];

    protected $casts = ['booked_on' => 'date', 'value_on' => 'date', 'source_payload' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Bank statement lines are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Bank statement lines are append-only.'));
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function matchEvents(): HasMany
    {
        return $this->hasMany(BankReconciliationMatchEvent::class);
    }

    public function exceptionEvents(): HasMany
    {
        return $this->hasMany(BankReconciliationExceptionEvent::class);
    }
}
