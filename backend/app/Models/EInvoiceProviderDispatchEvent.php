<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EInvoiceProviderDispatchEvent extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'e_invoice_provider_dispatch_id', 'event_type', 'attempt_number', 'provider_result_code', 'result_payload_hash', 'safe_metadata', 'recorded_by', 'occurred_at'];

    protected $casts = ['safe_metadata' => 'array', 'occurred_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Provider dispatch evidence is append-only.'));
        static::deleting(fn (): never => throw new LogicException('Provider dispatch evidence is append-only.'));
    }
}
