@php
    $h = $data['header'];
    $t = $data['totals'];
    $p = $data['period'];
    $errors = $data['validation']['errors'] ?? [];
    $money = fn ($n) => number_format((float) $n, 2, '.', ' ');
@endphp
<!doctype html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    .head { border-bottom: 3px solid #0b6b3a; padding-bottom: 8px; margin-bottom: 14px; }
    .title { font-size: 20px; font-weight: bold; color: #0b6b3a; }
    .muted { color: #666; }
    .sub { font-size: 10px; color: #555; }
    table.form { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.form th { background: #0b6b3a; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
    table.form td { padding: 6px 8px; border-bottom: 1px solid #eee; }
    .r { text-align: right; }
    .box-no { display:inline-block; width:26px; height:18px; line-height:18px; text-align:center; background:#eef4f0; border:1px solid #cfe0d6; border-radius:3px; font-weight:bold; color:#0b6b3a; margin-right:6px; }
    .net { font-size: 15px; font-weight: bold; }
    .kv td { border:0; padding:2px 0; }
    .stamp { display:inline-block; padding:3px 10px; border-radius:12px; font-size:10px; font-weight:bold; }
    .ok { background:#e6f4ec; color:#0b6b3a; }
    .warn { background:#fbeaea; color:#b3261e; }
    .footer { margin-top: 20px; font-size: 9px; color:#999; border-top:1px solid #eee; padding-top:6px; }
</style></head>
<body>
    <div class="head">
        <table style="width:100%; border:0;"><tr>
            <td style="border:0;">
                <div class="title">VAT 201 — Value-Added Tax Return</div>
                <div class="sub">{{ $h['company_name'] ?: '—' }}</div>
            </td>
            <td style="border:0; text-align:right;" class="sub">
                <div><strong>{{ $submission->reference }}</strong> · v{{ $submission->version }}</div>
                <div>Generated {{ optional($submission->generated_at)->format('d M Y') ?: now()->format('d M Y') }}</div>
            </td>
        </tr></table>
    </div>

    <table class="kv" style="width:100%; border:0;"><tr>
        <td style="border:0; width:50%; vertical-align:top;">
            <table class="kv"><tbody>
                <tr><td class="muted">VAT registration no.</td><td><strong>{{ $h['vat_number'] ?: '—' }}</strong></td></tr>
                <tr><td class="muted">Tax period</td><td><strong>{{ $p['label'] }}</strong> ({{ $p['from'] }} → {{ $p['to'] }})</td></tr>
                <tr><td class="muted">Filing frequency</td><td>{{ ucwords(str_replace('_',' ', (string) $h['vat_frequency'])) ?: '—' }}</td></tr>
            </tbody></table>
        </td>
        <td style="border:0; vertical-align:top; text-align:right;">
            @if(empty($errors))
                <span class="stamp ok">✓ Validated — no errors</span>
            @else
                <span class="stamp warn">{{ count($errors) }} validation issue{{ count($errors) === 1 ? '' : 's' }}</span>
            @endif
        </td>
    </tr></table>

    <table class="form">
        <thead><tr><th style="width:60px;">Box</th><th>Description</th><th class="r" style="width:150px;">Amount (R)</th></tr></thead>
        <tbody>
            @foreach($data['boxes'] as $b)
                <tr>
                    <td><span class="box-no">{{ $b['field'] }}</span></td>
                    <td>{{ $b['label'] }}</td>
                    <td class="r">{{ $money($b['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="width:100%; border:0; margin-top:14px;"><tr>
        <td style="border:0; width:55%; vertical-align:top;" class="sub">
            <div class="muted">Context (this period)</div>
            <table class="kv"><tbody>
                <tr><td class="muted">Total sales (income)</td><td class="r">{{ $money($t['total_sales']) }}</td></tr>
                <tr><td class="muted">Total purchases (expense)</td><td class="r">{{ $money($t['total_purchases']) }}</td></tr>
            </tbody></table>
        </td>
        <td style="border:0; vertical-align:top;">
            <table class="kv" style="width:100%;"><tbody>
                <tr><td class="muted r">Output tax</td><td class="r">{{ $money($t['output_tax']) }}</td></tr>
                <tr><td class="muted r">Input tax</td><td class="r">−{{ $money($t['input_tax']) }}</td></tr>
                <tr><td class="r" style="border-top:2px solid #0b6b3a;">
                        <span class="net">{{ $t['is_payable'] ? 'Payable to SARS' : 'Refundable by SARS' }}</span>
                    </td>
                    <td class="r net" style="border-top:2px solid #0b6b3a;">{{ $money(abs($t['net'])) }}</td>
                </tr>
            </tbody></table>
        </td>
    </tr></table>

    @if(!empty($errors))
        <div style="margin-top:16px;">
            <div style="font-weight:bold; color:#b3261e; margin-bottom:4px;">Validation issues</div>
            <table class="form"><tbody>
                @foreach($errors as $e)
                    <tr><td style="width:120px;" class="muted">{{ $e['field'] ?? $e['entity'] ?? '' }}</td><td colspan="2">{{ $e['message'] ?? '' }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
    @endif

    <div class="footer">
        This VAT201 was generated by Mkhaya Agri from the general ledger for the stated period. It is a working document for review and record-keeping — the official return must be submitted via SARS eFiling. Contact: {{ $h['contact_person'] ?: '—' }} {{ $h['contact_email'] ? '· ' . $h['contact_email'] : '' }}.
    </div>
</body>
</html>
