@php
    $money = fn ($n) => number_format((float) $n, 2, '.', ' ');
    $isIrp5 = $cert['type'] === 'IRP5';
@endphp
<!doctype html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    .head { border-bottom: 3px solid #1f4e79; padding-bottom: 8px; margin-bottom: 14px; }
    .title { font-size: 20px; font-weight: bold; color: #1f4e79; }
    .muted { color: #666; }
    .sub { font-size: 10px; color: #555; }
    .box { border: 1px solid #e2e2e2; border-radius: 5px; padding: 10px 12px; margin-top: 10px; }
    .box h4 { margin: 0 0 6px; color: #1f4e79; font-size: 11px; }
    table.kv { width: 100%; }
    table.kv td { border: 0; padding: 2px 0; }
    table.codes { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.codes th { background: #1f4e79; color: #fff; text-align: left; padding: 5px 8px; font-size: 10px; }
    table.codes td { padding: 5px 8px; border-bottom: 1px solid #eee; }
    .code { display:inline-block; width:40px; text-align:center; background:#eef2f7; border:1px solid #cfdcea; border-radius:3px; font-weight:bold; color:#1f4e79; }
    .r { text-align: right; }
    .pill { display:inline-block; padding:2px 10px; border-radius:12px; font-size:10px; font-weight:bold; background:#e6f0f7; color:#1f4e79; }
    .footer { margin-top: 20px; font-size: 9px; color:#999; border-top:1px solid #eee; padding-top:6px; }
</style></head>
<body>
    <div class="head">
        <table style="width:100%; border:0;"><tr>
            <td style="border:0;">
                <div class="title">{{ $isIrp5 ? 'IRP5' : 'IT3(a)' }} — Employee Tax Certificate</div>
                <div class="sub">{{ $company ?: '—' }}</div>
            </td>
            <td style="border:0; text-align:right;" class="sub">
                <span class="pill">{{ $cert['type'] }}</span>
                <div style="margin-top:4px;">Tax year {{ $period['tax_year'] }}</div>
                <div>{{ $period['label'] }}</div>
                {{-- Only an issued certificate has these. A preview computed from live payroll has
                     no number because nothing has been certified yet, and printing one would say
                     this document is a record when it is not. --}}
                @if(!empty($cert['certificate_number']))
                    <div style="margin-top:4px;">No. {{ $cert['certificate_number'] }}</div>
                    @if(($cert['version'] ?? 1) > 1)
                        <div><strong>Version {{ $cert['version'] }}</strong></div>
                    @endif
                @endif
            </td>
        </tr></table>
    </div>

    <table style="width:100%; border:0;"><tr>
        <td style="border:0; width:50%; vertical-align:top;">
            <div class="box">
                <h4>Employee</h4>
                <table class="kv">
                    <tr><td class="muted">Name</td><td><strong>{{ $cert['name'] }}</strong></td></tr>
                    <tr><td class="muted">ID number</td><td>{{ $cert['id_number'] ?: '—' }}</td></tr>
                    <tr><td class="muted">Income tax no.</td><td>{{ $cert['tax_number'] ?: '—' }}</td></tr>
                    <tr><td class="muted">Periods worked</td><td>{{ $cert['periods'] }}</td></tr>
                </table>
            </div>
        </td>
        <td style="border:0; width:50%; vertical-align:top;">
            <div class="box">
                <h4>Employer</h4>
                <table class="kv">
                    <tr><td class="muted">Name</td><td>{{ $company ?: '—' }}</td></tr>
                    <tr><td class="muted">PAYE ref</td><td>{{ $config->paye_reference ?: '—' }}</td></tr>
                    <tr><td class="muted">UIF ref</td><td>{{ $config->uif_reference ?: '—' }}</td></tr>
                </table>
            </div>
        </td>
    </tr></table>

    <table class="codes">
        <thead><tr><th style="width:70px;">Code</th><th>Description</th><th class="r" style="width:150px;">Amount (R)</th></tr></thead>
        <tbody>
            @foreach($cert['codes'] as $c)
                <tr>
                    <td><span class="code">{{ $c['code'] }}</span></td>
                    <td>{{ $c['label'] }}</td>
                    <td class="r">{{ $money($c['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="sub" style="margin-top:10px;">
        @if($isIrp5) PAYE was deducted, so this is an IRP5. @else No PAYE was deducted in the tax year, so this is an IT3(a). @endif
    </div>

    {{-- An employee holding two documents has to be able to tell which is current, and why the
         second one exists. --}}
    @if(($cert['version'] ?? 1) > 1)
        <div class="sub" style="margin-top:6px;">
            This replaces version {{ $cert['version'] - 1 }} of this certificate. Use this one.
        </div>
    @endif

    <div class="footer">
        @if(!empty($cert['certificate_number']))
            Issued {{ $cert['issued_at'] ?? '' }} from the payroll figures as they stood on that date, and unchanged since.
        @else
            Generated from live payroll and not yet issued — a working certificate for review, which will change if payroll changes.
        @endif
        The official IRP5/IT3(a) is filed with SARS via e@syFile as part of the EMP501 reconciliation.
    </div>
</body>
</html>
