@php($accent = $company['colour'] ?? '#4b3fd4')
<!doctype html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    .title { font-size: 22px; font-weight: bold; color: {{ $accent }}; }
    .muted { color: #666; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
    table.lines th { background: {{ $accent }}; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
    table.lines td { padding: 6px 8px; border-bottom: 1px solid #eee; }
    .r { text-align: right; }
    .box { border: 1px solid #eee; padding: 10px; border-radius: 4px; }
</style></head>
<body>
    @include('pdf.finance._letterhead')

    <table style="width:100%; border:0;"><tr>
        <td style="border:0; vertical-align:top; width:55%;">
            <div class="title">DELIVERY NOTE</div>
            <div class="muted">{{ $dn->number }}</div>
            <div style="margin-top:10px;">
                @include('pdf.finance._party', [
                    'party'        => $dn->party,
                    'heading'      => 'Deliver to',
                    'accent'       => $accent,
                    'accountLabel' => 'Account',
                ])
            </div>
        </td>
        <td style="border:0; vertical-align:top; text-align:right;">
            <table style="border:0; margin-left:auto;">
                <tr><td class="muted r">Date</td><td class="r" style="padding-left:14px;">{{ optional($dn->delivery_date)->format('d M Y') }}</td></tr>
                <tr><td class="muted r">Order</td><td class="r">{{ $dn->order->number ?? '—' }}</td></tr>
                @if($dn->order && $dn->order->customer_ref)<tr><td class="muted r">Your ref</td><td class="r">{{ $dn->order->customer_ref }}</td></tr>@endif
            </table>
        </td>
    </tr></table>

    <table class="lines">
        <thead><tr><th>Description</th><th class="r" style="width:120px">Quantity</th></tr></thead>
        <tbody>
        @foreach($dn->lines as $l)
            <tr><td>{{ $l->description }}</td><td class="r">{{ rtrim(rtrim(number_format($l->quantity, 2), '0'), '.') }}</td></tr>
        @endforeach
        @if($dn->lines->isEmpty())<tr><td colspan="2" class="muted" style="text-align:center; padding:16px;">No items on this delivery.</td></tr>@endif
        </tbody>
    </table>

    @if($dn->notes)<div class="box muted" style="margin-top:12px;">{!! nl2br(e($dn->notes)) !!}</div>@endif

    <table style="width:100%; border:0; margin-top:36px;"><tr>
        <td style="border:0; width:50%;"><div style="border-top:1px solid #999; padding-top:4px;" class="muted">Received by (name &amp; signature)</div></td>
        <td style="border:0; width:20px;"></td>
        <td style="border:0;"><div style="border-top:1px solid #999; padding-top:4px;" class="muted">Date</div></td>
    </tr></table>
</body>
</html>
