<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export the exact posted rows returned by the general-journal report.
 *
 * The controller supplies the already tenant-scoped, posted-only result. This
 * class only shapes that result for Excel; it does not recalculate accounting
 * values or query a second source.
 */
final class GeneralJournalExport extends SafeMoneyExport implements FromArray, WithHeadings, WithStyles
{
    /** @var list<string> */
    protected array $moneyColumns = ['G', 'H'];

    /** @param list<object|array<string, mixed>> $data */
    public function __construct(
        private readonly array $data,
        private readonly ?string $fromDate = null,
        private readonly ?string $toDate = null,
    ) {
    }

    public function array(): array
    {
        return array_map(static function (object|array $row): array {
            $value = static fn (string $key): mixed => is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);

            return [
                $value('posting_date') ?? '',
                $value('voucher_date') ?? '',
                $value('voucher_number') ?? '',
                $value('description') ?? $value('reason') ?? '',
                $value('debit_account') ?? '',
                $value('credit_account') ?? '',
                $value('debit_amount') ?? '0.00',
                $value('credit_amount') ?? '0.00',
            ];
        }, $this->data);
    }

    public function headings(): array
    {
        $period = $this->fromDate !== null && $this->toDate !== null
            ? "Kỳ báo cáo: {$this->fromDate} đến {$this->toDate}"
            : '';

        return [
            ['SỔ NHẬT KÝ CHUNG', '', '', '', '', '', '', ''],
            [$period, '', '', '', '', '', '', ''],
            [],
            ['Ngày HT', 'Ngày CT', 'Số CT', 'Diễn giải', 'TK Nợ', 'TK Có', 'Số tiền Nợ', 'Số tiền Có'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->mergeCells('A1:H1');
        $sheet->mergeCells('A2:H2');

        return [
            1 => ['font' => ['bold' => true, 'size' => 16], 'alignment' => ['horizontal' => 'center']],
            4 => ['font' => ['bold' => true]],
        ];
    }
}
