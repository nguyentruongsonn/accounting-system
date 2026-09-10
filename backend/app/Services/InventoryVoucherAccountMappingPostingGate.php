<?php

namespace App\Services;

use App\Models\ApprovedAccountMapping;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Owner-approved account selection for inventory receipt and issue lines. */
final class InventoryVoucherAccountMappingPostingGate
{
    public const MAPPING_KEY = 'inventory.voucher';

    public function __construct(private readonly ApprovedAccountMappingResolver $resolver) {}

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public function requireSatisfied(Model $voucher, array $policy): array
    {
        $voucher->loadMissing('lines');
        $postingDate = ($voucher->getAttribute('posting_date') ?? $voucher->getAttribute('voucher_date') ?? now())->toDateString();
        $accounts = [];
        $resolutions = [];

        foreach ($voucher->lines as $line) {
            foreach (['debit' => (string) $line->debit_account, 'credit' => (string) $line->credit_account] as $role => $sourceAccount) {
                if (trim($sourceAccount) === '') {
                    throw ValidationException::withMessages([
                        'account_mappings' => 'Phiếu kho chưa có đủ tài khoản Nợ/Có cho từng dòng. Hãy hoàn thiện mapping hoặc tài khoản trước khi ghi sổ.',
                    ]);
                }
                $context = ApprovedAccountMapping::canonicalize(self::contextFor($voucher, $line, $sourceAccount));
                $key = $role.'|'.ApprovedAccountMapping::contextHash($context);
                if (isset($accounts[$key])) {
                    continue;
                }
                $mapping = $this->resolver->require(
                    (int) $voucher->getAttribute('company_id'),
                    (int) $policy['policy_id'],
                    $postingDate,
                    self::MAPPING_KEY,
                    $context,
                    [$role],
                )[$role];
                $accounts[$key] = $mapping['account_code'];
                $resolutions[] = [
                    'account_role' => $role,
                    'mapping_id' => $mapping['mapping_id'],
                    'account_code' => $mapping['account_code'],
                    'contract_hash' => $mapping['contract_hash'],
                    'context' => $context,
                ];
            }
        }

        return [
            'gate' => 'enforced',
            'policy_id' => (int) $policy['policy_id'],
            'policy_contract_hash' => (string) $policy['contract_hash'],
            'posting_date' => $postingDate,
            'mapping_key' => self::MAPPING_KEY,
            'resolutions' => $resolutions,
            'accounts' => $accounts,
        ];
    }

    /** @param array<string,mixed> $lineage */
    public static function accountFor(array $lineage, Model $voucher, Model $line, string $role, string $sourceAccount): string
    {
        $context = ApprovedAccountMapping::canonicalize(self::contextFor($voucher, $line, $sourceAccount));
        $key = $role.'|'.ApprovedAccountMapping::contextHash($context);
        if (! isset($lineage['accounts'][$key])) {
            throw new LogicException('Resolved inventory account-mapping evidence is incomplete.');
        }

        return (string) $lineage['accounts'][$key];
    }

    /** @return array<string,int|string> */
    public static function contextFor(Model $voucher, Model $line, string $sourceAccount): array
    {
        $family = match ($voucher::class) {
            InventoryReceipt::class => 'inventory_receipt',
            InventoryIssue::class => 'inventory_issue',
            default => throw new LogicException('This document is not a supported inventory voucher.'),
        };

        return [
            'voucher_family' => $family,
            'voucher_type' => (string) ($voucher->getAttribute('voucher_type') ?? ''),
            'item_id' => (int) $line->getAttribute('item_id'),
            'warehouse_id' => (int) ($line->getAttribute('warehouse_id') ?? $voucher->getAttribute('warehouse_id') ?? 0),
            'source_account_code' => $sourceAccount,
        ];
    }
}
