@php
    $h = $data['header'];
    $t = $data['totals'];
    $p = $data['period'];
    $errors = $data['validation']['errors'] ?? [];
    $money = fn ($n) => number_format((float) $n, 2, '.', ' ');
    $statusLabel = ['new' => 'Engaged', 'terminated' => 'Terminated', 'active' => 'Active'];
@endphp
<!doctype html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
    .head { border-bottom: 3px solid #0b6b3a; padding-bottom: 8px; margin-bottom: 12px; }
    .title { font-size: 18px; font-weight: bold; color: #0b6b3a; }
    .muted { color: #666; }
    .sub { font-size: 9px; color: #555; }
    table.g { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.g th { background: #0b6b3a; color: #fff; text-align: left; padding: 5px 7px; font-size: 9px; }
    table.g td { padding: 4px 7px; border-bottom: 1px solid #eee; }
    .r { text-align: right; }
    .stamp { display:inline-block; padding:3px 10px; border-radius:12px; font-size:10px; font-weight:bold; }
    .ok { background:#e6f4ec; color:#0b6b3a; }
    .warn { background:#fbeaea; color:#b3261e; }
    .tag { display:inline-block; padding:1px 6px; border-radius:8px; font-size:8px; font-weight:bold; }
    .tag-new { background:#e6f0f7; color:#1f4e79; }
    .tag-term { background:#fbeaea; color:#b3261e; }
    .footer { margin-top: 14px; font-size: 8px; color:#999; border-top:1px solid #eee; padding-top:6px; }
</style></head>
<body>
    <div class="head">
        <table style="width:100%; border:0;"><tr>
            <td style="border:0;">
                <div class="title">UI-19 — Monthly UIF Declaration</div>
                <div class="sub">{{ $h['company_name'] ?: '—' }} · UIF ref {{ $h['uif_reference'] ?: '—' }} · {{ $p['label'] }}</div>
            </td>
            <td style="border:0; text-align:right;" class="sub">
                <div><strong>{{ $submission->reference }}</strong> · v{{ $submission->version }}</div>
                <div>Generated {{ optional($submission->generated_at)->format('d M Y') ?: now()->format('d M Y') }}</div>
                @if(empty($errors))<div style="margin-top:4px;"><span class="stamp ok">✓ Validated</span></div>
                @else<div style="margin-top:4px;"><span class="stamp warn">{{ count($errors) }} issue{{ count($errors) === 1 ? '' : 's' }}</span></div>@endif
            </td>
        </tr></table>
    </div>

    <div class="sub" style="margin-bottom:6px;">
        {{ $t['employee_count'] }} employees · {{ $t['new_count'] }} engaged · {{ $t['terminated_count'] }} terminated ·
        UIF payable {{ $money($t['uif_total']) }} ({{ $money($t['uif_employee']) }} employee + {{ $money($t['uif_employer']) }} employer)
    </div>

    <table class="g">
        <thead><tr>
            <th>Employee</th><th>ID number</th><th>Engaged</th><th>Terminated</th><th>Status</th>
            <th class="r">UIF remuneration</th><th class="r">UIF employee</th><th class="r">UIF employer</th><th class="r">Total</th>
        </tr></thead>
        <tbody>
            @foreach($data['employees'] as $e)
                <tr>
                    <td>{{ $e['name'] }}</td>
                    <td>{{ $e['id_number'] ?: '—' }}</td>
                    <td>{{ $e['date_joined'] ?: '—' }}</td>
                    <td>{{ $e['date_left'] ?: '—' }}</td>
                    <td>
                        @if($e['status'] === 'new')<span class="tag tag-new">Engaged</span>
                        @elseif($e['status'] === 'terminated')<span class="tag tag-term">Terminated</span>
                        @else Active @endif
                        @if($e['reason_code']) <span class="muted">({{ $e['reason_code'] }})</span>@endif
                    </td>
                    <td class="r">{{ $money($e['remuneration']) }}</td>
                    <td class="r">{{ $money($e['uif_employee']) }}</td>
                    <td class="r">{{ $money($e['uif_employer']) }}</td>
                    <td class="r">{{ $money($e['uif_total']) }}</td>
                </tr>
            @endforeach
            <tr style="font-weight:bold;">
                <td colspan="5" class="r">Totals</td>
                <td class="r" style="border-top:2px solid #0b6b3a;">{{ $money($t['total_remuneration']) }}</td>
                <td class="r" style="border-top:2px solid #0b6b3a;">{{ $money($t['uif_employee']) }}</td>
                <td class="r" style="border-top:2px solid #0b6b3a;">{{ $money($t['uif_employer']) }}</td>
                <td class="r" style="border-top:2px solid #0b6b3a;">{{ $money($t['uif_total']) }}</td>
            </tr>
        </tbody>
    </table>

    @if(!empty($errors))
        <div style="margin-top:12px;">
            <div style="font-weight:bold; color:#b3261e; margin-bottom:4px;">Validation issues</div>
            <table class="g"><tbody>
                @foreach($errors as $e)
                    <tr><td style="width:150px;" class="muted">{{ $e['field'] ?? $e['entity'] ?? '' }}</td><td>{{ $e['message'] ?? '' }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
    @endif

    <div class="footer">
        Generated by Mkhaya Hub from finalised payroll for {{ $p['label'] }}. A working UIF declaration for review — the official UI-19 is submitted to the UIF (uFiling / Department of Employment & Labour). Reason codes: 01 engaged, 17 terminated.
    </div>
</body>
</html>
