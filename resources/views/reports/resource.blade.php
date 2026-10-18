<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #4a5568; color: #fff; padding: 8px; text-align: left; font-size: 11px; }
        td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
        tr:nth-child(even) td { background-color: #f7fafc; }
        .meta { color: #718096; font-size: 10px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">Generated on {{ now()->format('d/m/Y H:i') }} &nbsp;|&nbsp; {{ count($rows) }} registration(s)</p>

    @if(count($rows))
        <table>
         <thead>
    <tr>
        @foreach(array_keys($rows[0]) as $header)
            <th>{{ ucfirst($header) }}</th>
        @endforeach
    </tr>
</thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        @foreach($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>No data available.</p>
    @endif
</body>
</html>
