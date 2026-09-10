<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bảng cân đối số phát sinh</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: right; }
        th { background-color: #f2f2f2; text-align: center; font-weight: bold; }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        h2 { text-align: center; }
    </style>
</head>
<body>
    <h2>BẢNG CÂN ĐỐI SỐ PHÁT SINH</h2>
    <table>
        <thead>
            <tr>
                <th rowspan="2">Số TK</th>
                <th rowspan="2">Tên tài khoản</th>
                <th colspan="2">Số dư đầu kỳ</th>
                <th colspan="2">Số phát sinh trong kỳ</th>
                <th colspan="2">Số dư cuối kỳ</th>
            </tr>
            <tr>
                <th>Nợ</th>
                <th>Có</th>
                <th>Nợ</th>
                <th>Có</th>
                <th>Nợ</th>
                <th>Có</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
            <tr>
                <td class="text-center">{{ $row['code'] }}</td>
                <td class="text-left">{{ $row['name'] }}</td>
                <td>{{ $row['opening_debit'] ? number_format($row['opening_debit']) : '' }}</td>
                <td>{{ $row['opening_credit'] ? number_format($row['opening_credit']) : '' }}</td>
                <td>{{ $row['arising_debit'] ? number_format($row['arising_debit']) : '' }}</td>
                <td>{{ $row['arising_credit'] ? number_format($row['arising_credit']) : '' }}</td>
                <td>{{ $row['ending_debit'] ? number_format($row['ending_debit']) : '' }}</td>
                <td>{{ $row['ending_credit'] ? number_format($row['ending_credit']) : '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
