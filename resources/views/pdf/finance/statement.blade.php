{{-- Branded from the tenant's statement template, using the same layout presets as invoices so a
     chosen style carries across both documents. Callers that pass no $style get the classic look. --}}
@php($accent = $style['accent'] ?? ($company['colour'] ?? '#4b3fd4'))
@php($font = $style['font'] ?? 'DejaVu Sans, sans-serif')
@php($layout = $style['layout'] ?? 'classic')
<!doctype html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: {{ $font }}; font-size: 11px; color: #222; }
    .title { font-size: {{ $layout === 'bold' ? '26px' : '22px' }}; font-weight: bold; color: {{ $layout === 'bold' ? '#fff' : $accent }}; @if($layout === 'bold') background: {{ $accent }}; padding: 6px 12px; display: inline-block; border-radius: 3px; @endif }
    .muted { color: #666; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
    @if($layout === 'modern')
    table.lines th { background: transparent; color: {{ $accent }}; text-align: left; padding: 6px 8px; font-size: 10px; border-bottom: 2px solid {{ $accent }}; }
    @elseif($layout === 'minimal')
    table.lines th { background: #f3f4f6; color: #111; text-align: left; padding: 6px 8px; font-size: 10px; }
    @else
    table.lines th { background: {{ $accent }}; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
    @endif
    table.lines td { padding: 6px 8px; border-bottom: 1px solid #eee; }
    .r { text-align: right; }
</style></head>
<body>
    @include('pdf.finance._letterhead')

    <div class="title">STATEMENT OF ACCOUNT</div>
    <div class="muted" style="margin-bottom:8px;">{{ $statement['party']['name'] }} · {{ $statement['party']['code'] }}</div>
    <div class="muted">Period: {{ \Illuminate\Support\Carbon::parse($statement['from'])->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($statement['to'])->format('d M Y') }}</div>

    <table class="lines">
        <thead><tr><th>Date</th><th>Reference</th><th>Type</th><th class="r">Charge</th><th class="r">Payment</th><th class="r">Balance</th></tr></thead>
        <tbody>
            <tr style="font-weight:bold; background:#f7f7fb;"><td colspan="5">Opening balance</td><td class="r">{{ number_format($statement['opening'], 2) }}</td></tr>
            @foreach($statement['rows'] as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }}</td>
                    <td>{{ $row['ref'] }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td class="r">{{ $row['charge'] ? number_format($row['charge'], 2) : '' }}</td>
                    <td class="r">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                    <td class="r">{{ number_format($row['balance'], 2) }}</td>
                </tr>
            @endforeach
            @if(empty($statement['rows']))<tr><td colspan="6" class="muted" style="text-align:center; padding:16px;">No activity in this period.</td></tr>@endif
        </tbody>
        <tfoot>
            <tr style="font-weight:bold; font-size:13px;"><td colspan="5" class="r" style="border-top:2px solid {{ $accent }};">Balance due</td><td class="r" style="border-top:2px solid {{ $accent }};">{{ number_format($statement['closing'], 2) }}</td></tr>
        </tfoot>
    </table>

    {{-- Banking details, message and terms — a statement is a request for payment, so the details
         needed to pay it belong on it. Renders nothing when the template supplies none. --}}
    @include('pdf.finance._footer')
</body>
</html>
