<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact, fixed-scale arithmetic used by the monthly weighted-average costing
 * path.  Database quantities are DECIMAL(*, 4), accounting amounts are
 * DECIMAL(*, 2), and the calculated display/trace rate is DECIMAL(*, 4).
 *
 * This deliberately does not use PHP floats: a costing run must be
 * reproducible from the same voucher evidence on every execution.
 */
final class InventoryCostArithmetic
{
    public const QUANTITY_SCALE = 4;

    public const ZERO_QUANTITY = '0.00';

    public const ZERO_RATE = '0.0000';

    public static function quantity(mixed $value): string
    {
        $normalized = self::normalize($value, self::QUANTITY_SCALE, 'Quantity');

        // Keep the legacy two-place representation for whole/cent quantities
        // while retaining any meaningful fractional inventory precision.
        [$whole, $fraction] = explode('.', $normalized, 2);
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, 2, '0');

        return $whole.'.'.$fraction;
    }

    public static function rate(mixed $value): string
    {
        return self::normalize($value, 4, 'Unit cost');
    }

    public static function addQuantity(mixed $left, mixed $right): string
    {
        return self::fromRaw(self::addUnsigned(self::rawQuantity($left), self::rawQuantity($right)), self::QUANTITY_SCALE);
    }

    public static function subtractQuantity(mixed $left, mixed $right): string
    {
        $leftRaw = self::rawQuantity($left);
        $rightRaw = self::rawQuantity($right);
        if (self::compareUnsigned($leftRaw, $rightRaw) < 0) {
            throw new InvalidArgumentException('Inventory quantity cannot become negative in the exact weighted-average path.');
        }

        return self::fromRaw(self::subtractUnsigned($leftRaw, $rightRaw), self::QUANTITY_SCALE);
    }

    public static function compareQuantity(mixed $left, mixed $right): int
    {
        return self::compareUnsigned(self::rawQuantity($left), self::rawQuantity($right));
    }

    /** Returns a 4-decimal unit rate, rounded half up. */
    public static function weightedUnitRate(mixed $availableAmount, mixed $availableQuantity): string
    {
        $quantityRaw = self::rawQuantity($availableQuantity);
        if ($quantityRaw === '0') {
            return self::ZERO_RATE;
        }

        $amountRaw = self::raw(DecimalMoney::normalize($availableAmount));

        // (amount cents / quantity ten-thousandths) is the monetary rate.
        // Add six zeros to produce a four-decimal stored/display rate.
        return self::fromRaw(self::divideRounded($amountRaw.str_repeat('0', self::QUANTITY_SCALE + 2), $quantityRaw), 4);
    }

    /**
     * Allocate the exact weighted-average available amount to an issue line.
     * Result is a legal 2-decimal accounting amount, rounded half up once.
     */
    public static function weightedIssueAmount(mixed $availableAmount, mixed $issueQuantity, mixed $availableQuantity): string
    {
        $availableQuantityRaw = self::rawQuantity($availableQuantity);
        if ($availableQuantityRaw === '0') {
            return DecimalMoney::ZERO;
        }

        $numerator = self::multiplyUnsigned(
            self::raw(DecimalMoney::normalize($availableAmount)),
            self::rawQuantity($issueQuantity),
        );

        return self::fromRaw(self::divideRounded($numerator, $availableQuantityRaw), 2);
    }

    /** Multiplies a quantity (4 places) by a unit rate (4 places) to cents. */
    public static function amountForQuantityAtRate(mixed $quantity, mixed $rate): string
    {
        $product = self::multiplyUnsigned(self::rawQuantity($quantity), self::raw(self::rate($rate)));

        // quantity raw is four-place and rate raw is four-place; turn their
        // product into cents by dividing by 10^(4 + 2).
        return self::fromRaw(self::divideRounded($product, '1000000'), 2);
    }

    private static function rawQuantity(mixed $value): string
    {
        return self::raw(self::normalize($value, self::QUANTITY_SCALE, 'Quantity'));
    }

    private static function normalize(mixed $value, int $scale, string $label): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a decimal string or integer.");
        }
        $value = trim($value);
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException("{$label} must be a non-negative exact decimal.");
        }
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new InvalidArgumentException("{$label} exceeds scale {$scale}.");
        }

        return self::fromRaw(ltrim($matches[1].str_pad(substr($fraction, 0, $scale), $scale, '0'), '0') ?: '0', $scale);
    }

    private static function raw(string $value): string
    {
        return ltrim(str_replace('.', '', $value), '0') ?: '0';
    }

    private static function fromRaw(string $raw, int $scale): string
    {
        $raw = ltrim($raw, '0') ?: '0';
        $raw = str_pad($raw, $scale + 1, '0', STR_PAD_LEFT);

        return substr($raw, 0, -$scale).'.'.substr($raw, -$scale);
    }

    private static function divideRounded(string $numerator, string $denominator): string
    {
        [$quotient, $remainder] = self::divideUnsigned($numerator, $denominator);
        if (self::compareUnsigned(self::multiplyUnsigned($remainder, '2'), $denominator) >= 0) {
            $quotient = self::addUnsigned($quotient, '1');
        }

        return $quotient;
    }

    /** @return array{string, string} quotient and remainder */
    private static function divideUnsigned(string $numerator, string $denominator): array
    {
        $numerator = ltrim($numerator, '0') ?: '0';
        $denominator = ltrim($denominator, '0') ?: '0';
        if ($denominator === '0') {
            throw new InvalidArgumentException('Division by zero.');
        }

        $remainder = '0';
        $quotient = '';
        foreach (str_split($numerator) as $digit) {
            $remainder = ltrim($remainder.$digit, '0') ?: '0';
            $digitQuotient = 0;
            while (self::compareUnsigned($remainder, $denominator) >= 0) {
                $remainder = self::subtractUnsigned($remainder, $denominator);
                $digitQuotient++;
            }
            $quotient .= (string) $digitQuotient;
        }

        return [ltrim($quotient, '0') ?: '0', $remainder];
    }

    private static function compareUnsigned(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) === strlen($right) ? (strcmp($left, $right) <=> 0) : (strlen($left) <=> strlen($right));
    }

    private static function addUnsigned(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0 || $j >= 0 || $carry > 0; --$i, --$j) {
            $sum = ($i >= 0 ? ord($left[$i]) - 48 : 0) + ($j >= 0 ? ord($right[$j]) - 48 : 0) + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function subtractUnsigned(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0; --$i, --$j) {
            $value = ord($left[$i]) - 48 - $borrow;
            $sub = $j >= 0 ? ord($right[$j]) - 48 : 0;
            if ($value < $sub) {
                $value += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = ($value - $sub).$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function multiplyUnsigned(string $left, string $right): string
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if ($left === '0' || $right === '0') {
            return '0';
        }
        $digits = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $digits[$i + $j + 1] += (ord($left[$i]) - 48) * (ord($right[$j]) - 48);
            }
        }
        for ($i = count($digits) - 1; $i > 0; $i--) {
            $digits[$i - 1] += intdiv($digits[$i], 10);
            $digits[$i] %= 10;
        }

        return ltrim(implode('', $digits), '0') ?: '0';
    }
}
