<!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 16mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
    h1 { font-size: 15pt; margin: 0 0 2mm; }
    h2 { font-size: 11pt; margin: 6mm 0 2mm; border-bottom: 0.3mm solid #999; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 0.2mm solid #ccc; padding: 1.5mm; text-align: left; vertical-align: top; }
    th { background: #f2f2f2; }
    .kv td:first-child { width: 38%; color: #444; }
    .note { color: #555; font-size: 8pt; margin-top: 4mm; }
    .r { text-align: right; }
</style></head>
<body>
{!! \App\Modules\Agri\Support\OutputBranding::pdfHeader($brand ?? ['white_label' => false]) !!}
<h1>{{ $L('summary_title') }}</h1>
<div>{{ $project->description }}</div>

<h2>{{ $L('summary_project') }}</h2>
<table class="kv">
    <tr><td>{{ $L('registry') }}</td><td>{{ $registry }}</td></tr>
    <tr><td>{{ $L('registry_project_id') }}</td><td>{{ $reg->registry_project_id ?: '—' }}</td></tr>
    <tr><td>{{ $L('status') }}</td><td>{{ $L('status_'.$reg->status) }}{{ $reg->status_on ? ' ('.$reg->status_on->toDateString().')' : '' }}</td></tr>
    <tr><td>{{ $L('methodology') }}</td><td>{{ $reg->methodology ?: ($project->methodology ?: '—') }}</td></tr>
    <tr><td>{{ $L('proponent') }}</td><td>{{ $reg->proponent ?: '—' }}</td></tr>
    <tr><td>{{ $L('farm') }}</td><td>{{ $farm->name }}{{ $farm->location ? ', '.$farm->location : '' }}{{ $farm->latitude !== null ? sprintf(' (%.4f, %.4f)', $farm->latitude, $farm->longitude) : '' }}</td></tr>
    <tr><td>{{ $L('crediting_period') }}</td><td>{{ $reg->crediting_starts_on?->toDateString() ?? '—' }} – {{ $reg->crediting_ends_on?->toDateString() ?? '—' }}</td></tr>
    <tr><td>{{ $L('verification') }}</td><td>{{ $project->verification === 'verified' ? $L('verified_by', ['verifier' => $project->verifier]) : $L('unverified') }}</td></tr>
    <tr><td>{{ $L('buffer') }}</td><td>{{ rtrim(rtrim(number_format($reg->buffer_pct, 2), '0'), '.') }}%</td></tr>
</table>

<h2>{{ $L('periods') }}</h2>
<table>
    <tr><th>{{ $L('period') }}</th><th>{{ $L('status') }}</th><th class="r">{{ $L('claimed') }}</th><th class="r">{{ $L('verified') }}</th><th>{{ $L('verifier') }}</th><th class="r">{{ $L('values') }}</th></tr>
    @forelse ($periods as $p)
        <tr><td>{{ $p->starts_on->toDateString() }} – {{ $p->ends_on->toDateString() }}</td><td>{{ $L('period_'.$p->status) }}</td>
            <td class="r">{{ $p->claimed_t !== null ? number_format($p->claimed_t, 3) : '—' }}</td><td class="r">{{ $p->verified_t !== null ? number_format($p->verified_t, 3) : '—' }}</td>
            <td>{{ $p->verifier ?: '—' }}{{ $p->verified_on ? ' ('.$p->verified_on->toDateString().')' : '' }}</td><td class="r">{{ ($monitoring[$p->id] ?? collect())->count() }}</td></tr>
    @empty
        <tr><td colspan="6">{{ $L('no_periods') }}</td></tr>
    @endforelse
</table>

<h2>{{ $L('issuances') }}</h2>
<table>
    <tr><th>{{ $L('vintage') }}</th><th>{{ $L('issued_on') }}</th><th class="r">{{ $L('quantity') }}</th><th class="r">{{ $L('buffer_t') }}</th><th>{{ $L('serials') }}</th></tr>
    @forelse ($issuances as $i)
        <tr><td>{{ $i->vintage }}</td><td>{{ $i->issued_on->toDateString() }}</td><td class="r">{{ number_format($i->quantity_t, 3) }}</td><td class="r">{{ number_format($i->buffer_t, 3) }}</td>
            <td>{{ $i->serial_start }}{{ $i->serial_end ? ' – '.$i->serial_end : '' }}</td></tr>
    @empty
        <tr><td colspan="5">{{ $L('no_issuances') }}</td></tr>
    @endforelse
</table>
<table class="kv" style="margin-top: 3mm">
    <tr><td>{{ $L('issued_total') }}</td><td>{{ number_format($balance['issued'], 3) }} tCO2e</td></tr>
    <tr><td>{{ $L('buffer_total') }}</td><td>{{ number_format($balance['buffer'], 3) }} tCO2e</td></tr>
    <tr><td>{{ $L('retired') }}</td><td>{{ number_format($balance['retired'], 3) }} tCO2e</td></tr>
    <tr><td>{{ $L('transferred') }}</td><td>{{ number_format($balance['transferred'], 3) }} tCO2e</td></tr>
    <tr><td>{{ $L('held') }}</td><td>{{ number_format($balance['held'], 3) }} tCO2e</td></tr>
</table>
<p class="note">{{ $L('no_api') }} {{ $L('generated', ['at' => now()->format('Y-m-d H:i')]) }}@if (! empty($brand['white_label'])) {{ $L('prepared_by', ['brand' => $brand['name']]) }}@endif</p>
</body></html>
