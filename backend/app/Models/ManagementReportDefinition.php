<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Tenant-bound, versioned contract for an operational management report.
 *
 * This model stores approval metadata only. It does not itself make a report
 * statutory, tax-compliant, or Appendix IV certified.
 */
class ManagementReportDefinition extends Model
{
    use BelongsToCompany;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'company_id',
        'report_key',
        'definition_version',
        'source_contract',
        'calculation_contract',
        'amount_contract',
    ];

    protected $casts = [
        'source_contract' => 'array',
        'calculation_contract' => 'array',
        'amount_contract' => 'array',
        'signed_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
    ];

    /**
     * A definition may be collaboratively edited while it is a draft. Once
     * published, its source and calculation contract are accounting-control
     * evidence and must never be changed in place. A successor version is
     * required instead.
     *
     * The lifecycle service uses a narrowly-scoped query-builder transition
     * from draft to published. The database trigger added with the lifecycle
     * migration independently protects already-published rows from raw SQL.
     */
    protected static function booted(): void
    {
        static::creating(function (self $definition): void {
            if ($definition->status !== 'draft') {
                throw new LogicException('Management report definitions must be created as drafts.');
            }

            if ($definition->signed_by !== null || $definition->signed_at !== null
                || $definition->published_at !== null || $definition->contract_hash !== null) {
                throw new LogicException('Publication metadata can only be set by the definition lifecycle service.');
            }
        });

        static::updating(function (self $definition): void {
            if ($definition->getOriginal('published_at') !== null) {
                throw new LogicException('Published management report definitions are immutable; create a successor draft.');
            }

            if ($definition->isDirty(['status', 'signed_by', 'signed_at', 'published_at', 'contract_hash'])) {
                throw new LogicException('Use the definition lifecycle service to publish a management report definition.');
            }
        });

        static::deleting(function (self $definition): void {
            if ($definition->published_at !== null) {
                throw new LogicException('Published management report definitions cannot be deleted.');
            }
        });
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }
}
