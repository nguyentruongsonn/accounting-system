<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Export the posted general-ledger rows plus the signed opening balance. */
final class GeneralLedgerExport extends SafeMoneyExport implements FromArray, WithHeadings, WithStyles
{
    /** @var list<string> */
    protected array $moneyColumns = ['D', 'E', 'F'];

    /** @param list<array<string, mixed>> $data */
    public function __construct(
        private readonly array $data,
        private readonly array $openingBalance,
        private readonly string $accountCode,
        private readonly ?string $fromDate = null,
        private readonly ?string $toDate = null,
    ) {
    }

    public function array(): array
    {
        $rows = [
            [
                'Số dư đầu kỳ đến '.($this->openingBalance['as_of_date'] ?? ''),
                '',
                '',
                $this->openingBalance['debit'] ?? '0.00',
                $this->openingBalance['credit'] ?? '0.00',
                '',
                '',
                '',
            ],
        ];

        foreach ($this->data as $row) {
            $rows[] = [
                $row['posting_date'] ?? '',
                $row['voucher_number'] ?? '',
                $row['description'] ?? $row['reason'] ?? '',
                $row['corresponding_account'] ?? '',
                $row['debit'] ?? '0.00',
                $row['credit'] ?? '0.00',
                $row['voucher_date'] ?? '',
                $row['account_code'] ?? $this->accountCode,
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        $period = $this->fromDate !== null && $this->toDate !== null
            ? "Kỳ báo cáo: {$this->fromDate} đến {$this->toDate}"
            : '';

        return [
            ['SỔ CÁI', '', '', '', '', '', '', ''],
            ['Tài khoản', $this->accountCode, '', '', '', '', '', ''],
            [$period, '', '', '', '', '', '', ''],
            ['Ngày HT', 'Số CT', 'Diễn giải', 'TK Đối ứng', 'Phát sinh Nợ', 'Phát sinh Có', 'Ngày CT', 'TK'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->mergeCells('A1:H1');
        $sheet->mergeCells('A3:H3');

        return [
            1 => ['font' => ['bold' => true, 'size' => 16], 'alignment' => ['horizontal' => 'center']],
            4 => ['font' => ['bold' => true]],
        ];
    }
}
