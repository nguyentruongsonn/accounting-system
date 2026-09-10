<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable provider/evidence lifecycle record. This is intentionally not a
 * tax return, signature, transmission, or legal-compliance assertion.
 */
class EInvoiceDocument extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['draft', 'issued', 'replaced', 'adjusted', 'cancelled'];

    protected $fillable = [
        'company_id', 'accounting_document_type', 'accounting_document_id',
        'lifecycle_status', 'provider_name', 'provider_document_id',
        'document_reference', 'payload_hash', 'payload_snapshot',
        'supersedes_einvoice_document_id', 'recorded_by', 'occurred_at', 'metadata',
    ];

    protected $casts = ['payload_snapshot' => 'array', 'metadata' => 'array', 'occurred_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('E-invoice documents are append-only. Record a new lifecycle event.'));
        static::deleting(fn (): never => throw new LogicException('E-invoice documents are append-only.'));
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_einvoice_document_id');
    }
}
