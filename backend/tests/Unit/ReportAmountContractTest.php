<?php

namespace Tests\Unit;

use App\Support\ReportAmountContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReportAmountContractTest extends TestCase
{
    public function test_it_canonicalizes_declared_report_amounts_without_float_coercion(): void
    {
        $row = ReportAmountContract::row([
            'supplier_code' => 'NCC-001',
            'total_due' => '9999999999999999.99',
            'current' => '0.10',
            'days_1_30' => 2,
        ], ['total_due', 'current', 'days_1_30']);

        $this->assertSame('NCC-001', $row['supplier_code']);
        $this->assertSame('9999999999999999.99', $row['total_due']);
        $this->assertSame('0.10', $row['current']);
        $this->assertSame('2.00', $row['days_1_30']);
        $this->assertSame('10000000000000000.09', ReportAmountContract::sum([
            $row['total_due'],
            $row['current'],
        ], 'total_due'));
    }

    public function test_it_canonicalizes_each_declared_field_for_multiple_rows(): void
    {
        $rows = ReportAmountContract::rows([
            ['account_code' => '111', 'actual' => '1.20', 'variance' => '-0.20'],
            ['account_code' => '112', 'actual' => '0', 'variance' => '0.00'],
        ], ['actual', 'variance']);

        $this->assertSame([
            ['account_code' => '111', 'actual' => '1.20', 'variance' => '-0.20'],
            ['account_code' => '112', 'actual' => '0.00', 'variance' => '0.00'],
        ], $rows);
    }

    public function test_it_rejects_float_transport_values_and_undeclared_contract_errors(): void
    {
        try {
            ReportAmountContract::amount(0.1, 'total_due');
            $this->fail('Expected float report amount to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('total_due', $exception->getMessage());
            $this->assertStringContainsString('floats are not permitted', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing declared monetary field [total_due]');

        ReportAmountContract::row(['supplier_code' => 'NCC-001'], ['total_due']);
    }

    public function test_it_rejects_invalid_or_ambiguous_declared_amount_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('appears more than once');

        ReportAmountContract::row(['amount' => '1.00'], ['amount', 'amount']);
    }
}
