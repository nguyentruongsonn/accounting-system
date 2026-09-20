<?php

namespace Tests\Unit;

use App\Services\PurchaseInvoiceAmountCalculator;
use Tests\TestCase;

final class PurchaseInvoiceAmountCalculatorTest extends TestCase
{
    public function test_line_amounts_are_calculated_from_quantity_rates_and_expense(): void
    {
        $amounts = app(PurchaseInvoiceAmountCalculator::class)->calculate([
            'quantity' => '2.5',
            'unit_price' => '10',
            'discount_rate' => '10',
            'tax_rate' => '8',
            'purchase_expense' => '5',
        ]);

        $this->assertSame('2.50', $amounts['quantity']);
        $this->assertSame('10.00', $amounts['unit_price']);
        $this->assertSame('25.00', $amounts['amount']);
        $this->assertSame('2.50', $amounts['discount']);
        $this->assertSame('1.80', $amounts['tax']);
        $this->assertSame('5.00', $amounts['expense']);
        $this->assertSame('27.50', $amounts['stock_value']);
    }

    public function test_explicit_amounts_are_preserved_after_money_normalization(): void
    {
        $amounts = app(PurchaseInvoiceAmountCalculator::class)->calculate([
            'quantity' => '1',
            'unit_price' => '12.30',
            'amount' => '12.30',
            'discount_amount' => '1.20',
            'tax_amount' => '0.11',
            'purchase_expense' => '0.00',
            'stock_value' => '11.10',
        ]);

        $this->assertSame('12.30', $amounts['amount']);
        $this->assertSame('1.20', $amounts['discount']);
        $this->assertSame('0.11', $amounts['tax']);
        $this->assertSame('0.00', $amounts['expense']);
        $this->assertSame('11.10', $amounts['stock_value']);
    }
}
