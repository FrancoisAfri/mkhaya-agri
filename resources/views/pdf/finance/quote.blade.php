@php($accent = $style['accent'] ?? ($company['colour'] ?? '#374151'))
@php($font = $style['font'] ?? 'DejaVu Sans, sans-serif')
@php($layout = $style['layout'] ?? 'classic')
<!doctype html>
<html>
<head><meta charset="utf-8">
<style>
    /* Set in ink, like the invoice it turns into. See pdf/finance/invoice.blade.php. */
    @page { margin: 34px 40px 56px 40px; }

    body { font-family: {{ $font }}; font-size: 10.5px; color: #1f2937; line-height: 1.5; }

    .title { font-size: 24px; font-weight: bold; letter-spacing: 0.06em; color: #111827; }
    .muted { color: #6b7280; }
    .r     { text-align: right; }
    .num   { font-variant-numeric: tabular-nums; white-space: nowrap; }

    .label { font-size: 8px; letter-spacing: 0.1em; text-transform: uppercase; color: #9ca3af; }

    table.lines { width: 100%; border-collapse: collapse; margin-top: 18px; }
    table.lines th {
        text-align: left; padding: 0 9px 6px;
        font-size: 8px; letter-spacing: 0.1em; text-transform: uppercase;
        color: #6b7280; border-bottom: 1px solid #d1d5db;
    }
    table.lines td { padding: 8px 9px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }

    .note { color: #6b7280; font-size: 9.5px; margin-top: 2px; }
    .box  { border: 0; padding: 0; }
</style></head>
<body>
    @include('pdf.finance._letterhead')

    <table style="width:100%; border:0;"><tr>
        <td style="border:0; vertical-align:top; width:55%;">
            <div class="title">QUOTATION</div>
            <div class="muted">{{ $quote->number }}</div>
            <div style="margin-top:10px;">
                @include('pdf.finance._party', [
                    'party'        => $quote->party,
                    'heading'      => 'To',
                    'accent'       => $accent,
                    'accountLabel' => 'Account',
                ])

                {{-- Where the goods go, which is not always where the invoice goes. --}}
                @if($quote->delivery_address)
                    <div style="margin-top:8px; border:1px solid #e5e7eb; border-radius:4px; padding:9px 11px;">
                        <div style="font-size:9px; letter-spacing:0.08em; text-transform:uppercase; color:#6b7280;">Deliver to</div>
                        <div style="color:#4b5563; margin-top:2px;">{!! nl2br(e($quote->delivery_address)) !!}</div>
                    </div>
                @endif
            </div>
        </td>
        <td style="border:0; vertical-align:top; text-align:right;">
            <table style="border:0; margin-left:auto; font-size:11px;">
                <tr><td class="muted r">Date</td><td class="r" style="padding-left:14px;">{{ optional($quote->quote_date)->format('d M Y') }}</td></tr>
                <tr><td class="muted r">Valid until</td><td class="r" style="padding-left:14px;">{{ optional($quote->valid_until)->format('d M Y') }}</td></tr>
                @if($quote->customer_ref)<tr><td class="muted r">Your ref</td><td class="r">{{ $quote->customer_ref }}</td></tr>@endif
                {{-- Per customer, not per company: their supplier account for us. --}}
                @if($quote->vendor_number)<tr><td class="muted r">Vendor no</td><td class="r">{{ $quote->vendor_number }}</td></tr>@endif
                @if($quote->sales_rep)<tr><td class="muted r">Sales rep</td><td class="r">{{ $quote->sales_rep }}</td></tr>@endif
                <tr><td class="muted r">Status</td><td class="r" style="text-transform:capitalize;">{{ $quote->status }}</td></tr>
            </table>
        </td>
    </tr></table>

    <table class="lines">
        <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Tax %</th><th class="r">Total</th></tr></thead>
        <tbody>
        @foreach($quote->lines as $l)
            <tr>
                <td>
                    <strong>{{ $l->item?->name ?: $l->description }}</strong>
                    @if($l->item && $l->description && $l->description !== $l->item->name)<div class="muted">{{ $l->description }}</div>@endif
                    @if($l->note)<div class="note">{{ $l->note }}</div>@endif
                </td>
                <td class="r">{{ rtrim(rtrim(number_format($l->quantity, 2), '0'), '.') }}</td>
                <td class="r">{{ number_format($l->unit_price, 2) }}</td>
                <td class="r">{{ rtrim(rtrim(number_format($l->tax_rate, 2), '0'), '.') }}%</td>
                <td class="r">{{ number_format($l->line_total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table style="width:100%; border:0; margin-top:12px;"><tr>
        <td style="border:0; vertical-align:top; width:55%;">
            @if($quote->description)<div class="box muted">{!! nl2br(e($quote->description)) !!}</div>@endif
        </td>
        <td style="border:0;">
            <table class="totals" style="width:100%; border:0;">
                <tr><td class="muted r">Subtotal</td><td class="r">{{ number_format($quote->subtotal, 2) }}</td></tr>
                @if($quote->discount_amount > 0)<tr><td class="muted r">Discount ({{ rtrim(rtrim(number_format($quote->discount_percent,2),'0'),'.') }}%)</td><td class="r">−{{ number_format($quote->discount_amount, 2) }}</td></tr>@endif
                <tr><td class="muted r">VAT</td><td class="r">{{ number_format($quote->tax_total, 2) }}</td></tr>
                <tr style="font-weight:bold; font-size:13px; color:#111827;"><td class="r" style="border-top:1px solid #9ca3af; padding-top:8px;">Total ({{ $quote->currency }})</td><td class="r num" style="border-top:1px solid #9ca3af; padding-top:8px;">{{ number_format($quote->total, 2) }}</td></tr>
            </table>
        </td>
    </tr></table>

    @include('pdf.finance._footer')
</body>
</html>
