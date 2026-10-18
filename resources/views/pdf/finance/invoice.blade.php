@php
    $accent = $style['accent'] ?? ($company['colour'] ?? '#4b3fd4');
    $font   = $style['font'] ?? 'DejaVu Sans, sans-serif';
    $layout = $style['layout'] ?? 'classic';
    $isAr   = $invoice->direction === 'receivable';
    $party  = $invoice->party;

    $heading = $invoice->doc_type === 'credit_note'
        ? 'CREDIT NOTE'
        : ($isAr ? 'TAX INVOICE' : 'PURCHASE INVOICE');

    // Everything that identifies this document, so the block is drawn once and never half-empty.
    $meta = array_filter([
        'Date'      => optional($invoice->invoice_date)->format('d M Y'),
        'Due'       => optional($invoice->due_date)->format('d M Y'),
        'Reference' => $invoice->party_ref,
        /*
         * The supplier account this customer files us under, on their side.
         *
         * It sat on the letterhead as one company-wide number, which is what a registration number
         * is. This one differs per customer and is the field their accounts-payable clerk matches
         * against, so it belongs in the document's own identity block — and is read off the
         * invoice rather than the party, so a reissued document says what it said when it went out.
         */
        'Vendor no' => $invoice->vendor_number,
        'Sales rep' => $party->sales_rep,
        'Terms'     => $party->credit_terms_days ? $party->credit_terms_days . ' days' : null,
        'Status'    => ucfirst(str_replace('_', ' ', $invoice->status)),
    ], fn ($v) => filled($v));

    $discount = (float) ($invoice->discount_total ?? 0);
    $paid     = (float) $invoice->amount_paid;
    $due      = (float) $invoice->total - $paid;
@endphp
<!doctype html>
<html>
<head><meta charset="utf-8">
<style>
    /*
     * Set in ink and paper.
     *
     * The document used to carry a saturated accent on its title, its table header and the block
     * holding the total — three of the strongest marks on the page, none of them the number the
     * reader came for. Weight and space do that work now, and colour is reserved for the one line
     * that is genuinely exceptional: money still owed.
     *
     * Borders are hairlines or absent. A boxed layout reads as a form; an invoice should read as a
     * letter that happens to have a table in it.
     */
    @page { margin: 34px 40px 56px 40px; }

    body {
        font-family: {{ $font }};
        font-size: 10.5px;
        color: #1f2937;
        line-height: 1.5;
    }

    .title {
        font-size: {{ $layout === 'statement' ? '26px' : '24px' }};
        font-weight: bold;
        letter-spacing: 0.06em;
        color: #111827;
    }

    .muted  { color: #6b7280; }
    .r      { text-align: right; }
    .num    { font-variant-numeric: tabular-nums; white-space: nowrap; }

    /* A label above its value, which is how every one of these documents is read. */
    .label {
        font-size: 8px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #9ca3af;
    }

    table.meta { border: 0; margin-left: auto; }
    table.meta td { border: 0; padding: 2px 0; font-size: 10px; }
    table.meta td.k { color: #6b7280; padding-right: 20px; white-space: nowrap; }
    table.meta td.v { text-align: right; color: #111827; font-weight: bold; white-space: nowrap; }

    table.lines { width: 100%; border-collapse: collapse; margin-top: 18px; }
    table.lines th {
        text-align: left;
        padding: 0 9px 6px;
        font-size: 8px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #6b7280;
        border-bottom: 1px solid #d1d5db;
    }
    table.lines td { padding: 8px 9px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }

    .note { color: #6b7280; font-size: 9.5px; margin-top: 2px; }

    table.totals { width: 100%; border: 0; }
    table.totals td { border: 0; padding: 3px 0; }
    table.totals td.k { color: #6b7280; text-align: right; padding-right: 20px; }
    table.totals td.num { text-align: right; }
    table.totals tr.grand td {
        font-size: 13px;
        font-weight: bold;
        color: #111827;
        border-top: 1px solid #9ca3af;
        padding-top: 8px;
    }
    /* The only place a colour is spent, and only when something is actually outstanding. */
    table.totals tr.due td { font-weight: bold; color: {{ $accent }}; }
</style></head>
<body>
    @include('pdf.finance._letterhead')

    <table style="width:100%; border:0;"><tr>
        <td style="border:0; vertical-align:top; width:56%; padding-right:22px;">
            <div class="title">{{ $heading }}</div>
            <div class="muted" style="margin-top:2px; font-size:11px;">{{ $invoice->number }}</div>

            <div style="margin-top:12px;">
                @include('pdf.finance._party', [
                    'party'        => $party,
                    'heading'      => $isAr ? 'Bill to' : 'From',
                    'accent'       => $accent,
                    'accountLabel' => $isAr ? 'Account' : 'Supplier',
                ])
            </div>
        </td>

        <td style="border:0; vertical-align:top;">
            <table class="meta">
                @foreach($meta as $label => $value)
                    <tr><td class="k">{{ $label }}</td><td class="v">{{ $value }}</td></tr>
                @endforeach
            </table>

            {{--
                What is actually owed, said once. A rule above it and room around it rather than a
                filled slab: the figure is the largest thing in its corner, which is enough — a
                block of solid colour shouts over the very number it is trying to present.
            --}}
            <div style="margin-top:14px; padding-top:8px; border-top:1px solid #9ca3af; text-align:right;">
                <div class="label">Amount due</div>
                <div style="font-size:19px; font-weight:bold; color:#111827;" class="num">
                    {{ $invoice->currency }} {{ number_format($due, 2) }}
                </div>
            </div>
        </td>
    </tr></table>

    <table class="lines">
        <thead><tr>
            <th style="width:48%;">Description</th>
            <th class="r">Qty</th>
            <th class="r">Unit price</th>
            <th class="r">VAT</th>
            <th class="r">Amount</th>
        </tr></thead>
        <tbody>
        @foreach($invoice->lines as $l)
            <tr>
                <td>{{ $l->description }}@if($l->note)<div class="note">{{ $l->note }}</div>@endif</td>
                <td class="r num">{{ rtrim(rtrim(number_format($l->quantity, 2), '0'), '.') }}</td>
                <td class="r num">{{ number_format($l->unit_price, 2) }}</td>
                <td class="r num">{{ rtrim(rtrim(number_format($l->tax_rate, 2), '0'), '.') }}%</td>
                <td class="r num">{{ number_format($l->line_total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table style="width:100%; border:0; margin-top:16px;"><tr>
        <td style="border:0; vertical-align:top; width:52%; padding-right:24px;">
            @if($invoice->description)
                <div class="label" style="margin-bottom:3px;">Notes</div>
                <div class="muted">{!! nl2br(e($invoice->description)) !!}</div>
            @endif
        </td>
        <td style="border:0; vertical-align:top;">
            <table class="totals">
                <tr><td class="k">Subtotal (excl. VAT)</td><td class="num">{{ number_format($invoice->subtotal, 2) }}</td></tr>

                {{-- Only when there is one. A discount line reading 0.00 invites the question of why. --}}
                @if($discount > 0)
                    <tr><td class="k">Discount</td><td class="num">−{{ number_format($discount, 2) }}</td></tr>
                @endif

                <tr><td class="k">VAT</td><td class="num">{{ number_format($invoice->tax_total, 2) }}</td></tr>
                <tr class="grand"><td class="k" style="color:#111827;">Total ({{ $invoice->currency }})</td><td class="num">{{ number_format($invoice->total, 2) }}</td></tr>

                @if($paid > 0)
                    <tr><td class="k">Paid</td><td class="num">−{{ number_format($paid, 2) }}</td></tr>
                    <tr class="due"><td class="k" style="color:{{ $accent }};">Balance due</td><td class="num">{{ number_format($due, 2) }}</td></tr>
                @endif
            </table>
        </td>
    </tr></table>

    @if(!empty($qr))
        <table style="width:100%; border:0; margin-top:16px;"><tr>
            <td style="border:0; vertical-align:top; width:128px;"><img src="{{ $qr['uri'] }}" style="width:108px; height:108px;" /></td>
            <td style="border:0; vertical-align:middle; font-size:11px; color:#4b5563;">
                {{ $qr['caption'] }}<br>
                <span class="muted">Amount due: {{ number_format($due, 2) }}</span>
            </td>
        </tr></table>
    @endif

    @include('pdf.finance._footer')
</body>
</html>
