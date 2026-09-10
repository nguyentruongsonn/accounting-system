<?php

namespace App\Services;

use App\Models\FinancialReportDefinition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Trusted lifecycle for report-definition evidence.  Definitions become
 * immutable at approval; publication appends only publication evidence using
 * a narrow query-builder transition.  It intentionally does not certify
 * Appendix IV completeness or legal compliance.
 */
final class FinancialReportDefinitionLifecycleService
{
    public function __construct(private readonly FinancialReportDefinitionContractHasher $hasher) {}

    /** @param array<string,mixed> $attributes */
    public function createDraft(User $actor, array $attributes): FinancialReportDefinition
    {
        $companyId = $this->companyId($actor);
        if (isset($attributes['company_id']) && (int) $attributes['company_id'] !== $companyId) {
            throw new AuthorizationException('Cannot create a financial report definition for another tenant.');
        }
        $attributes['company_id'] = $companyId;
        $attributes['created_by'] = $actor->id;
        $attributes['status'] = 'draft';
        return FinancialReportDefinition::withoutGlobalScope('company')->create($attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function updateDraft(User $actor, FinancialReportDefinition $definition, array $attributes): FinancialReportDefinition
    {
        $this->assertOwner($actor, $definition);
        if ($definition->status !== 'draft' || $definition->approved_at !== null) {
            throw new LogicException('Only an unsigned financial report draft may be edited.');
        }
        unset($attributes['company_id'], $attributes['created_by'], $attributes['status'], $attributes['approved_by'], $attributes['approved_at'], $attributes['published_by'], $attributes['published_at'], $attributes['contract_hash']);
        $definition->fill($attributes)->save();
        return $definition->refresh();
    }

    public function approve(User $actor, FinancialReportDefinition $definition, ?CarbonImmutable $at = null): FinancialReportDefinition
    {
        $this->assertOwner($actor, $definition);
        $at ??= CarbonImmutable::now();
        return DB::transaction(function () use ($actor, $definition, $at): FinancialReportDefinition {
            $companyId = $this->companyId($actor);
            // Scope before locking to keep foreign report definitions out of
            // the approval transaction even for direct service callers.
            $locked = FinancialReportDefinition::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($definition->id);
            $this->assertOwner($actor, $locked);
            if ($locked->status !== 'draft' || $locked->approved_at !== null) throw new LogicException('Only an unsigned financial report draft may be approved.');
            $contract = $this->completeContract($locked);
            FinancialReportDefinition::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($locked->id)->update([
                'status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => $at,
                'contract_hash' => $this->hasher->hash($contract), 'updated_at' => $at,
            ]);
            return FinancialReportDefinition::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($locked->id);
        });
    }

    public function publish(User $actor, FinancialReportDefinition $definition, ?CarbonImmutable $at = null): FinancialReportDefinition
    {
        $this->assertOwner($actor, $definition);
        $at ??= CarbonImmutable::now();
        return DB::transaction(function () use ($actor, $definition, $at): FinancialReportDefinition {
            $companyId = $this->companyId($actor);
            // Scope before locking to keep foreign report definitions out of
            // the publication transaction even for direct service callers.
            $locked = FinancialReportDefinition::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($definition->id);
            $this->assertOwner($actor, $locked);
            if ($locked->status !== 'approved' || $locked->approved_at === null || $locked->published_at !== null) throw new LogicException('Only an approved, unpublished financial report definition may be published.');
            if (! is_string($locked->contract_hash) || ! hash_equals($locked->contract_hash, $this->hasher->hash($this->completeContract($locked)))) throw new LogicException('Approved financial report definition integrity check failed.');
            $overlap = FinancialReportDefinition::withoutGlobalScope('company')
                ->where('company_id', $locked->company_id)->lockForUpdate()
                ->where('report_key', $locked->report_key)
                ->where('status', 'published')->whereDate('effective_from', '<=', $locked->effective_to)
                ->whereDate('effective_to', '>=', $locked->effective_from)->exists();
            if ($overlap) throw ValidationException::withMessages(['effective_from' => 'Published financial-report definition effective ranges must not overlap for the same tenant and report key.']);
            FinancialReportDefinition::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($locked->id)->update([
                'status' => 'published', 'published_by' => $actor->id, 'published_at' => $at, 'updated_at' => $at,
            ]);
            return FinancialReportDefinition::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($locked->id);
        });
    }

    /** @return array<string,mixed> */
    public function contract(FinancialReportDefinition $definition): array { return $this->completeContract($definition); }

    /** @return array<string,mixed> */
    private function completeContract(FinancialReportDefinition $definition): array
    {
        $contracts = ['source_contract', 'line_mapping_contract', 'sign_rounding_contract', 'comparative_contract'];
        foreach ($contracts as $field) if (! is_array($definition->{$field}) || $definition->{$field} === []) throw new LogicException("A non-empty {$field} is required before approval.");
        if (! is_array($definition->regulatory_dependencies)) throw new LogicException('REGULATORY DEPENDENCY records must be declared, including an empty list when none apply.');
        if (trim((string) $definition->report_key) === '' || trim((string) $definition->definition_version) === '' || trim((string) $definition->source_form_id) === '') throw new LogicException('A report key, version, and source form identifier are required before approval.');
        $from = $definition->effective_from?->toDateString(); $to = $definition->effective_to?->toDateString();
        if ($from === null || $to === null || $from > $to) throw new LogicException('Financial report definition effective dates are invalid.');
        return [
            'report_key' => $definition->report_key, 'definition_version' => $definition->definition_version,
            'effective_from' => $from, 'effective_to' => $to, 'source_form_id' => $definition->source_form_id,
            'source_contract' => $definition->source_contract, 'line_mapping_contract' => $definition->line_mapping_contract,
            'sign_rounding_contract' => $definition->sign_rounding_contract, 'comparative_contract' => $definition->comparative_contract,
            'regulatory_dependencies' => $definition->regulatory_dependencies,
        ];
    }

    private function companyId(User $actor): int { if ($actor->company_id === null) throw new AuthorizationException('The acting user is not assigned to a company.'); return (int) $actor->company_id; }
    private function assertOwner(User $actor, FinancialReportDefinition $definition): void { if ($this->companyId($actor) !== (int) $definition->company_id) throw new AuthorizationException('The acting user cannot manage another tenant\'s financial report definitions.'); }
}
