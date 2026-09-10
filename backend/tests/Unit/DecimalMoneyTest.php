<?php

namespace Tests\Unit;

use App\Exports\BalanceSheetExport;
use App\Exports\IncomeStatementExport;
use App\Exports\TrialBalanceExport;
use App\Support\DecimalMoney;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class DecimalMoneyTest extends TestCase
{
    public function test_addition_is_exact_for_cents_and_values_beyond_ieee_754_safe_integer(): void
    {
        $this->assertSame('0.30', DecimalMoney::add('0.10', '0.20'));
        $this->assertSame('10000000000000000.00', DecimalMoney::add('9999999999999999.99', '0.01'));
        $this->assertSame('9999999999999999.98', DecimalMoney::subtract('10000000000000000.00', '0.02'));
    }

    public function test_signed_arithmetic_comparison_and_canonical_scale_are_exact(): void
    {
        $this->assertSame('-0.75', DecimalMoney::add('-1.00', '0.25'));
        $this->assertSame('1.25', DecimalMoney::abs('-1.25'));
        $this->assertSame('-1.25', DecimalMoney::negate('1.25'));
        $this->assertSame('0.00', DecimalMoney::maxZero('-0.01'));
        $this->assertSame('12.30', DecimalMoney::normalize('00012.3'));
        $this->assertGreaterThan(0, DecimalMoney::compare('9007199254740993.00', '9007199254740992.99'));
    }

    public function test_non_zero_fraction_beyond_database_scale_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalMoney::normalize('1.001');
    }

    public function test_multiplication_and_percentage_are_exact_and_round_once_at_money_scale(): void
    {
        $this->assertSame('6.66', DecimalMoney::multiply('2.00', '3.33'));
        $this->assertSame('0.01', DecimalMoney::percentage('0.50', '1.00'));
        $this->assertSame('11120017983924509.95', DecimalMoney::multiply('9007199254740.12', '1234.57'));
    }

    public function test_balance_sheet_export_totals_do_not_coerce_decimal_strings_to_float(): void
    {
        $rows = (new BalanceSheetExport([
            'assets' => [
                ['name' => 'A', 'code' => '111', 'end_balance' => '9999999999999999.99', 'start_balance' => '0.00'],
                ['name' => 'B', 'code' => '112', 'end_balance' => '0.01', 'start_balance' => '0.00'],
            ],
            'liabilities' => [
                ['name' => 'L', 'code' => '331', 'end_balance' => '1.01', 'start_balance' => '0.00'],
            ],
            'equity' => [
                ['name' => 'E', 'code' => '411', 'end_balance' => '2.02', 'start_balance' => '0.00'],
            ],
        ]))->array();

        $this->assertSame('10000000000000000.00', $rows[3][3]);
        $this->assertSame('3.03', $rows[array_key_last($rows)][3]);
    }

    public function test_excel_exports_keep_monetary_values_as_exact_text_without_float_coercion(): void
    {
        $exports = [
            [new BalanceSheetExport(['assets' => [], 'liabilities' => [], 'equity' => []]), 'D'],
            [new TrialBalanceExport([]), 'C'],
            [new IncomeStatementExport([]), 'D'],
        ];

        foreach ($exports as [$export, $moneyColumn]) {
            $sheet = (new Spreadsheet)->getActiveSheet();
            $large = $sheet->getCell($moneyColumn.'4');
            $export->bindValue($large, '9999999999999999.99');
            $largeType = $large->getDataType();
            $largeValue = $large->getValue();

            $cents = $sheet->getCell($moneyColumn.'5');
            $export->bindValue($cents, '0.10');
            $centsType = $cents->getDataType();
            $centsValue = $cents->getValue();

            $heading = $sheet->getCell($moneyColumn.'3');
            $export->bindValue($heading, 'Số cuối kỳ');
            $headingType = $heading->getDataType();
            $headingValue = $heading->getValue();

            $this->assertSame(DataType::TYPE_STRING, $largeType);
            $this->assertSame('9999999999999999.99', $largeValue);
            $this->assertSame(DataType::TYPE_STRING, $centsType);
            $this->assertSame('0.10', $centsValue);
            $this->assertSame(DataType::TYPE_STRING, $headingType);
            $this->assertSame('Số cuối kỳ', $headingValue);
            $this->assertSame('@', $export->columnFormats()[$moneyColumn]);
        }
    }
}
