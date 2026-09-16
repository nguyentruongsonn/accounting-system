<?php

namespace App\Exports;

use App\Exports\Concerns\BindsSafeExcelMoney;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\DefaultValueBinder;

abstract class SafeMoneyExport extends DefaultValueBinder implements WithColumnFormatting, WithCustomValueBinder
{
    use BindsSafeExcelMoney;
}
