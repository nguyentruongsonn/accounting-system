<?php

namespace Tests\Unit;

use App\Support\InventoryCostArithmetic;
use PHPUnit\Framework\TestCase;

class InventoryCostArithmeticTest extends TestCase
{
    public function test_weighted_average_math_is_exact_and_uses_defined_rounding(): void
    {
        // 100.00 / 3.00 cannot be represented exactly by an IEEE-754 float.
        $this->assertSame('33.3333', InventoryCostArithmetic::weightedUnitRate('100.00', '3.00'));
        $this->assertSame('33.33', InventoryCostArithmetic::weightedIssueAmount('100.00', '1.00', '3.00'));
        $this->assertSame('66.67', InventoryCostArithmetic::weightedIssueAmount('100.00', '2.00', '3.00'));
    }

    public function test_fractional_quantity_and_large_amount_do_not_route_through_float(): void
    {
        $this->assertSame('3002399751580331.46', InventoryCostArithmetic::weightedIssueAmount(
            '9007199254740994.37',
            '1.00',
            '3.00',
        ));
        $this->assertSame('0.01', InventoryCostArithmetic::amountForQuantityAtRate('0.01', '0.5000'));
    }
}
