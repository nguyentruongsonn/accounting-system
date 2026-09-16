<?php

namespace App\Services;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\ManagementReportDefinition;
use App\Models\ManagementReportEffectiveDefinition;

/**
 * Resolves only report definitions that are safe to execute.
 *
 * A published flag alone is insufficient: a signed exact source contract and
 * a signed exact calculation contract are required. Calculation services must
 * call this registry before producing a v2 result.
 */
final class ManagementReportDefinitionRegistry
{
    public function __construct(private readonly ManagementReportDefinitionContractHasher $hasher) {}

    /**
     * @throws ReportDefinitionUnavailableException
     */
    public function requireExecutable(int $companyId, string $reportKey): ManagementReportDefinition
    {
        $definitionsTable = (new ManagementReportDefinition)->getTable();
        $effectiveTable = (new ManagementReportEffectiveDefinition)->getTable();

        $definition = ManagementReportDefinition::withoutGlobalScope('company')
            ->join($effectiveTable, "{$effectiveTable}.management_report_definition_id", '=', "{$definitionsTable}.id")
            ->select("{$definitionsTable}.*")
            ->where("{$definitionsTable}.company_id", $companyId)
            ->where("{$definitionsTable}.report_key", $reportKey)
            ->where("{$definitionsTable}.status", 'published')
            ->whereNotNull("{$definitionsTable}.signed_by")
            ->whereNotNull("{$definitionsTable}.signed_at")
            ->whereNotNull("{$definitionsTable}.published_at")
            ->where("{$effectiveTable}.company_id", $companyId)
            ->where("{$effectiveTable}.report_key", $reportKey)
            ->get()
            ->first(fn (ManagementReportDefinition $candidate): bool => $this->hasExactContracts($candidate));

        if ($definition === null) {
            throw new ReportDefinitionUnavailableException($reportKey);
        }

        return $definition;
    }

    /**
     * Resolve an executable definition only when it declares precisely the
     * amount representation required by the caller. Stock v2 should use this
     * boundary before reading any quantity or money source data, so a missing
     * or scale-mismatched D-04 contract cannot silently fall back to floats.
     *
     * @param  array<string, mixed>  $expectedAmountContract
     *
     * @throws ReportDefinitionUnavailableException
     */
    public function requireExecutableWithExactAmountContract(
        int $companyId,
        string $reportKey,
        array $expectedAmountContract,
    ): ManagementReportDefinition {
        $definition = $this->requireExecutable($companyId, $reportKey);

        if (! is_array($definition->amount_contract)
            || $definition->amount_contract === []
            || ! $this->hasher->equivalent($definition->amount_contract, $expectedAmountContract)) {
            throw new ReportDefinitionUnavailableException($reportKey);
        }

        return $definition;
    }

    private function hasExactContracts(ManagementReportDefinition $definition): bool
    {
        return is_array($definition->source_contract)
            && $definition->source_contract !== []
            && is_array($definition->calculation_contract)
            && $definition->calculation_contract !== []
            && ($definition->amount_contract === null
                || (is_array($definition->amount_contract) && $definition->amount_contract !== []))
            && is_string($definition->contract_hash)
            && $definition->contract_hash !== ''
            && hash_equals($definition->contract_hash, $this->hasher->hash(
                $definition->report_key,
                $definition->definition_version,
                $definition->source_contract,
                $definition->calculation_contract,
                $definition->amount_contract,
            ));
    }
}
