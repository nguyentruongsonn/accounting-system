<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bảng cân đối kế toán</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 5px; }
        th { background-color: #f2f2f2; text-align: center; font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .bold { font-weight: bold; }
        h2 { text-align: center; }
    </style>
</head>
<body>
    <h2>BẢNG CÂN ĐỐI KẾ TOÁN</h2>
    <table>
        <thead>
            <tr>
                <th>Chỉ tiêu</th>
                <th>Mã số</th>
                <th>Thuyết minh</th>
                <th>Số cuối kỳ</th>
                <th>Số đầu kỳ</th>
            </tr>
        </thead>
        <tbody>
            <tr class="bold"><td colspan="5">TÀI SẢN</td></tr>
            @foreach($data['assets'] as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="text-center">{{ $row['code'] }}</td>
                <td></td>
                <td class="text-right">{{ $row['end_balance'] ? number_format($row['end_balance']) : '' }}</td>
                <td class="text-right">{{ $row['start_balance'] ? number_format($row['start_balance']) : '' }}</td>
            </tr>
            @endforeach
            <tr class="bold">
                <td>TỔNG CỘNG TÀI SẢN</td>
                <td class="text-center">270</td>
                <td></td>
                <td class="text-right">{{ number_format(collect($data['assets'])->sum('end_balance')) }}</td>
                <td class="text-right"></td>
            </tr>
            <tr class="bold"><td colspan="5">NGUỒN VỐN</td></tr>
            <tr class="bold">
                <td>I. Nợ phải trả</td>
                <td class="text-center">300</td>
                <td></td>
                <td class="text-right">{{ number_format(collect($data['liabilities'])->sum('end_balance')) }}</td>
                <td class="text-right"></td>
            </tr>
            @foreach($data['liabilities'] as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="text-center">{{ $row['code'] }}</td>
                <td></td>
                <td class="text-right">{{ $row['end_balance'] ? number_format($row['end_balance']) : '' }}</td>
                <td class="text-right">{{ $row['start_balance'] ? number_format($row['start_balance']) : '' }}</td>
            </tr>
            @endforeach
            <tr class="bold">
                <td>II. Vốn chủ sở hữu</td>
                <td class="text-center">400</td>
                <td></td>
                <td class="text-right">{{ number_format(collect($data['equity'])->sum('end_balance')) }}</td>
                <td class="text-right"></td>
            </tr>
            @foreach($data['equity'] as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="text-center">{{ $row['code'] }}</td>
                <td></td>
                <td class="text-right">{{ $row['end_balance'] ? number_format($row['end_balance']) : '' }}</td>
                <td class="text-right">{{ $row['start_balance'] ? number_format($row['start_balance']) : '' }}</td>
            </tr>
            @endforeach
            <tr class="bold">
                <td>TỔNG CỘNG NGUỒN VỐN</td>
                <td class="text-center">440</td>
                <td></td>
                <td class="text-right">{{ number_format(collect($data['liabilities'])->sum('end_balance') + collect($data['equity'])->sum('end_balance')) }}</td>
                <td class="text-right"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
