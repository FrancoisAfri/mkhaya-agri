<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .period { color: #666; font-size: 11px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; border-bottom: 1.5px solid #333; padding: 5px 6px; font-size: 10px; text-transform: uppercase; }
        td { padding: 4px 6px; border-bottom: 0.5px solid #e5e5e5; }
        .right { text-align: right; }
        .group td { font-weight: bold; text-transform: uppercase; font-size: 10px; padding-top: 8px; color: #444; }
        .subtotal td { font-weight: bold; background: #f4f4f4; }
        .totals td { font-weight: bold; border-top: 1.5px solid #333; }
    </style>
</head>
<body>
    <h1>{{ $report['title'] }}</h1>
    <div class="period">{{ $from }} &nbsp;&rarr;&nbsp; {{ $to }}</div>
    <table>
        <thead>
            <tr>
                @foreach ($report['columns'] as $c)
                    <th class="{{ $c['align'] === 'right' ? 'right' : '' }}">{{ $c['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report['rows'] as $row)
                <tr class="{{ ($row['group'] ?? false) ? 'group' : (($row['subtotal'] ?? false) ? 'subtotal' : '') }}">
                    @foreach ($report['columns'] as $c)
                        <td class="{{ $c['align'] === 'right' ? 'right' : '' }}">
                            @php $v = $row[$c['key']] ?? null; @endphp
                            @if (is_numeric($v)) {{ number_format((float) $v, 2) }} @else {{ $v }} @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            @if (!empty($report['totals']))
                <tr class="totals">
                    @foreach ($report['columns'] as $c)
                        <td class="{{ $c['align'] === 'right' ? 'right' : '' }}">
                            @php $v = $report['totals'][$c['key']] ?? null; @endphp
                            @if (is_numeric($v)) {{ number_format((float) $v, 2) }} @else {{ $v }} @endif
                        </td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
