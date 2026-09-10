<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EInvoiceProviderDispatch extends Model
{
    use BelongsToCompany;

    public const STATES = ['prepared', 'retry_pending', 'acknowledged', 'terminal_failed'];

    protected $fillable = ['company_id', 'e_invoice_document_id', 'provider_configuration_id', 'idempotency_key', 'payload_hash', 'state', 'attempt_count', 'next_retry_at', 'acknowledged_at', 'terminal_at', 'prepared_by'];

    protected $casts = ['next_retry_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime', 'terminal_at' => 'immutable_datetime'];

    public function eInvoiceDocument(): BelongsTo
    {
        return $this->belongsTo(EInvoiceDocument::class, 'e_invoice_document_id');
    }
}
