<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Immutable, normalized import evidence; never a bank connector sync log. */
class BankStatementImport extends Model
{
    use BelongsToCompany;

    protected $fillable = ['uuid', 'company_id', 'bank_account_id', 'source_format', 'statement_reference', 'content_hash', 'idempotency_key', 'request_hash', 'status', 'line_count', 'source_metadata', 'imported_by', 'imported_at'];

    protected $casts = ['source_metadata' => 'array', 'imported_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Bank statement imports are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Bank statement imports are append-only.'));
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }
}
