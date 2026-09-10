<?php

namespace App\Services;

use App\Support\DecimalMoney;
use Illuminate\Validation\ValidationException;

/**
 * Validates account evidence at the draft intake boundary for commercial
 * documents.
 *
 * This class deliberately does not select statutory accounts.  It only makes
 * an explicit caller-supplied account mandatory when the corresponding
 * production posting-mapping control is enabled.  The legacy test harness
 * can opt out through the existing posting-mapping flags; that mode is a
 * diagnostic compatibility mode and must never be used in production.
 */
final class CommercialDraftAccountEvidenceGate
{
    /**
     * @param array<int|string,array<string,mixed>> $lines
     * @param array<string,mixed> $context
     */
    public static function assertSatisfied(string $documentType, array $lines, array $context = []): void
    {
        if (! self::enabled($documentType)) {
            return;
        }

        $errors = [];
        $taxApplicable = static fn (array $line): bool => self::positive($line['tax_amount'] ?? null)
            || (self::positive($line['tax_rate'] ?? null) && self::positive(self::lineAmount($line)));

        foreach ($lines as $index => $line) {
            $prefix = 'lines.'.$index;

            foreach (['debit_account', 'credit_account'] as $field) {
                if (! self::present($line[$field] ?? null)) {
                    $errors[$prefix.'.'.$field][] = 'Account evidence is required; the system will not invent a legacy account default.';
                }
            }

            // VAT account is required only when this line actually carries
            // VAT. A zero-tax line does not need a VAT account classification.
            if ($taxApplicable($line) && ! self::present($line['tax_account'] ?? null)) {
                $errors[$prefix.'.tax_account'][] = 'A tax account is required when the line has a positive tax amount/rate.';
            }

            if ($documentType === 'sales_invoice' && ! empty($context['is_export_slip'])) {
                // Sales posting requires both COGS roles for an export/delivery
                // slip, even when the current cost amount is zero and may be
                // derived later by the posting path.
                if (! self::present($line['cogs_account'] ?? null) && ! self::present($line['cogs_debit_account'] ?? null)) {
                    $errors[$prefix.'.cogs_account'][] = 'COGS debit account evidence is required for an export/delivery slip.';
                }
                if (! self::present($line['inventory_account'] ?? null) && ! self::present($line['cogs_credit_account'] ?? null)) {
                    $errors[$prefix.'.inventory_account'][] = 'Inventory credit account evidence is required for an export/delivery slip.';
                }
            }

            if ($documentType === 'sales_return' && ! empty($context['inward']) && self::positive(self::cogsAmount($line))) {
                if (! self::present($line['inventory_account'] ?? null) && ! self::present($line['cogs_debit_account'] ?? null)) {
                    $errors[$prefix.'.inventory_account'][] = 'Inventory account evidence is required when returned stock creates a positive COGS reversal.';
                }
                if (! self::present($line['cogs_account'] ?? null) && ! self::present($line['cogs_credit_account'] ?? null)) {
                    $errors[$prefix.'.cogs_account'][] = 'COGS account evidence is required when returned stock creates a positive COGS reversal.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Persist explicit evidence. When the gate is enabled, a missing value is
     * null rather than a synthesized historical account. The legacy value is
     * retained only for the explicitly disabled diagnostic harness.
     */
    public static function account(array $line, string $field, string $documentType, ?string $legacy = null): ?string
    {
        $value = $line[$field] ?? null;
        if (self::present($value)) {
            return (string) $value;
        }

        return self::enabled($documentType) ? null : $legacy;
    }

    /**
     * A header-only update cannot prove that legacy diagnostic defaults in the
     * persisted lines are owner-supplied evidence. Production therefore
     * requires the caller to resubmit the complete line evidence snapshot.
     */
    public static function assertUpdateLinesProvided(string $documentType, ?array $lines): void
    {
        if (self::enabled($documentType) && $lines === null) {
            throw ValidationException::withMessages([
                'lines' => 'Line account evidence must be resubmitted for a production draft update; header-only updates cannot certify legacy mappings.',
            ]);
        }
    }

    public static function enabled(string $documentType): bool
    {
        return match ($documentType) {
            'sales_invoice' => (bool) config('accounting.enforce_sales_invoice_posting_account_mappings', true),
            'purchase_invoice' => (bool) config('accounting.enforce_purchase_invoice_posting_account_mappings', true),
            'sales_return', 'purchase_return', 'sales_discount', 'purchase_discount' => (bool) config('accounting.enforce_return_discount_posting_account_mappings', true),
            default => throw new \InvalidArgumentException('Unsupported commercial document type: '.$documentType),
        };
    }

    private static function present(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private static function positive(mixed $value): bool
    {
        if (! is_numeric($value)) {
            return false;
        }

        try {
            return DecimalMoney::compare((string) $value, DecimalMoney::ZERO) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function lineAmount(array $line): mixed
    {
        if (array_key_exists('amount', $line)) {
            return $line['amount'];
        }

        // Services derive the taxable base from quantity × unit price when
        // amount is omitted. A present zero discount must not mask that
        // positive base and let a missing tax account through.
        if (self::positive($line['quantity'] ?? null) && self::positive($line['unit_price'] ?? null)) {
            return '1.00';
        }

        return array_key_exists('discount_amount', $line)
            ? $line['discount_amount']
            : DecimalMoney::ZERO;
    }

    private static function cogsAmount(array $line): mixed
    {
        if (array_key_exists('cogs_amount', $line)) {
            return $line['cogs_amount'];
        }

        return self::positive($line['quantity'] ?? null) && self::positive($line['cogs_unit_price'] ?? $line['cogs_price'] ?? null)
            ? '1.00'
            : DecimalMoney::ZERO;
    }
}
