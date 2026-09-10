<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Support\DecimalMoney;

/**
 * Resolves the owner-approved account choices for a purchase posting.
 *
 * The context deliberately includes the pre-existing source classification.
 * It is not a statutory account-selection algorithm: the tenant must approve
 * every resulting role/context before this controlled rollout is enabled.
 */
final class PurchaseInvoiceAccountMappingPostingGate
{
    public const MAPPING_KEY = 'purchase.invoice';

    public function __construct(private readonly ApprovedAccountMappingResolver $resolver) {}

    /**
     * @param array<string,mixed> $policy
     * @return array{gate:string,policy_id:int,policy_contract_hash:string,posting_date:string,mapping_key:string,resolutions:array<string,array<string,mixed>>,accounts:array<string,string>}
     */
    public function requireSatisfied(PurchaseInvoice $invoice, array $policy): array
    {
        $postingDate = ($invoice->accounting_date ?? $invoice->invoice_date ?? now())->toDateString();
        $accounts = [];
        $resolutions = [];

        $resolve = function (string $identifier, string $role, array $context) use ($invoice, $policy, $postingDate, &$accounts, &$resolutions): string {
            $canonicalContext = \App\Models\ApprovedAccountMapping::canonicalize($context);
            $cacheKey = $role.'|'.\App\Models\ApprovedAccountMapping::contextHash($canonicalContext);
            if (! isset($accounts[$cacheKey])) {
                $mapping = $this->resolver->require(
                    (int) $invoice->company_id,
                    (int) $policy['policy_id'],
                    $postingDate,
                    self::MAPPING_KEY,
                    $canonicalContext,
                    [$role],
                )[$role];
                $accounts[$cacheKey] = $mapping['account_code'];
                $resolutions[$cacheKey] = [
                    'identifier' => $identifier,
                    'account_role' => $role,
                    'mapping_id' => $mapping['mapping_id'],
                    'account_code' => $mapping['account_code'],
                    'contract_hash' => $mapping['contract_hash'],
                    'context' => $canonicalContext,
                ];
            }

            return $accounts[$cacheKey];
        };

        $payableAccount = $this->legacySettlementAccount($invoice);
        $resolve('settlement_credit', 'settlement_credit', [
            'entry' => 'settlement_credit',
            'payment_method' => (string) $invoice->payment_method,
            'payment_status' => (string) $invoice->status,
            'source_account_code' => $payableAccount,
        ]);

        foreach ($invoice->lines as $line) {
            $debit = $line->debit_account ?: $this->legacyPurchaseDebitAccount($invoice);
            $resolve('purchase_debit', 'purchase_debit', [
                'entry' => 'purchase_debit',
                'voucher_type' => (string) $invoice->voucher_type,
                'source_account_code' => (string) $debit,
            ]);

            if (DecimalMoney::compare((string) ($line->getRawOriginal('tax_amount') ?? DecimalMoney::ZERO), DecimalMoney::ZERO) > 0) {
                $resolve('input_vat', 'input_vat', [
                    'entry' => 'input_vat',
                    'source_account_code' => (string) ($line->tax_account ?: '1331'),
                ]);
            }
            if (DecimalMoney::compare((string) ($line->getRawOriginal('import_tax_amount') ?? DecimalMoney::ZERO), DecimalMoney::ZERO) > 0) {
                $resolve('import_tax_payable', 'import_tax_payable', [
                    'entry' => 'import_tax_payable',
                    'source_account_code' => '3333',
                ]);
            }
        }

        return [
            'gate' => 'enforced',
            'policy_id' => (int) $policy['policy_id'],
            'policy_contract_hash' => (string) $policy['contract_hash'],
            'posting_date' => $postingDate,
            'mapping_key' => self::MAPPING_KEY,
            'resolutions' => array_values($resolutions),
            'accounts' => $accounts,
        ];
    }

    /** @param array<string,mixed> $lineage */
    public static function accountFor(array $lineage, string $role, array $context): string
    {
        $key = $role.'|'.\App\Models\ApprovedAccountMapping::contextHash(\App\Models\ApprovedAccountMapping::canonicalize($context));
        if (! isset($lineage['accounts'][$key])) {
            throw new \LogicException('Resolved purchase account-mapping evidence is incomplete.');
        }
        return $lineage['accounts'][$key];
    }

    private function legacySettlementAccount(PurchaseInvoice $invoice): string
    {
        if ($invoice->payment_method === 'cash' || $invoice->status === 'Paid') return '1111';
        return $invoice->payment_method === 'bank' ? '1121' : '331';
    }

    private function legacyPurchaseDebitAccount(PurchaseInvoice $invoice): string
    {
        return in_array($invoice->voucher_type, ['domestic_direct', 'import_direct'], true) ? '642' : '1561';
    }
}
