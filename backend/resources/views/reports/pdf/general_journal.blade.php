<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Sổ nhật ký chung</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #172033; }
        h2 { text-align: center; margin: 0 0 4px; }
        .period { text-align: center; color: #526078; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 5px; }
        th { background: #eef2f7; text-align: center; font-weight: bold; }
        .number { text-align: right; }
        .center { text-align: center; }
    </style>
</head>
<body>
    <h2>SỔ NHẬT KÝ CHUNG</h2>
    <div class="period">Kỳ báo cáo: {{ $fromDate }} đến {{ $toDate }}</div>
    <table>
        <thead>
            <tr>
                <th>Ngày HT</th><th>Ngày CT</th><th>Số CT</th><th>Diễn giải</th>
                <th>TK Nợ</th><th>TK Có</th><th>Số tiền Nợ</th><th>Số tiền Có</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data as $row)
                <tr>
                    <td class="center">{{ data_get($row, 'posting_date') }}</td>
                    <td class="center">{{ data_get($row, 'voucher_date') }}</td>
                    <td>{{ data_get($row, 'voucher_number') }}</td>
                    <td>{{ data_get($row, 'description') ?: data_get($row, 'reason') }}</td>
                    <td class="center">{{ data_get($row, 'debit_account') }}</td>
                    <td class="center">{{ data_get($row, 'credit_account') }}</td>
                    <td class="number">{{ data_get($row, 'debit_amount') }}</td>
                    <td class="number">{{ data_get($row, 'credit_amount') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="center">Không có phát sinh trong kỳ đã chọn.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
