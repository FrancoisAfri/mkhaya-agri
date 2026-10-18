<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $report->reference }}</title>
    <style>
        body   { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1     { font-size: 18px; margin: 0 0 2px; }
        h2     { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .muted { color: #777; }
        .head  { width: 100%; margin-bottom: 18px; }
        .head td { vertical-align: top; }
        .right { text-align: right; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data th { text-align: left; background: #f4f4f4; padding: 5px; font-size: 10px; }
        table.data td { padding: 5px; border-bottom: 1px solid #eee; }
        .totals { width: 45%; margin-left: auto; border-collapse: collapse; }
        .totals td { padding: 4px 6px; }
        .totals .grand { border-top: 1.5px solid #333; font-weight: bold; }
        .ticket { margin-bottom: 4px; font-weight: bold; }
        .note   { font-size: 10px; color: #444; }
        .badge  { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 9px; background: #eee; }
    </style>
</head>
<body>

<table class="head">
    <tr>
        <td>
            <h1>{{ $company['company_name'] ?? 'Service Report' }}</h1>
            <div class="muted">{{ $company['full_company_name'] ?? '' }}</div>
        </td>
        <td class="right">
            <strong>{{ $report->reference }}</strong><br>
            <span class="muted">
                {{ $report->period_start->format('j M Y') }} &ndash; {{ $report->period_end->format('j M Y') }}
            </span>
        </td>
    </tr>
</table>

<table class="head">
    <tr>
        <td>
            <div class="muted">Prepared for</div>
            <strong>{{ $report->party?->name }}</strong><br>
            <span class="muted">{{ $report->party?->customer_number }}</span>
        </td>
        <td class="right">
            <div class="muted">Tickets handled</div>
            <strong>{{ $report->ticket_count }}</strong>
        </td>
    </tr>
</table>

@if ($report->summary)
    <p>{{ $report->summary }}</p>
@endif

<h2>Work carried out</h2>

@forelse ($lines as $line)
    <div class="ticket">
        {{ $line['ticket_number'] }} &mdash; {{ $line['subject'] }}
        <span class="muted">({{ $line['hours'] }} h)</span>
    </div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:14%">Date</th>
                <th style="width:12%">Time</th>
                <th>What was done</th>
                <th style="width:12%">Chargeable</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($line['entries'] as $entry)
                <tr>
                    <td>{{ $entry['date'] }}</td>
                    <td>{{ $entry['minutes'] }} min</td>
                    <td class="note">{{ $entry['note'] ?: '—' }}</td>
                    <td><span class="badge">{{ $entry['billable'] ? 'Yes' : 'No' }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@empty
    <p class="muted">No work was recorded for this period.</p>
@endforelse

<h2>Summary</h2>

<table class="totals">
    <tr>
        <td>Total time recorded</td>
        <td class="right">{{ $report->totalHours() }} h</td>
    </tr>
    <tr>
        <td>Chargeable time</td>
        <td class="right">{{ $report->billableHours() }} h</td>
    </tr>

    {{-- Only meaningful when the customer actually has an agreement. --}}
    @if ($report->entitlement_minutes !== null)
        <tr>
            <td>Included in your agreement</td>
            <td class="right">{{ round($report->entitlement_minutes / 60, 2) }} h</td>
        </tr>
        <tr>
            <td>Beyond included hours</td>
            <td class="right">{{ $report->overageHours() }} h</td>
        </tr>
        @if ($report->overage_value > 0)
            <tr class="grand">
                <td>Chargeable this period</td>
                <td class="right">{{ number_format((float) $report->overage_value, 2) }}</td>
            </tr>
        @else
            <tr class="grand">
                <td>Chargeable this period</td>
                <td class="right">Within your included hours</td>
            </tr>
        @endif
    @endif
</table>

<p class="muted" style="margin-top:24px; font-size:9px;">
    Times shown are working hours, excluding weekends, public holidays and non-working periods.
    Approved {{ $report->approved_at?->format('j M Y') }}.
</p>

</body>
</html>
