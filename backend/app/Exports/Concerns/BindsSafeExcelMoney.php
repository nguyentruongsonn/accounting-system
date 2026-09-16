<?php

namespace App\Exports\Concerns;

use App\Support\DecimalMoney;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Preserves the fixed-scale DECIMAL(?,2) contract at the Excel boundary.
 *
 * Excel stores numeric cells with at most 15 significant decimal digits and
 * PhpSpreadsheet may coerce a decimal string through a float before writing.
 * Every monetary value is consequently emitted as text. This is the only
 * representation that preserves the canonical DECIMAL(*,2) value exactly.
 */
trait BindsSafeExcelMoney
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (! in_array($cell->getColumn(), $this->moneyColumns, true) || $value === null || $value === '') {
            return parent::bindValue($cell, $value);
        }

        try {
            $amount = DecimalMoney::normalize($value);
        } catch (InvalidArgumentException) {
            // Title and heading rows can occupy a monetary column in a
            // multi-row export. They remain ordinary text cells.
            return parent::bindValue($cell, $value);
        }

        $cell->setValueExplicit($amount, DataType::TYPE_STRING);

        return true;
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return array_fill_keys($this->moneyColumns, '@');
    }
}
