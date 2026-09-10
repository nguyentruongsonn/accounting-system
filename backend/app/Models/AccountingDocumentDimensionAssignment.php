<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable, selected analytic-dimension evidence for a business document.
 * A replacement before posting is a new revision, never an altered record.
 */
class AccountingDocumentDimensionAssignment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'accounting_document_type', 'accounting_document_id', 'revision',
        'accounting_policy_version_id', 'policy_contract_hash', 'posting_date',
        'dimension_code', 'accounting_dimension_definition_id', 'accounting_dimension_value_id',
        'selected_by', 'selected_at', 'metadata',
    ];

    protected $casts = ['posting_date' => 'date', 'selected_at' => 'immutable_datetime', 'metadata' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Accounting document dimension evidence is append-only. Save a new draft revision.'));
        static::deleting(fn (): never => throw new LogicException('Accounting document dimension evidence is append-only.'));
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AccountingDimensionDefinition::class, 'accounting_dimension_definition_id');
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(AccountingDimensionValue::class, 'accounting_dimension_value_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AccountingPolicyVersion::class, 'accounting_policy_version_id');
    }

    public function selector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }
}
