<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Báo cáo kết quả kinh doanh</title>
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
    <h2>BÁO CÁO KẾT QUẢ KINH DOANH</h2>
    <table>
        <thead>
            <tr>
                <th>Chỉ tiêu</th>
                <th>Mã số</th>
                <th>Thuyết minh</th>
                <th>Kỳ này</th>
                <th>Kỳ trước</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
            <tr class="{{ in_array($row['code'], ['10', '20', '30', '50', '60']) ? 'bold' : '' }}">
                <td>{{ $row['name'] }}</td>
                <td class="text-center">{{ $row['code'] }}</td>
                <td></td>
                <td class="text-right">{{ $row['this_period'] ? number_format($row['this_period']) : '' }}</td>
                <td class="text-right">{{ $row['prev_period'] ? number_format($row['prev_period']) : '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
