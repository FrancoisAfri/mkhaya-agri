<!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
    h1 { font-size: 15pt; margin: 0 0 1mm; }
    h2 { font-size: 11pt; margin: 6mm 0 2mm; border-bottom: 0.3mm solid #999; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 0.2mm solid #ccc; padding: 1.5mm; text-align: left; vertical-align: top; }
    th { background: #f2f2f2; }
    .r { text-align: right; }
    .muted { color: #555; }
    .kv td:first-child { width: 45%; color: #444; }
    .total td { font-weight: bold; }
    .note { color: #555; font-size: 8pt; margin-top: 5mm; }
</style></head>
<body>
@php($m = fn ($v) => 'R '.number_format((float) $v, 2, '.', ' '))
{!! \App\Modules\Agri\Support\OutputBranding::pdfHeader($brand) !!}
<h1>{{ $d['settlement'] ? $L('settlement_title', ['ref' => $d['settlement']['reference']]) : $L('title') }}</h1>
<div class="muted">{{ $L('from', ['company' => $company]) }}{{ $d['farm'] ? ' · '.$d['farm'] : '' }}</div>
<div>{{ $L('grower', ['code' => $d['grower']['code'], 'name' => $d['grower']['name'] ?? '']) }}{{ $d['grower']['location'] ? ' · '.$d['grower']['location'] : '' }}</div>

<h2>{{ $L('summary') }}</h2>
<table class="kv">
    @if ($d['settlement'])
        <tr><td>{{ $L('gross') }}</td><td class="r">{{ $m($d['settlement']['gross']) }}</td></tr>
        <tr><td>{{ $L('advances_recovered') }}</td><td class="r">{{ $m($d['settlement']['advances_recovered']) }}</td></tr>
        <tr class="total"><td>{{ $L('net') }}</td><td class="r">{{ $m($d['settlement']['net']) }}</td></tr>
        <tr><td>{{ $L('status') }}</td><td class="r">{{ $L('status_'.$d['settlement']['status']) }}</td></tr>
    @else
        <tr><td>{{ $L('collected_value') }}</td><td class="r">{{ $m($d['summary']['collected_value']) }}</td></tr>
        <tr><td>{{ $L('advanced') }}</td><td class="r">{{ $m($d['summary']['advanced']) }}</td></tr>
        <tr><td>{{ $L('recovered') }}</td><td class="r">{{ $m($d['summary']['recovered']) }}</td></tr>
        <tr><td>{{ $L('advances_outstanding') }}</td><td class="r">{{ $m($d['summary']['advances_outstanding']) }}</td></tr>
        <tr class="total"><td>{{ $L('to_pay') }}</td><td class="r">{{ $m($d['summary']['to_pay']) }}</td></tr>
    @endif
</table>

<h2>{{ $L('deliveries') }}</h2>
<table>
    <tr><th>{{ $L('date') }}</th><th>{{ $L('contract') }}</th><th>{{ $L('lot') }}</th><th class="r">{{ $L('delivered') }}</th><th class="r">{{ $L('rejected') }}</th>
        <th>{{ $L('grade') }}</th><th class="r">{{ $L('price') }}</th><th class="r">{{ $L('value') }}</th></tr>
    @forelse ($d['deliveries'] as $x)
        <tr><td>{{ $x['date'] }}</td><td>{{ $x['contract'] }}</td><td>{{ $x['lot'] ?? '—' }}</td><td class="r">{{ number_format($x['delivered'], 2) }} {{ $x['unit'] }}</td>
            <td class="r">{{ number_format($x['rejected'], 2) }}</td><td>{{ $x['grade'] ?? '—' }}</td><td class="r">{{ $m($x['price']) }}</td><td class="r">{{ $m($x['value']) }}</td></tr>
    @empty
        <tr><td colspan="8">{{ $L('no_deliveries') }}</td></tr>
    @endforelse
</table>

@if (! $d['settlement'])
<h2>{{ $L('advances') }}</h2>
<table>
    <tr><th>{{ $L('date') }}</th><th>{{ $L('contract') }}</th><th>{{ $L('advance') }}</th><th class="r">{{ $L('value') }}</th><th class="r">{{ $L('recovered') }}</th><th class="r">{{ $L('balance') }}</th></tr>
    @forelse ($d['advances']['rows'] as $x)
        <tr><td>{{ $x['date'] }}</td><td>{{ $x['contract'] }}</td><td>{{ $x['kind'] === 'cash' ? $L('cash') : (($x['item'] ?? $L('inputs')).($x['quantity'] ? ' × '.number_format($x['quantity'], 2) : '')) }}</td>
            <td class="r">{{ $m($x['value']) }}</td><td class="r">{{ $m($x['recovered']) }}</td><td class="r">{{ $m($x['balance']) }}</td></tr>
    @empty
        <tr><td colspan="6">{{ $L('no_advances') }}</td></tr>
    @endforelse
</table>

<h2>{{ $L('settlements') }}</h2>
<table>
    <tr><th>{{ $L('reference') }}</th><th>{{ $L('date') }}</th><th class="r">{{ $L('gross') }}</th><th class="r">{{ $L('advances_recovered') }}</th><th class="r">{{ $L('net') }}</th><th>{{ $L('status') }}</th></tr>
    @forelse ($d['settlements'] as $x)
        <tr><td>{{ $x['reference'] }}</td><td>{{ $x['date'] }}</td><td class="r">{{ $m($x['gross']) }}</td><td class="r">{{ $m($x['advances_recovered']) }}</td><td class="r">{{ $m($x['net']) }}</td>
            <td>{{ $L('status_'.$x['status']) }}</td></tr>
    @empty
        <tr><td colspan="6">{{ $L('no_settlements') }}</td></tr>
    @endforelse
</table>
@endif

<p class="note">{{ $L('generated', ['at' => $d['generated']]) }} {{ $brand['white_label'] ? $L('issued_by', ['brand' => $brand['name']]) : $L('prepared_with', ['brand' => $brand['name']]) }}</p>
</body></html>
