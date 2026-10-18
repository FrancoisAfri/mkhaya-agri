<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pay invoice {{ $invoice->number }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f5f7; color: #1f2733; }
        @media (prefers-color-scheme: dark) { body { background: #0f1115; color: #e6e8eb; } .card { background: #1a1d23 !important; } .muted { color: #9aa4b2 !important; } .box { background:#12151a !important; } }
        .wrap { max-width: 560px; margin: 0 auto; padding: 24px 16px 48px; }
        .card { background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 28px; }
        .brand { font-size: 18px; font-weight: 700; margin-bottom: 2px; }
        .muted { color: #66707d; font-size: 13px; }
        h1 { font-size: 15px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #66707d; margin: 24px 0 6px; }
        .amount { font-size: 40px; font-weight: 800; letter-spacing: -.02em; }
        .amount.paid { color: #16a34a; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(128,128,128,.15); font-size: 14px; }
        .row:last-child { border-bottom: 0; }
        .box { background: #f7f8fa; border-radius: 10px; padding: 16px; margin-top: 10px; font-size: 14px; }
        .box .row { border-color: rgba(128,128,128,.12); }
        .btn { display: block; width: 100%; text-align: center; padding: 14px; border-radius: 10px; border: 0; font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #2563eb; color: #fff; margin-top: 18px; }
        .btn-snap { background: #111827; color: #fff; margin-top: 10px; }
        .ok { background: #dcfce7; color: #166534; border-radius: 10px; padding: 12px 14px; font-size: 14px; margin-bottom: 16px; }
        .pill { display:inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .pill.over { background: #fee2e2; color: #991b1b; }
        .pill.due { background: #fef9c3; color: #854d0e; }
        .foot { text-align: center; margin-top: 20px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="brand">{{ $settings->company_name ?: config('app.name') }}</div>
            <div class="muted">{{ $settings->email }}{{ $settings->phone ? ' · '.$settings->phone : '' }}</div>

            @if(session('paid_notified'))
                <div class="ok" style="margin-top:18px">Thank you — we’ve noted that you’ve paid. Your account will be updated once the payment reflects.</div>
            @endif

            <h1>{{ $paid ? 'Invoice paid' : 'Amount due' }}</h1>
            <div class="amount {{ $paid ? 'paid' : '' }}">{{ $invoice->currency }} {{ number_format($paid ? (float)$invoice->total : $outstanding, 2) }}</div>
            @unless($paid)
                @php $overdue = $invoice->due_date && $invoice->due_date->isPast(); @endphp
                <div style="margin-top:8px">
                    <span class="pill {{ $overdue ? 'over' : 'due' }}">
                        {{ $overdue ? 'Overdue — was due '.$invoice->due_date->format('d M Y') : 'Due '.optional($invoice->due_date)->format('d M Y') }}
                    </span>
                </div>
            @endunless

            <div class="box" style="margin-top:20px">
                <div class="row"><span class="muted">Invoice</span><span>{{ $invoice->number }}</span></div>
                <div class="row"><span class="muted">Billed to</span><span>{{ $invoice->party->name }}</span></div>
                <div class="row"><span class="muted">Invoice total</span><span>{{ $invoice->currency }} {{ number_format((float)$invoice->total, 2) }}</span></div>
                @if((float)$invoice->amount_paid > 0)
                    <div class="row"><span class="muted">Already paid</span><span>{{ $invoice->currency }} {{ number_format((float)$invoice->amount_paid, 2) }}</span></div>
                @endif
            </div>

            @unless($paid)
                @if($settings->bank_name || $settings->bank_account_number)
                    <h1>Pay by EFT</h1>
                    <div class="box">
                        @if($settings->bank_account_name)<div class="row"><span class="muted">Account name</span><span>{{ $settings->bank_account_name }}</span></div>@endif
                        @if($settings->bank_name)<div class="row"><span class="muted">Bank</span><span>{{ $settings->bank_name }}</span></div>@endif
                        @if($settings->bank_account_number)<div class="row"><span class="muted">Account no.</span><span>{{ $settings->bank_account_number }}</span></div>@endif
                        @if($settings->branch_code)<div class="row"><span class="muted">Branch code</span><span>{{ $settings->branch_code }}</span></div>@endif
                        <div class="row"><span class="muted">Reference</span><span>{{ $invoice->number }}</span></div>
                    </div>
                @endif

                @if($settings->show_payment_qr && $settings->snapscan_merchant)
                    <a class="btn btn-snap" href="https://pos.snapscan.io/qr/{{ $settings->snapscan_merchant }}?amount={{ (int) round($outstanding * 100) }}&reference={{ urlencode($invoice->number) }}" target="_blank" rel="noopener">Pay with SnapScan</a>
                @endif

                <form method="POST" action="{{ route('finance.pay.notify', $token) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">I’ve paid this invoice</button>
                </form>
            @endunless
        </div>
        <div class="foot muted">Secured payment request from {{ $settings->company_name ?: config('app.name') }}</div>
    </div>
</body>
</html>
