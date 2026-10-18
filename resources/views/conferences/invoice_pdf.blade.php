<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 12px; }
        .head { display: flex; justify-content: space-between; }
        h1 { font-size: 22px; margin: 0; color: #4f46e5; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e5e7eb; }
        td.amt, th.amt { text-align: right; }
        .totals { margin-top: 12px; width: 40%; float: right; }
        .totals td { border: none; padding: 4px 8px; }
        .box { background: #f9fafb; padding: 12px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="head">
        <div>
            <h1>{{ ucfirst($invoice->type) }}</h1>
            <div class="muted">{{ $invoice->invoice_number }}</div>
            <div class="muted">Issued {{ $invoice->issued_at->format('j F Y') }}</div>
            @if($invoice->purchase_order_number)
                <div class="muted">PO: {{ $invoice->purchase_order_number }}</div>
            @endif
            <div class="muted">Status: {{ ucfirst($invoice->status) }}</div>
        </div>
        <div style="text-align:right">
            <strong>{{ $conference->title }}</strong><br>
            <span class="muted">
                @if($conference->start_date){{ $conference->start_date->format('j M Y') }}@endif
            </span>
        </div>
    </div>

    <div class="box" style="margin-top:18px">
        <strong>Bill to</strong><br>
        @php($b = $invoice->billing_details ?? [])
        {{ $b['name'] ?? $b['company'] ?? $invoice->registration?->name }}<br>
        @if(!empty($b['vat_number']))VAT: {{ $b['vat_number'] }}<br>@endif
        @if(!empty($b['address'])){{ $b['address'] }}<br>@endif
        @if(!empty($b['email'])){{ $b['email'] }}@endif
    </div>

    <table>
        <thead><tr><th>Description</th><th class="amt">Amount</th></tr></thead>
        <tbody>
            @foreach($lineItems as $item)
                <tr><td>{{ $item['label'] }}</td><td class="amt">{{ $money($item['amount_cents']) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="amt">{{ $money($invoice->subtotal_cents) }}</td></tr>
        <tr><td>VAT ({{ rtrim(rtrim(number_format($invoice->vat_rate, 2), '0'), '.') }}%)</td><td class="amt">{{ $money($invoice->vat_cents) }}</td></tr>
        <tr><td><strong>Total</strong></td><td class="amt"><strong>{{ $invoice->total_cents > 0 ? ($invoice->registration?->currency ?? 'ZAR') : '' }} {{ $money($invoice->total_cents) }}</strong></td></tr>
    </table>
</body>
</html>
