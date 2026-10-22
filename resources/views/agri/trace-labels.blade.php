<!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 10mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
    table { width: 100%; border-collapse: collapse; }
    td { width: 33.33%; height: 62mm; border: 0.3mm dashed #bbb; text-align: center; vertical-align: middle; padding: 3mm; }
    img { width: 38mm; height: 38mm; }
    .p { font-weight: bold; font-size: 10pt; margin-top: 1mm; }
    .s { color: #555; font-size: 7.5pt; }
</style></head>
<body>
<table>
    @foreach (array_chunk($labels, 3) as $row)
        <tr>
            @foreach ($row as $l)
                <td><img src="{{ $l['qr'] }}" alt=""><div class="p">{{ $l['product'] }}</div><div class="s">{{ $l['lot'] }}@if ($l['grade']) · {{ $l['grade'] }}@endif</div><div class="s">{{ $scan }}</div></td>
            @endforeach
            @for ($i = count($row); $i < 3; $i++)<td></td>@endfor
        </tr>
    @endforeach
</table>
</body></html>
