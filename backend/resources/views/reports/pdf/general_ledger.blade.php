<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Sổ cái</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #172033; }
        h2 { text-align: center; margin: 0 0 4px; }
        .meta { text-align: center; color: #526078; margin-bottom: 14px; }
        .opening { margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 5px; }
        th { background: #eef2f7; text-align: center; font-weight: bold; }
        .number { text-align: right; }
        .center { text-align: center; }
    </style>
</head>
<body>
    <h2>SỔ CÁI</h2>
    <div class="meta">Tài khoản {{ $accountCode }} · Kỳ báo cáo: {{ $fromDate }} đến {{ $toDate }}</div>
    <table class="opening">
        <tr>
            <th>Số dư đầu kỳ đến {{ data_get($openingBalance, 'as_of_date') }}</th>
            <th>Dư Nợ</th><th>Dư Có</th><th>Số dư ròng</th>
        </tr>
        <tr>
            <td></td>
            <td class="number">{{ data_get($openingBalance, 'debit') }}</td>
            <td class="number">{{ data_get($openingBalance, 'credit') }}</td>
            <td class="number">{{ data_get($openingBalance, 'balance') }}</td>
        </tr>
    </table>
    <table>
        <thead>
            <tr>
                <th>Ngày HT</th><th>Số CT</th><th>Diễn giải</th><th>TK Đối ứng</th>
                <th>Phát sinh Nợ</th><th>Phát sinh Có</th><th>Ngày CT</th><th>TK</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data as $row)
                <tr>
                    <td class="center">{{ data_get($row, 'posting_date') }}</td>
                    <td>{{ data_get($row, 'voucher_number') }}</td>
                    <td>{{ data_get($row, 'description') ?: data_get($row, 'reason') }}</td>
                    <td class="center">{{ data_get($row, 'corresponding_account') }}</td>
                    <td class="number">{{ data_get($row, 'debit') }}</td>
                    <td class="number">{{ data_get($row, 'credit') }}</td>
                    <td class="center">{{ data_get($row, 'voucher_date') }}</td>
                    <td class="center">{{ data_get($row, 'account_code') ?: $accountCode }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="center">Không có phát sinh trong kỳ đã chọn.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
