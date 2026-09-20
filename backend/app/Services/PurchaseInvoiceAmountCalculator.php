<?php

namespace App\Services;

use App\Support\DecimalMoney;

/**
 * Pure money rules for one purchase-invoice line.
 *
 * The calculator deliberately returns storage-scale DECIMAL values so the
 * service and database-backed tests share the same rounding behavior.
 */
final class PurchaseInvoiceAmountCalculator
{
    /** @return array{quantity: string, unit_price: string, amount: string, discount: string, tax: string, expense: string, stock_value: string} */
    public function calculate(array $line): array
    {
        $quantity = $this->money($line['quantity'] ?? 1);
        $unitPrice = $this->money($line['unit_price'] ?? 0);
        $amount = array_key_exists('amount', $line)
            ? $this->money($line['amount'])
            : $this->multiplyMoney($quantity, $unitPrice);
        $discount = array_key_exists('discount_amount', $line)
            ? $this->money($line['discount_amount'])
            : $this->percentageOf($amount, $line['discount_rate'] ?? 0);
        $net = DecimalMoney::subtract($amount, $discount);
        $tax = array_key_exists('tax_amount', $line)
            ? $this->money($line['tax_amount'])
            : $this->percentageOf($net, $line['tax_rate'] ?? 0);
        $expense = $this->money($line['purchase_expense'] ?? 0);
        $stockValue = array_key_exists('stock_value', $line)
            ? $this->money($line['stock_value'])
            : DecimalMoney::add($net, $expense);

        return compact('quantity', 'unitPrice', 'amount', 'discount', 'tax', 'expense', 'stockValue') + [
            'unit_price' => $unitPrice,
            'stock_value' => $stockValue,
        ];
    }

    private function money(mixed $value): string
    {
        return DecimalMoney::normalize($value);
    }

    private function multiplyMoney(string $left, string $right): string
    {
        return $this->multiplyMinorWithRounding($left, $right, 2);
    }

    private function percentageOf(string $amount, mixed $rate): string
    {
        return $this->multiplyMinorWithRounding($amount, $this->money($rate), 4);
    }

    private function multiplyMinorWithRounding(string $left, string $right, int $divisorDigits): string
    {
        $left = $this->money($left);
        $right = $this->money($right);
        $negative = str_starts_with($left, '-') !== str_starts_with($right, '-');
        $leftDigits = ltrim(str_replace(['-', '.'], '', $left), '0') ?: '0';
        $rightDigits = ltrim(str_replace(['-', '.'], '', $right), '0') ?: '0';
        $product = $this->multiplyUnsigned($leftDigits, $rightDigits);
        $product = str_pad($product, $divisorDigits + 1, '0', STR_PAD_LEFT);
        $quotient = substr($product, 0, -$divisorDigits);
        $remainder = substr($product, -$divisorDigits);
        if ($remainder >= '5'.str_repeat('0', $divisorDigits - 1)) {
            $quotient = $this->incrementUnsigned($quotient);
        }

        $quotient = ltrim($quotient, '0') ?: '0';
        $minor = str_pad($quotient, 3, '0', STR_PAD_LEFT);
        $result = substr($minor, 0, -2).'.'.substr($minor, -2);

        return $negative && $result !== DecimalMoney::ZERO ? '-'.$result : $result;
    }

    private function multiplyUnsigned(string $left, string $right): string
    {
        $result = '0';
        for ($index = strlen($right) - 1, $zeros = ''; $index >= 0; $index--, $zeros .= '0') {
            $carry = 0;
            $partial = '';
            $digit = ord($right[$index]) - 48;
            for ($inner = strlen($left) - 1; $inner >= 0; $inner--) {
                $value = (ord($left[$inner]) - 48) * $digit + $carry;
                $partial = (string) ($value % 10).$partial;
                $carry = intdiv($value, 10);
            }
            $partial = ($carry > 0 ? (string) $carry : '').$partial.$zeros;
            $result = $this->addUnsigned($result, $partial);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function addUnsigned(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        for ($leftIndex = strlen($left) - 1, $rightIndex = strlen($right) - 1; $leftIndex >= 0 || $rightIndex >= 0 || $carry > 0;) {
            $sum = ($leftIndex >= 0 ? ord($left[$leftIndex--]) - 48 : 0)
                + ($rightIndex >= 0 ? ord($right[$rightIndex--]) - 48 : 0) + $carry;
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return $result;
    }

    private function incrementUnsigned(string $value): string
    {
        return $this->addUnsigned($value, '1');
    }
}
