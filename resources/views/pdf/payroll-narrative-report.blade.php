<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Payroll Report — {{ $run->ref_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; background: #fff; padding: 28px; }

        .header { background: #1e3a5f; color: #fff; padding: 16px 20px; border-radius: 4px; margin-bottom: 20px; }
        .header .title { font-size: 16px; font-weight: bold; }
        .header .meta { margin-top: 5px; font-size: 9px; color: #c8d8e8; }
        .header .meta span { margin-right: 20px; }

        h2 { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #1e3a5f; border-bottom: 1px solid #d0d7de; padding-bottom: 4px; margin: 18px 0 8px; }
        h3 { font-size: 10px; font-weight: bold; color: #374151; margin: 10px 0 4px; }

        .narrative { background: #f0f4fa; border-left: 3px solid #1e3a5f; padding: 8px 12px; font-size: 10px; line-height: 1.5; margin-bottom: 10px; border-radius: 0 4px 4px 0; }

        .kpi-row { display: flex; gap: 10px; margin-bottom: 12px; }
        .kpi { flex: 1; border: 1px solid #d0d7de; border-radius: 4px; padding: 8px; text-align: center; }
        .kpi .val { font-size: 13px; font-weight: bold; color: #1e3a5f; }
        .kpi .lbl { font-size: 8px; color: #6b7280; margin-top: 2px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th { background: #1e3a5f; color: #fff; padding: 5px 8px; font-size: 9px; text-align: left; }
        td { padding: 4px 8px; font-size: 9px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) td { background: #f8fafc; }

        .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 8px; font-weight: bold; }
        .badge-critical { background: #fce7f3; color: #9d174d; }
        .badge-high     { background: #fee2e2; color: #991b1b; }
        .badge-medium   { background: #fef3c7; color: #92400e; }
        .badge-open     { background: #fee2e2; color: #991b1b; }
        .badge-resolved { background: #d1fae5; color: #065f46; }

        .footer { margin-top: 24px; border-top: 1px solid #d0d7de; padding-top: 8px; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>

<div class="header">
    <div class="title">Payroll Narrative Report — {{ $run->ref_number }}</div>
    <div class="meta">
        <span>Period: {{ $run->period_start->format('d M Y') }} – {{ $run->period_end->format('d M Y') }}</span>
        <span>Employees: {{ number_format($run->employee_count) }}</span>
        <span>Status: {{ $run->status }}</span>
        <span>Generated: {{ now()->format('d M Y H:i') }}</span>
    </div>
</div>

{{-- ── Executive Summary ─────────────────────────────────────────────────── --}}
@php $exec = $report['executive'] ?? [] @endphp
<h2>Executive Summary</h2>
<div class="narrative">{{ $exec['narrative'] ?? '' }}</div>
<div class="kpi-row">
    <div class="kpi"><div class="val">R {{ number_format($exec['total_gross'] ?? 0, 2) }}</div><div class="lbl">Total Gross</div></div>
    <div class="kpi"><div class="val">R {{ number_format($exec['total_net'] ?? 0, 2) }}</div><div class="lbl">Total Net</div></div>
    <div class="kpi"><div class="val">R {{ number_format($exec['total_deductions'] ?? 0, 2) }}</div><div class="lbl">Total Deductions</div></div>
    <div class="kpi"><div class="val">{{ $exec['employee_count'] ?? 0 }}</div><div class="lbl">Employees</div></div>
    <div class="kpi"><div class="val">{{ $exec['open_anomalies'] ?? 0 }}</div><div class="lbl">Open Anomalies</div></div>
</div>

{{-- ── Variance Explanation ──────────────────────────────────────────────── --}}
@php $variance = $report['variance'] ?? [] @endphp
<h2>Variance Explanation</h2>
<div class="narrative">{{ $variance['narrative'] ?? '' }}</div>
@if (!empty($variance['items']))
    <table>
        <thead><tr><th>Factor</th><th>Change</th><th>Detail</th></tr></thead>
        <tbody>
            @foreach ($variance['items'] as $item)
            <tr>
                <td>{{ $item['factor'] }}</td>
                <td>{{ $item['change'] }}</td>
                <td>{{ $item['narrative'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- ── Department Breakdown ─────────────────────────────────────────────── --}}
@php $depts = $report['departments'] ?? [] @endphp
<h2>Department Breakdown</h2>
<div class="narrative">{{ $depts['narrative'] ?? '' }}</div>
@if (!empty($depts['sections']))
    <table>
        <thead><tr><th>Department</th><th>Headcount</th><th>Total Gross</th><th>Total Net</th><th>Avg Gross</th><th>Share %</th></tr></thead>
        <tbody>
            @foreach ($depts['sections'] as $dept)
            <tr>
                <td>{{ $dept['department'] }}</td>
                <td>{{ $dept['headcount'] }}</td>
                <td>R {{ number_format($dept['total_gross'], 2) }}</td>
                <td>R {{ number_format($dept['total_net'], 2) }}</td>
                <td>R {{ number_format($dept['avg_gross'], 2) }}</td>
                <td>{{ $dept['share_pct'] }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- ── Risk Report ──────────────────────────────────────────────────────── --}}
@php $risk = $report['risk'] ?? [] @endphp
<h2>Risk Report</h2>
<div class="narrative">{{ $risk['narrative'] ?? '' }}</div>
@if (!empty($risk['anomaly_summary']))
    <table>
        <thead><tr><th>Anomaly Type</th><th>Severity</th><th>Status</th><th>Count</th></tr></thead>
        <tbody>
            @foreach ($risk['anomaly_summary'] as $r)
            <tr>
                <td>{{ str_replace('_', ' ', $r['anomaly_type'] ?? '') }}</td>
                <td><span class="badge badge-{{ $r['severity'] ?? '' }}">{{ $r['severity'] ?? '' }}</span></td>
                <td><span class="badge badge-{{ $r['status'] ?? '' }}">{{ $r['status'] ?? '' }}</span></td>
                <td>{{ $r['cnt'] ?? 0 }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- ── Compliance Summary ───────────────────────────────────────────────── --}}
@php $comp = $report['compliance'] ?? [] @endphp
<h2>Compliance Summary</h2>
<div class="narrative">{{ $comp['narrative'] ?? '' }}</div>
@if (!empty($comp['violations']))
    <table>
        <thead><tr><th>Violation Type</th><th>Employee #</th><th>Severity</th><th>Status</th><th>Description</th></tr></thead>
        <tbody>
            @foreach ($comp['violations'] as $v)
            <tr>
                <td>{{ str_replace('_', ' ', $v['type'] ?? '') }}</td>
                <td>{{ $v['employee_id'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $v['severity'] ?? '' }}">{{ $v['severity'] ?? '' }}</span></td>
                <td><span class="badge badge-{{ $v['status'] ?? '' }}">{{ $v['status'] ?? '' }}</span></td>
                <td>{{ Str::limit($v['description'] ?? '', 80) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- ── Leakage Report ───────────────────────────────────────────────────── --}}
@php $leakage = $report['leakage'] ?? [] @endphp
<h2>Leakage Report</h2>
<div class="narrative">{{ $leakage['narrative'] ?? '' }}</div>
@if (!empty($leakage['items']))
    <table>
        <thead><tr><th>Type</th><th>Employee #</th><th>Severity</th><th>Status</th><th>Description</th></tr></thead>
        <tbody>
            @foreach ($leakage['items'] as $item)
            <tr>
                <td>{{ str_replace('_', ' ', $item['type'] ?? '') }}</td>
                <td>{{ $item['employee_id'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $item['severity'] ?? '' }}">{{ $item['severity'] ?? '' }}</span></td>
                <td><span class="badge badge-{{ $item['status'] ?? '' }}">{{ $item['status'] ?? '' }}</span></td>
                <td>{{ Str::limit($item['description'] ?? '', 80) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="footer">
    Generated by Mkhaya HR · {{ now()->format('d M Y H:i') }} · Payroll Run {{ $run->ref_number }} · CONFIDENTIAL
</div>

</body>
</html>
