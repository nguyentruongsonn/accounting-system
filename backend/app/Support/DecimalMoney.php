<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Exact fixed-scale arithmetic for amounts stored as DECIMAL(*, 2).
 *
 * Public methods deliberately return canonical decimal strings so JSON
 * serialization never routes legal monetary values through IEEE-754 floats.
 */
final class DecimalMoney
{
    public const ZERO = '0.00';

    public static function normalize(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            // Compatibility at system boundaries only. Persisted accounting
            // amounts are strings from DECIMAL columns.
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Money value must be finite.');
            }
            $value = number_format($value, 2, '.', '');
        } elseif (! is_string($value)) {
            throw new InvalidArgumentException('Money value must be a decimal string or integer.');
        }

        $value = trim($value);
        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $matches)
            || (($matches[2] ?? '') === '' && ($matches[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid decimal money value [{$value}].");
        }

        $integer = ltrim($matches[2] === '' ? '0' : $matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new InvalidArgumentException("Money value [{$value}] exceeds scale 2.");
        }
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $normalized = $integer.'.'.$fraction;

        return ($matches[1] ?? '') === '-' && $normalized !== self::ZERO
            ? '-'.$normalized
            : $normalized;
    }

    public static function add(mixed $left, mixed $right): string
    {
        [$leftNegative, $leftDigits] = self::parts($left);
        [$rightNegative, $rightDigits] = self::parts($right);

        if ($leftNegative === $rightNegative) {
            return self::fromMinor(self::addUnsigned($leftDigits, $rightDigits), $leftNegative);
        }

        $comparison = self::compareUnsigned($leftDigits, $rightDigits);
        if ($comparison === 0) {
            return self::ZERO;
        }

        if ($comparison > 0) {
            return self::fromMinor(self::subtractUnsigned($leftDigits, $rightDigits), $leftNegative);
        }

        return self::fromMinor(self::subtractUnsigned($rightDigits, $leftDigits), $rightNegative);
    }

    public static function subtract(mixed $left, mixed $right): string
    {
        return self::add($left, self::negate($right));
    }

    /** Multiply two money-scale values and round once to cents. */
    public static function multiply(mixed $left, mixed $right): string
    {
        $product = BigDecimal::of(self::normalize($left))
            ->multipliedBy(self::normalize($right))
            ->toScale(2, RoundingMode::HALF_UP)
            ->__toString();

        return self::normalize($product);
    }

    /** Calculate amount × percentage / 100 and round once to cents. */
    public static function percentage(mixed $amount, mixed $rate): string
    {
        $result = BigDecimal::of(self::normalize($amount))
            ->multipliedBy(self::normalize($rate))
            ->dividedBy('100', 2, RoundingMode::HALF_UP)
            ->toScale(2, RoundingMode::HALF_UP)
            ->__toString();

        return self::normalize($result);
    }

    public static function negate(mixed $value): string
    {
        $normalized = self::normalize($value);
        if ($normalized === self::ZERO) {
            return self::ZERO;
        }

        return str_starts_with($normalized, '-') ? substr($normalized, 1) : '-'.$normalized;
    }

    public static function abs(mixed $value): string
    {
        $normalized = self::normalize($value);

        return str_starts_with($normalized, '-') ? substr($normalized, 1) : $normalized;
    }

    public static function compare(mixed $left, mixed $right): int
    {
        [$leftNegative, $leftDigits] = self::parts($left);
        [$rightNegative, $rightDigits] = self::parts($right);

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $comparison = self::compareUnsigned($leftDigits, $rightDigits);

        return $leftNegative ? -$comparison : $comparison;
    }

    public static function maxZero(mixed $value): string
    {
        $normalized = self::normalize($value);

        return self::compare($normalized, self::ZERO) > 0 ? $normalized : self::ZERO;
    }

    /** @param iterable<mixed> $values */
    public static function sum(iterable $values): string
    {
        $total = self::ZERO;
        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return $total;
    }

    /** @return array{bool, string} */
    private static function parts(mixed $value): array
    {
        $normalized = self::normalize($value);
        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;

        return [$negative, ltrim(str_replace('.', '', $unsigned), '0') ?: '0'];
    }

    private static function fromMinor(string $digits, bool $negative): string
    {
        $digits = ltrim($digits, '0') ?: '0';
        $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
        $value = substr($digits, 0, -2).'.'.substr($digits, -2);

        return $negative && $value !== self::ZERO ? '-'.$value : $value;
    }

    private static function compareUnsigned(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }

    private static function addUnsigned(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = ($leftIndex >= 0 ? ord($left[$leftIndex--]) - 48 : 0)
                + ($rightIndex >= 0 ? ord($right[$rightIndex--]) - 48 : 0)
                + $carry;
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return $result;
    }

    /** Subtract unsigned right from unsigned left, where left >= right. */
    private static function subtractUnsigned(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;

        while ($leftIndex >= 0) {
            $digit = ord($left[$leftIndex--]) - 48 - $borrow;
            $subtrahend = $rightIndex >= 0 ? ord($right[$rightIndex--]) - 48 : 0;
            if ($digit < $subtrahend) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) ($digit - $subtrahend).$result;
        }

        return ltrim($result, '0') ?: '0';
    }
}
