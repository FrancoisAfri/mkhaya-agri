<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 20px 28px; }
        body  { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1f2a37; }
        h1    { font-size: 15px; margin: 0 0 2px; }
        .meta { color: #5b6573; font-size: 9px; margin-bottom: 8px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; }
        th    { background: #1f4e5f; color: #fff; padding: 5px; text-align: left; font-size: 9px; }
        td    { padding: 4px 5px; border-bottom: 1px solid #e3e8ec; font-size: 9px; }
        td.n  { text-align: right; }
        tr:nth-child(even) td { background: #f4f7f8; }
        .empty { color: #8a94a0; padding: 16px; text-align: center; }
        .foot { position: fixed; bottom: -14px; left: 0; right: 0; font-size: 8px; color: #8a94a0; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        {{ $company }} &middot; generated {{ $generated }} by {{ $by }} &middot; {{ count($rows) }} row(s)<br>
        @foreach ($parameters as $k => $v){{ $k }}: {{ $v }}@if (! $loop->last) &middot; @endif @endforeach
    </div>
    @if (count($rows))
        <table>
            <thead><tr>@foreach ($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>@foreach (array_values((array) $row) as $cell)<td class="{{ is_int($cell) || is_float($cell) ? 'n' : '' }}">{{ is_float($cell) ? rtrim(rtrim(number_format($cell, 4, '.', ' '), '0'), '.') : $cell }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">Nothing matches these filters.</div>
    @endif
    <div class="foot">{{ $title }} &middot; {{ $generated }}@if ($sensitive) &middot; Confidential: contains stock values @endif</div>
</body>
</html>
