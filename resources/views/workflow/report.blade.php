<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#1d2530;margin:20px}h1{font-size:15px;margin:0 0 4px}.meta{color:#6a7480;margin-bottom:12px}table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #d9dee3;padding:4px 6px;text-align:left}th{background:#eef2f5}</style>
</head><body>
<h1>{{ $title }}</h1><div class="meta">Generated {{ now()->setTimezone('Africa/Johannesburg')->format('j M Y H:i') }}</div>
<table><thead><tr>@foreach($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
<tbody>@foreach($rows as $r)<tr>@foreach($r as $c)<td>{{ is_array($c) ? json_encode($c) : $c }}</td>@endforeach</tr>@endforeach</tbody></table>
</body></html>
