<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\ApprovedAccountMapping;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesReturn;
use App\Support\DecimalMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Resolves owner-approved GL accounts for commercial returns and discounts.
 *
 * Adjustment posting used to stop at a permanent "mapping unavailable" guard
 * and, in the legacy branch, silently used hard-coded accounts.  This gate
 * keeps the source account as explicit context and only replaces it with an
 * active mapping approved for the tenant, policy and posting date.
 */
final class CommercialAdjustmentAccountMappingPostingGate
{
    public function __construct(private readonly ApprovedAccountMappingResolver $resolver) {}

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public function requireSatisfied(Model $adjustment, array $policy): array
    {
        $descriptor = self::descriptor($adjustment);
        $adjustment->loadMissing('lines');
        if ($adjustment->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'Chứng từ điều chỉnh phải có ít nhất một dòng trước khi ghi sổ.',
            ]);
        }

        $postingDate = ($adjustment->getAttribute('accounting_date') ?? $adjustment->getAttribute('voucher_date') ?? now())->toDateString();
        $accounts = [];
        $resolutions = [];

        $resolve = function (string $role, ?Model $line, string $sourceAccount) use ($adjustment, $policy, $descriptor, $postingDate, &$accounts, &$resolutions): string {
            if (trim($sourceAccount) === '') {
                throw ValidationException::withMessages([
                    'account_mappings' => 'Chứng từ điều chỉnh chưa có đủ tài khoản nguồn để đối chiếu mapping.',
                ]);
            }
            $context = ApprovedAccountMapping::canonicalize(self::contextFor($adjustment, $line, $role, $sourceAccount));
            $key = $role.'|'.ApprovedAccountMapping::contextHash($context);
            if (! isset($accounts[$key])) {
                $mapping = $this->resolver->require(
                    (int) $adjustment->getAttribute('company_id'),
                    (int) $policy['policy_id'],
                    $postingDate,
                    $descriptor['mapping_key'],
                    $context,
                    [$role],
                )[$role];
                $accounts[$key] = (string) $mapping['account_code'];
                $resolutions[] = [
                    'account_role' => $role,
                    'mapping_id' => $mapping['mapping_id'],
                    'account_code' => $mapping['account_code'],
                    'contract_hash' => $mapping['contract_hash'],
                    'context' => $context,
                ];
            }

            return $accounts[$key];
        };

        $paymentAccount = self::legacySettlementAccount($adjustment);
        $settlementRole = $descriptor['settlement_role'];
        $resolve($settlementRole, null, $paymentAccount);

        foreach ($adjustment->lines as $line) {
            if ($descriptor['line_role'] !== null) {
                $alternate = $descriptor['line_source_field'] === 'inventory_account'
                    ? $line->getAttribute('cogs_debit_account')
                    : ($descriptor['line_source_field'] === 'cogs_account' ? $line->getAttribute('cogs_credit_account') : null);
                $source = self::sourceAccount($line, $descriptor['line_source_field'], $descriptor['line_fallback'], $alternate);
                $resolve($descriptor['line_role'], $line, $source);
            }
            if (DecimalMoney::compare((string) ($line->getRawOriginal('tax_amount') ?? DecimalMoney::ZERO), DecimalMoney::ZERO) > 0) {
                $source = self::sourceAccount($line, 'tax_account', $descriptor['tax_fallback']);
                $resolve($descriptor['tax_role'], $line, $source);
            }
            if ($descriptor['stock_roles'] !== [] && self::hasPositiveCogs($line)) {
                foreach ($descriptor['stock_roles'] as $role => $field) {
                    $alternate = $field === 'inventory_account'
                        ? $line->getAttribute('cogs_debit_account')
                        : ($field === 'cogs_account' ? $line->getAttribute('cogs_credit_account') : null);
                    $source = self::sourceAccount($line, $field, $descriptor['stock_fallbacks'][$role], $alternate);
                    $resolve($role, $line, $source);
                }
            }
        }

        return [
            'gate' => 'enforced',
            'policy_id' => (int) $policy['policy_id'],
            'policy_contract_hash' => (string) $policy['contract_hash'],
            'posting_date' => $postingDate,
            'mapping_key' => $descriptor['mapping_key'],
            'resolutions' => $resolutions,
            'accounts' => $accounts,
        ];
    }

    /** @param array<string,mixed> $lineage @param array<string,mixed> $context */
    public static function accountFor(array $lineage, string $role, Model $adjustment, ?Model $line, string $sourceAccount): string
    {
        $context = ApprovedAccountMapping::canonicalize(self::contextFor($adjustment, $line, $role, $sourceAccount));
        $key = $role.'|'.ApprovedAccountMapping::contextHash($context);
        if (! isset($lineage['accounts'][$key])) {
            throw new LogicException('Resolved commercial adjustment account-mapping evidence is incomplete.');
        }

        return (string) $lineage['accounts'][$key];
    }

    /** @return array<string,mixed> */
    public static function descriptor(Model $adjustment): array
    {
        return match ($adjustment::class) {
            PurchaseReturn::class => [
                'mapping_key' => 'purchase.return', 'voucher_type' => SystemVoucherType::PURCHASE_RETURN,
                'settlement_role' => 'settlement_debit', 'line_role' => 'inventory_credit',
                'line_source_field' => 'credit_account', 'line_fallback' => '1561',
                'tax_role' => 'input_vat_reduction', 'tax_fallback' => '1331', 'stock_roles' => [], 'stock_fallbacks' => [],
            ],
            PurchaseDiscount::class => [
                'mapping_key' => 'purchase.discount', 'voucher_type' => SystemVoucherType::PURCHASE_DISCOUNT,
                'settlement_role' => 'settlement_debit', 'line_role' => 'inventory_credit',
                'line_source_field' => 'credit_account', 'line_fallback' => '1561',
                'tax_role' => 'input_vat_reduction', 'tax_fallback' => '1331', 'stock_roles' => [], 'stock_fallbacks' => [],
            ],
            SalesReturn::class => [
                'mapping_key' => 'sales.return', 'voucher_type' => SystemVoucherType::SALES_RETURN,
                'settlement_role' => 'settlement_credit', 'line_role' => 'revenue_reduction',
                'line_source_field' => 'debit_account', 'line_fallback' => '5212',
                'tax_role' => 'output_vat_reduction', 'tax_fallback' => '33311',
                'stock_roles' => ['inventory_debit' => 'inventory_account', 'cogs_credit' => 'cogs_account'],
                'stock_fallbacks' => ['inventory_debit' => '1561', 'cogs_credit' => '632'],
            ],
            SalesDiscount::class => [
                'mapping_key' => 'sales.discount', 'voucher_type' => SystemVoucherType::SALES_DISCOUNT,
                'settlement_role' => 'settlement_credit', 'line_role' => 'revenue_reduction',
                'line_source_field' => 'debit_account', 'line_fallback' => '5213',
                'tax_role' => 'output_vat_reduction', 'tax_fallback' => '33311', 'stock_roles' => [], 'stock_fallbacks' => [],
            ],
            default => throw new LogicException('Unsupported commercial adjustment document.'),
        };
    }

    /** @return array<string,int|string|bool> */
    public static function contextFor(Model $adjustment, ?Model $line, string $role, string $sourceAccount): array
    {
        $descriptor = self::descriptor($adjustment);
        $context = [
            'voucher_family' => $descriptor['mapping_key'],
            'voucher_type' => (string) ($adjustment->getAttribute('voucher_type') ?? $descriptor['voucher_type']->value),
            'entry' => $role,
            'payment_method' => strtolower((string) ($adjustment->getAttribute('payment_method') ?? '')),
            'source_account_code' => $sourceAccount,
        ];
        if ($line !== null) {
            $context['item_id'] = (int) ($line->getAttribute('item_id') ?? 0);
            $context['warehouse_id'] = (int) ($line->getAttribute('warehouse_id') ?? $adjustment->getAttribute('warehouse_id') ?? 0);
        }

        return $context;
    }

    private static function sourceAccount(Model $line, string $field, string $fallback, mixed $alternate = null): string
    {
        $value = $line->getAttribute($field) ?: $alternate;
        if ($value === null || trim((string) $value) === '') {
            throw ValidationException::withMessages([
                $field => 'Tài khoản nguồn phải được khai báo rõ trước khi đối chiếu mapping được phê duyệt.',
            ]);
        }

        return trim((string) $value);
    }

    private static function legacySettlementAccount(Model $adjustment): string
    {
        $method = strtolower((string) ($adjustment->getAttribute('payment_method') ?? ''));
        if ($method === 'bank') {
            return '1121';
        }
        if ($method === 'cash') {
            return '1111';
        }

        return $adjustment instanceof PurchaseReturn || $adjustment instanceof PurchaseDiscount ? '331' : '131';
    }

    private static function hasPositiveCogs(Model $line): bool
    {
        $value = $line->getRawOriginal('cogs_amount') ?? $line->getRawOriginal('cogs_price') ?? $line->getRawOriginal('cogs_unit_price');

        return $value !== null && DecimalMoney::compare((string) $value, DecimalMoney::ZERO) > 0;
    }
}
