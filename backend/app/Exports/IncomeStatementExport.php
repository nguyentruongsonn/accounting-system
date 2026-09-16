<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class IncomeStatementExport extends SafeMoneyExport implements FromArray, WithHeadings, WithStyles
{
    /** @var list<string> */
    protected array $moneyColumns = ['D', 'E'];

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
                $row['name'],
                $row['code'],
                '',
                $row['this_period'] ?: '',
                $row['prev_period'] ?: '',
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            ['BÁO CÁO KẾT QUẢ KINH DOANH'],
            [],
            ['Chỉ tiêu', 'Mã số', 'Thuyết minh', 'Kỳ này', 'Kỳ trước'],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->mergeCells('A1:E1');

        return [
            1 => ['font' => ['bold' => true, 'size' => 16], 'alignment' => ['horizontal' => 'center']],
            3 => ['font' => ['bold' => true]],
        ];
    }
}
