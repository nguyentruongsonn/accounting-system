<?php

namespace App\Services;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\FinancialReportDefinition;

/** Resolves exactly one tenant-local published definition at a report date. */
final class FinancialReportDefinitionResolver
{
    public function __construct(private readonly FinancialReportDefinitionContractHasher $hasher) {}

    /** @throws ReportDefinitionUnavailableException */
    public function requirePublished(int $companyId, string $reportKey, string $asOfDate): FinancialReportDefinition
    {
        $matches = FinancialReportDefinition::withoutGlobalScope('company')
            ->where('company_id', $companyId)->where('report_key', $reportKey)
            ->where('status', 'published')->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->whereNotNull('published_by')->whereNotNull('published_at')
            ->whereDate('effective_from', '<=', $asOfDate)->whereDate('effective_to', '>=', $asOfDate)
            ->orderBy('id')->get()->filter(fn (FinancialReportDefinition $definition): bool => $this->isExecutable($definition));
        if ($matches->count() !== 1) throw new ReportDefinitionUnavailableException($reportKey);
        return $matches->sole();
    }

    private function isExecutable(FinancialReportDefinition $definition): bool
    {
        foreach (['source_contract', 'line_mapping_contract', 'sign_rounding_contract', 'comparative_contract'] as $field) if (! is_array($definition->{$field}) || $definition->{$field} === []) return false;
        if (! is_array($definition->regulatory_dependencies) || trim((string) $definition->source_form_id) === '' || ! is_string($definition->contract_hash) || $definition->contract_hash === '') return false;
        $contract = [
            'report_key' => $definition->report_key, 'definition_version' => $definition->definition_version,
            'effective_from' => $definition->effective_from->toDateString(), 'effective_to' => $definition->effective_to->toDateString(), 'source_form_id' => $definition->source_form_id,
            'source_contract' => $definition->source_contract, 'line_mapping_contract' => $definition->line_mapping_contract,
            'sign_rounding_contract' => $definition->sign_rounding_contract, 'comparative_contract' => $definition->comparative_contract,
            'regulatory_dependencies' => $definition->regulatory_dependencies,
        ];
        return hash_equals($definition->contract_hash, $this->hasher->hash($contract));
    }
}
