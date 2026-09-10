<?php

namespace Tests\Unit;

use App\Services\SalesOrderService;
use PHPUnit\Framework\TestCase;

class SalesOrderArithmeticTest extends TestCase
{
    public function test_line_amounts_use_exact_money_arithmetic(): void
    {
        $service = new SalesOrderService;
        $method = new \ReflectionMethod($service, 'lineAmounts');
        $method->setAccessible(true);

        $amounts = $method->invoke($service, [
            'quantity' => '3.00',
            'unit_price' => '1234.56',
            'discount_rate' => '1.00',
            'tax_rate' => '10.00',
        ]);

        $this->assertSame('3.00', $amounts['quantity']);
        $this->assertSame('1234.56', $amounts['unit_price']);
        $this->assertSame('3703.68', $amounts['amount']);
        $this->assertSame('37.04', $amounts['discount']);
        $this->assertSame('3666.64', $amounts['net_amount']);
        $this->assertSame('366.66', $amounts['tax']);
        $this->assertSame('4033.30', $amounts['total_amount']);
    }
}
