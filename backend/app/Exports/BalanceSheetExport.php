<?php

namespace App\Exports;

use App\Support\DecimalMoney;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BalanceSheetExport extends SafeMoneyExport implements FromArray, WithHeadings, WithStyles
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

        $assetsTotal = DecimalMoney::sum(array_column($this->data['assets'], 'end_balance'));
        $liabilitiesTotal = DecimalMoney::sum(array_column($this->data['liabilities'], 'end_balance'));
        $equityTotal = DecimalMoney::sum(array_column($this->data['equity'], 'end_balance'));

        $rows[] = ['TÀI SẢN', '', '', '', ''];
        foreach ($this->data['assets'] as $asset) {
            $rows[] = [$asset['name'], $asset['code'], '', $asset['end_balance'] ?: '', $asset['start_balance'] ?: ''];
        }
        $rows[] = ['TỔNG CỘNG TÀI SẢN', '270', '', $assetsTotal, ''];

        $rows[] = ['NGUỒN VỐN', '', '', '', ''];
        $rows[] = ['I. Nợ phải trả', '300', '', $liabilitiesTotal, ''];
        foreach ($this->data['liabilities'] as $liab) {
            $rows[] = [$liab['name'], $liab['code'], '', $liab['end_balance'] ?: '', $liab['start_balance'] ?: ''];
        }
        $rows[] = ['II. Vốn chủ sở hữu', '400', '', $equityTotal, ''];
        foreach ($this->data['equity'] as $eq) {
            $rows[] = [$eq['name'], $eq['code'], '', $eq['end_balance'] ?: '', $eq['start_balance'] ?: ''];
        }
        $rows[] = ['TỔNG CỘNG NGUỒN VỐN', '440', '', DecimalMoney::add($liabilitiesTotal, $equityTotal), ''];

        return $rows;
    }

    public function headings(): array
    {
        return [
            ['BẢNG CÂN ĐỐI KẾ TOÁN'],
            [],
            ['Chỉ tiêu', 'Mã số', 'Thuyết minh', 'Số cuối năm', 'Số đầu năm'],
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
