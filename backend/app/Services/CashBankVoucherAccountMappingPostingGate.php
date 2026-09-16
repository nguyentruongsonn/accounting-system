<?php

namespace App\Services;

use App\Models\ApprovedAccountMapping;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Owner-approved account selection for the four cash/bank voucher families.
 *
 * This control does not derive accounts from business prose.  It preserves the
 * source voucher's already persisted debit/credit account choices and requires
 * an explicit approved mapping for each role/context before posting.
 */
final class CashBankVoucherAccountMappingPostingGate
{
    public const MAPPING_KEY = 'cash_bank.voucher';

    public function __construct(private readonly ApprovedAccountMappingResolver $resolver) {}

    /**
     * @param array<string,mixed> $policy
     * @return array{gate:string,policy_id:int,policy_contract_hash:string,posting_date:string,mapping_key:string,resolutions:list<array<string,mixed>>,accounts:array<string,string>}
     */
    public function requireSatisfied(Model $voucher, array $policy): array
    {
        $voucher->loadMissing('lines');
        $postingDate = ($voucher->getAttribute('posting_date') ?? $voucher->getAttribute('voucher_date') ?? now())->toDateString();
        $accounts = [];
        $resolutions = [];

        foreach ($voucher->lines as $line) {
            foreach (['debit' => (string) $line->debit_account, 'credit' => (string) $line->credit_account] as $role => $sourceAccount) {
                if (trim($sourceAccount) === '') {
                    throw new LogicException('Cash/bank account-mapping control requires explicit persisted debit and credit accounts.');
                }
                $context = self::contextFor($voucher, $sourceAccount);
                $canonical = ApprovedAccountMapping::canonicalize($context);
                $cacheKey = $role.'|'.ApprovedAccountMapping::contextHash($canonical);
                if (isset($accounts[$cacheKey])) continue;

                $mapping = $this->resolver->require(
                    (int) $voucher->getAttribute('company_id'), (int) $policy['policy_id'], $postingDate,
                    self::MAPPING_KEY, $canonical, [$role],
                )[$role];
                $accounts[$cacheKey] = $mapping['account_code'];
                $resolutions[] = [
                    'account_role' => $role,
                    'mapping_id' => $mapping['mapping_id'],
                    'account_code' => $mapping['account_code'],
                    'contract_hash' => $mapping['contract_hash'],
                    'context' => $canonical,
                ];
            }
        }

        return [
            'gate' => 'enforced', 'policy_id' => (int) $policy['policy_id'],
            'policy_contract_hash' => (string) $policy['contract_hash'], 'posting_date' => $postingDate,
            'mapping_key' => self::MAPPING_KEY, 'resolutions' => $resolutions, 'accounts' => $accounts,
        ];
    }

    /** @param array<string,mixed> $lineage */
    public static function accountFor(array $lineage, Model $voucher, string $role, string $sourceAccount): string
    {
        $key = $role.'|'.ApprovedAccountMapping::contextHash(ApprovedAccountMapping::canonicalize(self::contextFor($voucher, $sourceAccount)));
        if (! isset($lineage['accounts'][$key])) {
            throw new LogicException('Resolved cash/bank account-mapping evidence is incomplete.');
        }
        return (string) $lineage['accounts'][$key];
    }

    /** @return array{voucher_family:string,voucher_type:string,voucher_reason:string,source_account_code:string} */
    public static function contextFor(Model $voucher, string $sourceAccount): array
    {
        $family = match ($voucher::class) {
            \App\Models\CashReceipt::class => 'cash_receipt',
            \App\Models\CashPayment::class => 'cash_payment',
            \App\Models\BankReceipt::class => 'bank_receipt',
            \App\Models\BankPayment::class => 'bank_payment',
            default => throw new LogicException('This document is not a supported cash/bank voucher.'),
        };

        return [
            'voucher_family' => $family,
            'voucher_type' => (string) ($voucher->getAttribute('voucher_type') ?? ''),
            // Cash headers expose reason; bank headers expose description. Both
            // are existing persisted fields, normalized into one context key.
            'voucher_reason' => (string) ($voucher->getAttribute('reason') ?? $voucher->getAttribute('description') ?? ''),
            'source_account_code' => $sourceAccount,
        ];
    }
}
