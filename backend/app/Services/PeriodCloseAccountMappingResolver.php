<?php

namespace App\Services;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Models\ChartOfAccount;

/** Resolves the tenant-approved source and target accounts for period closing. */
final class PeriodCloseAccountMappingResolver
{
    public const POLICY_KEY = 'posting.period_closing';

    public const MAPPING_KEY = 'period_close.result';

    public function __construct(
        private readonly AccountingPolicyResolver $policyResolver,
        private readonly ApprovedAccountMappingResolver $mappingResolver,
    ) {}

    /**
     * @return array{
     *   policy_id:int,
     *   policy_contract_hash:string,
     *   mapping_key:string,
     *   posting_date:string,
     *   source_accounts:list<array{account_code:string,category:string}>,
     *   resolutions:list<array<string,mixed>>,
     *   accounts:array{result_clearing:string,retained_earnings:string}
     * }
     */
    public function require(int $companyId, string $postingDate): array
    {
        $policy = $this->policyResolver->require($companyId, $postingDate, self::POLICY_KEY);
        $contract = $policy['posting_rule_contract'] ?? null;
        if (! is_array($contract)
            || ($contract['schema'] ?? null) !== 'period-closing.v1'
            || ! isset($contract['source_accounts'])
            || ! is_array($contract['source_accounts'])
            || ! array_is_list($contract['source_accounts'])
            || $contract['source_accounts'] === []) {
            throw new AccountingAccountMappingUnavailableException(
                'Period-close policy must declare a non-empty period-closing.v1 source account contract.'
            );
        }

        $sourceAccounts = [];
        $sourceCodes = [];
        foreach ($contract['source_accounts'] as $source) {
            $code = is_array($source) ? trim((string) ($source['account_code'] ?? '')) : '';
            $category = is_array($source) ? trim((string) ($source['category'] ?? '')) : '';
            if ($code === '' || ! in_array($category, ['revenue', 'expense'], true)) {
                throw new AccountingAccountMappingUnavailableException(
                    'Every period-close source account must declare an account_code and a revenue or expense category.'
                );
            }
            if (isset($sourceCodes[$code])) {
                throw new AccountingAccountMappingUnavailableException(
                    "Period-close source account [{$code}] is declared more than once."
                );
            }
            $sourceCodes[$code] = true;
            $sourceAccounts[] = ['account_code' => $code, 'category' => $category];
        }

        $validSourceCodes = ChartOfAccount::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_parent', false)
            ->whereIn('code', array_keys($sourceCodes))
            ->pluck('code')
            ->all();
        $invalidSourceCodes = array_values(array_diff(array_keys($sourceCodes), $validSourceCodes));
        if ($invalidSourceCodes !== []) {
            throw new AccountingAccountMappingUnavailableException(
                'Period-close source accounts must be active detail accounts: '.implode(', ', $invalidSourceCodes).'.'
            );
        }

        $resolved = $this->mappingResolver->require(
            $companyId,
            (int) $policy['policy_id'],
            $postingDate,
            self::MAPPING_KEY,
            [],
            ['result_clearing', 'retained_earnings'],
        );
        $accounts = [
            'result_clearing' => $resolved['result_clearing']['account_code'],
            'retained_earnings' => $resolved['retained_earnings']['account_code'],
        ];
        if ($accounts['result_clearing'] === $accounts['retained_earnings']) {
            throw new AccountingAccountMappingUnavailableException(
                'Period-close result and retained-earnings mappings must use different accounts.'
            );
        }
        foreach ($accounts as $role => $code) {
            if (isset($sourceCodes[$code])) {
                throw new AccountingAccountMappingUnavailableException(
                    "Period-close target [{$role}] cannot also be a source account."
                );
            }
        }

        $resolutions = [];
        foreach ($resolved as $role => $mapping) {
            $resolutions[] = [
                'account_role' => $role,
                'mapping_id' => $mapping['mapping_id'],
                'account_code' => $mapping['account_code'],
                'contract_hash' => $mapping['contract_hash'],
                'context' => [],
            ];
        }

        return [
            'policy_id' => (int) $policy['policy_id'],
            'policy_contract_hash' => (string) $policy['contract_hash'],
            'mapping_key' => self::MAPPING_KEY,
            'posting_date' => $postingDate,
            'source_accounts' => $sourceAccounts,
            'resolutions' => $resolutions,
            'accounts' => $accounts,
        ];
    }
}
