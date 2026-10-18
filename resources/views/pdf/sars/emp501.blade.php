@php
    $h = $data['header'];
    $t = $data['totals'];
    $p = $data['period'];
    $rec = $data['reconciliation'];
    $errors = $data['validation']['errors'] ?? [];
    $money = fn ($n) => number_format((float) $n, 2, '.', ' ');
@endphp
<!doctype html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
    .head { border-bottom: 3px solid #5b2a86; padding-bottom: 8px; margin-bottom: 12px; }
    .title { font-size: 18px; font-weight: bold; color: #5b2a86; }
    .muted { color: #666; }
    .sub { font-size: 9px; color: #555; }
    table.g { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.g th { background: #5b2a86; color: #fff; text-align: left; padding: 5px 7px; font-size: 9px; }
    table.g td { padding: 4px 7px; border-bottom: 1px solid #eee; }
    .r { text-align: right; }
    .stamp { display:inline-block; padding:3px 10px; border-radius:12px; font-size:10px; font-weight:bold; }
    .ok { background:#e9e2f2; color:#5b2a86; }
    .warn { background:#fbeaea; color:#b3261e; }
    .kv td { border:0; padding:2px 0; }
    h3 { color:#5b2a86; font-size:12px; margin:16px 0 4px; }
    .footer { margin-top: 16px; font-size: 8px; color:#999; border-top:1px solid #eee; padding-top:6px; }
</style></head>
<body>
    <div class="head">
        <table style="width:100%; border:0;"><tr>
            <td style="border:0;">
                <div class="title">EMP 501 — Employer Reconciliation</div>
                <div class="sub">{{ $h['company_name'] ?: '—' }} · PAYE {{ $h['paye_reference'] ?: '—' }}</div>
            </td>
            <td style="border:0; text-align:right;" class="sub">
                <div><strong>{{ $submission->reference }}</strong> · v{{ $submission->version }}</div>
                <div>Tax year {{ $p['tax_year'] }} ({{ $p['label'] }})</div>
                <div>Generated {{ optional($submission->generated_at)->format('d M Y') ?: now()->format('d M Y') }}</div>
            </td>
        </tr></table>
    </div>

    <table style="width:100%; border:0;"><tr>
        <td style="border:0; width:60%; vertical-align:top;">
            <h3 style="margin-top:0;">Reconciliation</h3>
            <table class="kv">
                <tr><td class="muted" style="padding-right:20px;">PAYE declared (EMP201 × 12)</td><td class="r">{{ $money($rec['declared_paye']) }}</td></tr>
                <tr><td class="muted">PAYE on certificates</td><td class="r">{{ $money($rec['certificate_paye']) }}</td></tr>
                <tr><td class="muted" style="border-top:1px solid #ddd;"><strong>PAYE difference</strong></td><td class="r" style="border-top:1px solid #ddd; color:{{ abs($rec['paye_difference']) < 0.01 ? '#2e7d32' : '#b3261e' }};"><strong>{{ $money($rec['paye_difference']) }}</strong></td></tr>
                <tr><td class="muted" style="padding-top:6px;">UIF (employee) declared</td><td class="r" style="padding-top:6px;">{{ $money($rec['declared_uif']) }}</td></tr>
                <tr><td class="muted">UIF on certificates</td><td class="r">{{ $money($rec['certificate_uif']) }}</td></tr>
                <tr><td class="muted">SDL declared</td><td class="r">{{ $money($rec['declared_sdl']) }}</td></tr>
            </table>
        </td>
        <td style="border:0; vertical-align:top; text-align:right;">
            @if($rec['balanced'] && empty($errors))
                <span class="stamp ok">✓ Reconciled — no errors</span>
            @elseif($rec['balanced'])
                <span class="stamp warn">{{ count($errors) }} validation issue{{ count($errors) === 1 ? '' : 's' }}</span>
            @else
                <span class="stamp warn">Does not reconcile</span>
            @endif
            <div class="sub" style="margin-top:8px;">
                {{ $t['certificate_count'] }} certificates<br>
                {{ $t['irp5_count'] }} IRP5 · {{ $t['it3a_count'] }} IT3(a)
            </div>
        </td>
    </tr></table>

    <h3>IRP5 / IT3(a) certificate schedule</h3>
    <table class="g">
        <thead><tr>
            <th>Employee</th><th>Tax no.</th><th>Type</th><th class="r">Income (3601)</th><th class="r">PAYE (4102)</th><th class="r">UIF (4141)</th>
        </tr></thead>
        <tbody>
            @foreach($data['certificates'] as $c)
                <tr>
                    <td>{{ $c['name'] }}</td>
                    <td>{{ $c['tax_number'] ?: '—' }}</td>
                    <td>{{ $c['type'] }}</td>
                    <td class="r">{{ $money($c['income']) }}</td>
                    <td class="r">{{ $money($c['paye']) }}</td>
                    <td class="r">{{ $money($c['uif']) }}</td>
                </tr>
            @endforeach
            <tr style="font-weight:bold;">
                <td colspan="3" class="r">Totals</td>
                <td class="r" style="border-top:2px solid #5b2a86;">{{ $money($t['total_income']) }}</td>
                <td class="r" style="border-top:2px solid #5b2a86;">{{ $money($t['total_paye']) }}</td>
                <td class="r" style="border-top:2px solid #5b2a86;">{{ $money($t['total_uif']) }}</td>
            </tr>
        </tbody>
    </table>

    @if(!empty($errors))
        <h3>Validation issues</h3>
        <table class="g"><tbody>
            @foreach($errors as $e)
                <tr><td style="width:130px;" class="muted">{{ $e['field'] ?? $e['entity'] ?? '' }}</td><td>{{ $e['message'] ?? '' }}</td></tr>
            @endforeach
        </tbody></table>
    @endif

    <div class="footer">
        Generated by Mkhaya Agri from finalised payroll for tax year {{ $p['tax_year'] }}. A working reconciliation for review — the official EMP501 and certificates are submitted via SARS e@syFile / eFiling.
    </div>
</body>
</html>
