<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body  { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a2e; }
        h1    { font-size: 15px; margin: 0 0 2px; }
        .meta { color: #6b7280; font-size: 10px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th    { background: #4338ca; color: #fff; padding: 6px; text-align: left; font-size: 10px; }
        td    { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; font-size: 9.5px; }
        tr:nth-child(even) td { background: #f5f6ff; }
        .empty { color: #9ca3af; padding: 16px; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">Generated {{ $date }} &middot; {{ count($rows) }} record(s)</div>

    @if (count($rows))
        <table>
            <thead>
                <tr>@foreach ($headers as $h)<th>{{ $h }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>@foreach ((array) $row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">No data for this report.</div>
    @endif
</body>
</html>
