<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact transport contract for monetary fields emitted by management-report
 * adapters.
 *
 * An adapter must explicitly declare its amount fields. This prevents a new
 * report from silently sending a database DECIMAL value through a PHP float
 * merely because the field happened to be named `amount` or `total`.
 *
 * This class is deliberately a transport/validation boundary. It does not
 * infer currency, quantity precision, valuation method, or a rounding policy.
 */
final class ReportAmountContract
{
    /**
     * Canonicalize one money value for a report response.
     *
     * Floats are rejected at this boundary. Decimal values returned from a
     * DECIMAL database column should already be strings; accepting a float
     * here would preserve a value after it may already have lost precision.
     */
    public static function amount(mixed $value, string $field = 'amount'): string
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Report monetary field [%s] must be an integer or exact decimal string; floats are not permitted.',
                $field,
            ));
        }

        try {
            return DecimalMoney::normalize($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(sprintf(
                'Report monetary field [%s] is invalid: %s',
                $field,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    /**
     * Return a report row with every declared monetary field canonicalized.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $amountFields
     * @return array<string, mixed>
     */
    public static function row(array $row, array $amountFields): array
    {
        self::assertAmountFields($amountFields);

        foreach ($amountFields as $field) {
            if (! array_key_exists($field, $row)) {
                throw new InvalidArgumentException(sprintf(
                    'Report row is missing declared monetary field [%s].',
                    $field,
                ));
            }

            $row[$field] = self::amount($row[$field], $field);
        }

        return $row;
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>  $amountFields
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $rows, array $amountFields): array
    {
        $result = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException(sprintf(
                    'Report row at index [%s] must be an array.',
                    (string) $index,
                ));
            }

            $result[] = self::row($row, $amountFields);
        }

        return $result;
    }

    /** @param iterable<int|string> $values */
    public static function sum(iterable $values, string $field = 'amount'): string
    {
        $total = DecimalMoney::ZERO;
        foreach ($values as $value) {
            $total = DecimalMoney::add($total, self::amount($value, $field));
        }

        return $total;
    }

    /** @param list<string> $amountFields */
    private static function assertAmountFields(array $amountFields): void
    {
        $seen = [];
        foreach ($amountFields as $field) {
            if (! is_string($field) || trim($field) === '') {
                throw new InvalidArgumentException('Declared report monetary fields must be non-empty strings.');
            }

            if (isset($seen[$field])) {
                throw new InvalidArgumentException(sprintf(
                    'Declared report monetary field [%s] appears more than once.',
                    $field,
                ));
            }

            $seen[$field] = true;
        }
    }
}
