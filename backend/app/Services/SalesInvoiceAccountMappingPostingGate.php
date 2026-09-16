<?php

namespace App\Services;

use App\Models\ApprovedAccountMapping;
use App\Models\SalesInvoice;
use App\Support\DecimalMoney;

/**
 * Resolves explicit, tenant-owner-approved account choices for sales posting.
 *
 * Contexts retain the document's source classification only. This class never
 * decides which statutory accounts are correct: when rollout is active every
 * role/context actually used by the voucher must have exactly one approved
 * owner mapping.
 */
final class SalesInvoiceAccountMappingPostingGate
{
    public const MAPPING_KEY = 'sales.invoice';

    public function __construct(private readonly ApprovedAccountMappingResolver $resolver) {}

    /**
     * @param array<string,mixed> $policy
     * @return array{gate:string,policy_id:int,policy_contract_hash:string,posting_date:string,mapping_key:string,resolutions:array<int,array<string,mixed>>,accounts:array<string,string>}
     */
    public function requireSatisfied(SalesInvoice $invoice, array $policy): array
    {
        $postingDate = ($invoice->accounting_date ?? $invoice->invoice_date ?? now())->toDateString();
        $accounts = [];
        $resolutions = [];
        $resolve = function (string $identifier, string $role, array $context) use ($invoice, $policy, $postingDate, &$accounts, &$resolutions): string {
            $context = ApprovedAccountMapping::canonicalize($context);
            $cacheKey = $role.'|'.ApprovedAccountMapping::contextHash($context);
            if (! isset($accounts[$cacheKey])) {
                $mapping = $this->resolver->require((int) $invoice->company_id, (int) $policy['policy_id'], $postingDate, self::MAPPING_KEY, $context, [$role])[$role];
                $accounts[$cacheKey] = $mapping['account_code'];
                $resolutions[$cacheKey] = [
                    'identifier' => $identifier,
                    'account_role' => $role,
                    'mapping_id' => $mapping['mapping_id'],
                    'account_code' => $mapping['account_code'],
                    'contract_hash' => $mapping['contract_hash'],
                    'context' => $context,
                ];
            }
            return $accounts[$cacheKey];
        };

        $resolve('settlement_debit', 'settlement_debit', [
            'entry' => 'settlement_debit',
            'payment_method' => strtolower((string) $invoice->payment_method),
            'payment_status' => strtolower((string) $invoice->payment_status),
            'document_status' => strtolower((string) $invoice->status),
            'source_account_code' => self::legacySettlementAccount($invoice),
        ]);

        foreach ($invoice->lines as $line) {
            $resolve('revenue_credit', 'revenue_credit', [
                'entry' => 'revenue_credit',
                'voucher_type' => (string) $invoice->voucher_type,
                'source_account_code' => (string) ($line->credit_account ?: '5111'),
            ]);
            if (DecimalMoney::compare((string) ($line->getRawOriginal('tax_amount') ?? DecimalMoney::ZERO), DecimalMoney::ZERO) > 0) {
                $resolve('output_vat', 'output_vat', [
                    'entry' => 'output_vat',
                    'source_account_code' => (string) ($line->tax_account ?: '33311'),
                ]);
            }
            // The posting service may derive COGS from quantity × unit cost
            // when the persisted amount is zero. Require both roles for every
            // export-slip line so that derivation cannot bypass this gate.
            if ($invoice->is_export_slip) {
                $resolve('cogs_debit', 'cogs_debit', [
                    'entry' => 'cogs_debit',
                    'source_account_code' => (string) ($line->cogs_account ?: ($line->cogs_debit_account ?: '632')),
                ]);
                $resolve('inventory_credit', 'inventory_credit', [
                    'entry' => 'inventory_credit',
                    'source_account_code' => (string) ($line->inventory_account ?: ($line->cogs_credit_account ?: '1561')),
                ]);
            }
        }

        return ['gate' => 'enforced', 'policy_id' => (int) $policy['policy_id'], 'policy_contract_hash' => (string) $policy['contract_hash'], 'posting_date' => $postingDate, 'mapping_key' => self::MAPPING_KEY, 'resolutions' => array_values($resolutions), 'accounts' => $accounts];
    }

    /** @param array<string,mixed> $lineage @param array<string,mixed> $context */
    public static function accountFor(array $lineage, string $role, array $context): string
    {
        $key = $role.'|'.ApprovedAccountMapping::contextHash(ApprovedAccountMapping::canonicalize($context));
        if (! isset($lineage['accounts'][$key])) throw new \LogicException('Resolved sales account-mapping evidence is incomplete.');
        return $lineage['accounts'][$key];
    }

    public static function legacySettlementAccount(SalesInvoice $invoice): string
    {
        $paymentMethod = strtolower((string) $invoice->payment_method);
        $status = strtolower((string) $invoice->status);
        $paymentStatus = strtolower((string) $invoice->payment_status);
        if ($paymentMethod === 'bank') return '1121';
        if ($paymentMethod === 'cash' || $status === 'paid' || $paymentStatus === 'paid') return '1111';
        return '131';
    }
}
