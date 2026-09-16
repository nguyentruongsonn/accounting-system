<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Versioned tenant evidence for a financial-statement form and its lines.
 *
 * It deliberately stores owner-approved catalogue data only. It neither
 * generates a statutory statement nor claims that an Appendix IV mapping is
 * legally complete.  That assertion needs an owner-supplied catalogue and a
 * separate controlled implementation of the report calculation.
 */
class StatutoryFinancialStatementDefinition extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'company_id', 'accounting_regime_profile_id', 'form_key',
        'definition_version', 'effective_from', 'effective_to',
        'provenance_contract', 'form_contract', 'line_definitions',
        'line_mapping_contract', 'presentation_contract',
        'notes_requirement_contract', 'regulatory_dependencies', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date', 'effective_to' => 'date',
        'provenance_contract' => 'array', 'form_contract' => 'array',
        'line_definitions' => 'array', 'line_mapping_contract' => 'array',
        'presentation_contract' => 'array', 'notes_requirement_contract' => 'array',
        'regulatory_dependencies' => 'array',
        'approved_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $definition): void {
            if ($definition->status !== 'draft' || $definition->approved_by !== null
                || $definition->approved_at !== null || $definition->published_by !== null
                || $definition->published_at !== null || $definition->contract_hash !== null) {
                throw new LogicException('Statutory statement definitions must be created as unsigned drafts.');
            }
            $definition->assertProfileAndEffectiveRange();
        });

        static::updating(function (self $definition): void {
            if ($definition->getOriginal('approved_at') !== null) {
                throw new LogicException('Approved statutory statement definitions are immutable; create a successor draft.');
            }
            if ($definition->isDirty(['status', 'approved_by', 'approved_at', 'published_by', 'published_at', 'contract_hash'])) {
                throw new LogicException('Use StatutoryFinancialStatementDefinitionLifecycleService for approval or publication.');
            }
            $definition->assertProfileAndEffectiveRange();
        });

        static::deleting(function (self $definition): void {
            if ($definition->approved_at !== null) {
                throw new LogicException('Approved statutory statement definitions cannot be deleted.');
            }
        });
    }

    public function accountingRegimeProfile(): BelongsTo
    {
        return $this->belongsTo(AccountingRegimeProfile::class);
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

    private function assertProfileAndEffectiveRange(): void
    {
        $profile = AccountingRegimeProfile::withoutGlobalScope('company')
            ->where('company_id', $this->company_id)->find($this->accounting_regime_profile_id);
        if ($profile === null) {
            throw ValidationException::withMessages(['accounting_regime_profile_id' => 'Hồ sơ chế độ kế toán phải thuộc doanh nghiệp hiện tại.']);
        }
        $from = $this->effective_from?->toDateString() ?? (string) $this->getRawOriginal('effective_from');
        $to = $this->effective_to?->toDateString() ?? (string) $this->getRawOriginal('effective_to');
        if ($from === '' || $to === '' || $from > $to
            || $from < $profile->effective_from->toDateString() || $to > $profile->effective_to->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => 'Hiệu lực catalogue phải nằm hoàn toàn trong hiệu lực hồ sơ chế độ kế toán.']);
        }
    }
}
