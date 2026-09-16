<?php

namespace App\Services;

use App\Exceptions\StatutoryStatementDefinitionUnavailableException;
use App\Models\StatutoryFinancialStatementDefinition;

/** Resolves exactly one signed form catalogue; never falls back to a default. */
final class StatutoryFinancialStatementDefinitionResolver
{
    public function __construct(private readonly StatutoryFinancialStatementDefinitionContractHasher $hasher) {}

    public function requirePublished(int $companyId, int $regimeProfileId, string $formKey, string $asOfDate): StatutoryFinancialStatementDefinition
    {
        $matches = StatutoryFinancialStatementDefinition::withoutGlobalScope('company')
            ->where('company_id', $companyId)->where('accounting_regime_profile_id', $regimeProfileId)->where('form_key', $formKey)
            ->where('status', 'published')->whereNotNull('approved_by')->whereNotNull('approved_at')->whereNotNull('published_by')->whereNotNull('published_at')
            ->whereDate('effective_from', '<=', $asOfDate)->whereDate('effective_to', '>=', $asOfDate)->orderBy('id')->get()
            ->filter(fn (StatutoryFinancialStatementDefinition $d): bool => $this->isExecutable($d));
        if ($matches->count() !== 1) throw new StatutoryStatementDefinitionUnavailableException($formKey);
        return $matches->sole();
    }

    private function isExecutable(StatutoryFinancialStatementDefinition $d): bool
    {
        foreach (['provenance_contract', 'form_contract', 'line_definitions', 'line_mapping_contract', 'presentation_contract', 'notes_requirement_contract'] as $field) if (! is_array($d->{$field}) || $d->{$field} === []) return false;
        if (! is_array($d->regulatory_dependencies) || ! is_string($d->contract_hash) || $d->contract_hash === '') return false;
        return hash_equals($d->contract_hash, $this->hasher->hash([
            'accounting_regime_profile_id' => (int) $d->accounting_regime_profile_id, 'form_key' => $d->form_key, 'definition_version' => $d->definition_version,
            'effective_from' => $d->effective_from->toDateString(), 'effective_to' => $d->effective_to->toDateString(),
            'provenance_contract' => $d->provenance_contract, 'form_contract' => $d->form_contract, 'line_definitions' => $d->line_definitions,
            'line_mapping_contract' => $d->line_mapping_contract, 'presentation_contract' => $d->presentation_contract,
            'notes_requirement_contract' => $d->notes_requirement_contract, 'regulatory_dependencies' => $d->regulatory_dependencies,
        ]));
    }
}
