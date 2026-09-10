<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TrialBalanceExport extends SafeMoneyExport implements FromArray, WithHeadings, WithStyles
{
    /** @var list<string> */
    protected array $moneyColumns = ['C', 'D', 'E', 'F', 'G', 'H'];

    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function array(): array
    {
        $rows = [];
        foreach ($this->data as $row) {
            $rows[] = [
                $row['code'],
                $row['name'],
                $row['opening_debit'] ?: '',
                $row['opening_credit'] ?: '',
                $row['arising_debit'] ?: '',
                $row['arising_credit'] ?: '',
                $row['ending_debit'] ?: '',
                $row['ending_credit'] ?: '',
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            ['BẢNG CÂN ĐỐI SỐ PHÁT SINH'],
            [],
            ['Số TK', 'Tên tài khoản', 'Dư đầu kỳ Nợ', 'Dư đầu kỳ Có', 'Phát sinh Nợ', 'Phát sinh Có', 'Dư cuối kỳ Nợ', 'Dư cuối kỳ Có'],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->mergeCells('A1:H1');

        return [
            1 => ['font' => ['bold' => true, 'size' => 16], 'alignment' => ['horizontal' => 'center']],
            3 => ['font' => ['bold' => true]],
        ];
    }
}
