<?php

namespace App\Enums;

enum AccountingRegime: string
{
    case TT200 = 'TT200';
    case TT99 = 'TT99';

    public const TT99_EFFECTIVE_FROM = '2026-01-01';

    public static function forFiscalYearStart(string $startDate): self
    {
        return $startDate >= self::TT99_EFFECTIVE_FROM ? self::TT99 : self::TT200;
    }

    public function label(): string
    {
        return match ($this) {
            self::TT200 => 'Thông tư 200/2014/TT-BTC',
            self::TT99 => 'Thông tư 99/2025/TT-BTC',
        };
    }
}
