<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Tenant-owned versioned definition for a financial-report output.
 *
 * This is an accounting-control artefact, not a statement that a report is a
 * statutory TT99 Appendix IV form.  A source_form_id is evidence supplied by
 * the tenant/owner; the application deliberately does not invent form codes
 * or account mappings.
 */
class FinancialReportDefinition extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'company_id', 'report_key', 'definition_version', 'effective_from',
        'effective_to', 'source_form_id', 'source_contract',
        'line_mapping_contract', 'sign_rounding_contract',
        'comparative_contract', 'regulatory_dependencies', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'source_contract' => 'array',
        'line_mapping_contract' => 'array',
        'sign_rounding_contract' => 'array',
        'comparative_contract' => 'array',
        'regulatory_dependencies' => 'array',
        'approved_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $definition): void {
            if ($definition->status !== 'draft'
                || $definition->approved_by !== null || $definition->approved_at !== null
                || $definition->published_by !== null || $definition->published_at !== null
                || $definition->contract_hash !== null) {
                throw new LogicException('Financial report definitions must be created as unsigned drafts.');
            }
        });

        static::updating(function (self $definition): void {
            if ($definition->getOriginal('approved_at') !== null) {
                throw new LogicException('Approved financial report definitions are immutable; create a successor draft.');
            }

            if ($definition->isDirty(['status', 'approved_by', 'approved_at', 'published_by', 'published_at', 'contract_hash'])) {
                throw new LogicException('Use FinancialReportDefinitionLifecycleService for approval or publication.');
            }
        });

        static::deleting(function (self $definition): void {
            if ($definition->approved_at !== null) {
                throw new LogicException('Approved financial report definitions cannot be deleted.');
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
