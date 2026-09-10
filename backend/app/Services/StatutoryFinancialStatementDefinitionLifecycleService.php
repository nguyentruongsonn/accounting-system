<?php

namespace App\Services;

use App\Models\StatutoryFinancialStatementDefinition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Approval lifecycle for owner-supplied TT99 Appendix IV catalogue evidence.
 * This service does not evaluate legal correctness, calculate amounts, or
 * substitute a product team's mapping for a controlled owner catalogue.
 */
final class StatutoryFinancialStatementDefinitionLifecycleService
{
    public function __construct(private readonly StatutoryFinancialStatementDefinitionContractHasher $hasher) {}

    /** @param array<string,mixed> $attributes */
    public function createDraft(User $actor, array $attributes): StatutoryFinancialStatementDefinition
    {
        $companyId = $this->companyId($actor);
        if (isset($attributes['company_id']) && (int) $attributes['company_id'] !== $companyId) throw new AuthorizationException('Cannot create a statement definition for another tenant.');
        $attributes['company_id'] = $companyId;
        $attributes['created_by'] = $actor->id;
        $attributes['status'] = 'draft';
        return StatutoryFinancialStatementDefinition::withoutGlobalScope('company')->create($attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function updateDraft(User $actor, StatutoryFinancialStatementDefinition $definition, array $attributes): StatutoryFinancialStatementDefinition
    {
        $this->assertOwner($actor, $definition);
        if ($definition->status !== 'draft' || $definition->approved_at !== null) throw new LogicException('Only an unsigned statement-definition draft may be edited.');
        unset($attributes['company_id'], $attributes['created_by'], $attributes['status'], $attributes['approved_by'], $attributes['approved_at'], $attributes['published_by'], $attributes['published_at'], $attributes['contract_hash']);
        $definition->fill($attributes)->save();
        return $definition->refresh();
    }

    public function approve(User $actor, StatutoryFinancialStatementDefinition $definition, ?CarbonImmutable $at = null): StatutoryFinancialStatementDefinition
    {
        $this->assertOwner($actor, $definition); $at ??= CarbonImmutable::now();
        return DB::transaction(function () use ($actor, $definition, $at): StatutoryFinancialStatementDefinition {
            $companyId = $this->companyId($actor);
            // Scope before locking to keep foreign statement definitions out
            // of the approval transaction for direct callers.
            $locked = StatutoryFinancialStatementDefinition::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($definition->id);
            $this->assertOwner($actor, $locked);
            if ($locked->status !== 'draft' || $locked->approved_at !== null) throw new LogicException('Only an unsigned statement-definition draft may be approved.');
            $contract = $this->completeContract($locked);
            StatutoryFinancialStatementDefinition::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($locked->id)->update([
                'status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => $at,
                'contract_hash' => $this->hasher->hash($contract), 'updated_at' => $at,
            ]);
            return StatutoryFinancialStatementDefinition::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($locked->id);
        });
    }

    public function publish(User $actor, StatutoryFinancialStatementDefinition $definition, ?CarbonImmutable $at = null): StatutoryFinancialStatementDefinition
    {
        $this->assertOwner($actor, $definition); $at ??= CarbonImmutable::now();
        return DB::transaction(function () use ($actor, $definition, $at): StatutoryFinancialStatementDefinition {
            $companyId = $this->companyId($actor);
            // Scope before locking to keep foreign statement definitions out
            // of the publication transaction for direct callers.
            $locked = StatutoryFinancialStatementDefinition::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($definition->id);
            $this->assertOwner($actor, $locked);
            if ($locked->status !== 'approved' || $locked->approved_at === null || $locked->published_at !== null) throw new LogicException('Only an approved, unpublished statement definition may be published.');
            if (! is_string($locked->contract_hash) || ! hash_equals($locked->contract_hash, $this->hasher->hash($this->completeContract($locked)))) throw new LogicException('Approved statement definition integrity check failed.');
            $overlap = StatutoryFinancialStatementDefinition::withoutGlobalScope('company')
                ->where('company_id', $locked->company_id)->lockForUpdate()
                ->where('accounting_regime_profile_id', $locked->accounting_regime_profile_id)
                ->where('form_key', $locked->form_key)->where('status', 'published')
                ->whereDate('effective_from', '<=', $locked->effective_to)->whereDate('effective_to', '>=', $locked->effective_from)->exists();
            if ($overlap) throw ValidationException::withMessages(['effective_from' => 'Published statement-definition effective ranges must not overlap for the same tenant, regime, and form.']);
            StatutoryFinancialStatementDefinition::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($locked->id)->update([
                'status' => 'published', 'published_by' => $actor->id, 'published_at' => $at, 'updated_at' => $at,
            ]);
            return StatutoryFinancialStatementDefinition::withoutGlobalScope('company')->where('company_id', $companyId)->findOrFail($locked->id);
        });
    }

    /** @return array<string,mixed> */
    public function contract(StatutoryFinancialStatementDefinition $definition): array { return $this->completeContract($definition); }

    /** @return array<string,mixed> */
    private function completeContract(StatutoryFinancialStatementDefinition $d): array
    {
        foreach (['provenance_contract', 'form_contract', 'line_definitions', 'line_mapping_contract', 'presentation_contract', 'notes_requirement_contract'] as $field) {
            if (! is_array($d->{$field}) || $d->{$field} === []) throw new LogicException("A non-empty {$field} is required before approval.");
        }
        if (! is_array($d->regulatory_dependencies)) throw new LogicException('REGULATORY DEPENDENCY records must be declared, including an empty list when none apply.');
        if (trim((string) $d->form_key) === '' || trim((string) $d->definition_version) === '') throw new LogicException('A form key and definition version are required before approval.');
        $from = $d->effective_from?->toDateString(); $to = $d->effective_to?->toDateString();
        if ($from === null || $to === null || $from > $to) throw new LogicException('Statement-definition effective dates are invalid.');
        return ['accounting_regime_profile_id' => (int) $d->accounting_regime_profile_id, 'form_key' => $d->form_key, 'definition_version' => $d->definition_version,
            'effective_from' => $from, 'effective_to' => $to, 'provenance_contract' => $d->provenance_contract, 'form_contract' => $d->form_contract,
            'line_definitions' => $d->line_definitions, 'line_mapping_contract' => $d->line_mapping_contract,
            'presentation_contract' => $d->presentation_contract, 'notes_requirement_contract' => $d->notes_requirement_contract,
            'regulatory_dependencies' => $d->regulatory_dependencies];
    }

    private function companyId(User $actor): int { if ($actor->company_id === null) throw new AuthorizationException('The acting user is not assigned to a company.'); return (int) $actor->company_id; }
    private function assertOwner(User $actor, StatutoryFinancialStatementDefinition $d): void { if ($this->companyId($actor) !== (int) $d->company_id) throw new AuthorizationException('The acting user cannot manage another tenant\'s statement definitions.'); }
}
