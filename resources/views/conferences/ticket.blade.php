<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1f2937; }
        .ticket { border: 2px solid #4f46e5; border-radius: 12px; padding: 24px; width: 540px; margin: 24px auto; }
        .brand { color: #4f46e5; font-size: 12px; letter-spacing: 2px; text-transform: uppercase; }
        .title { font-size: 22px; font-weight: bold; margin: 4px 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 4px 0; font-size: 13px; vertical-align: top; }
        .label { color: #6b7280; width: 120px; }
        .code { font-size: 14px; font-weight: bold; letter-spacing: 1px; }
        .qr { text-align: center; padding-top: 12px; }
        .foot { color: #9ca3af; font-size: 11px; text-align: center; margin-top: 14px; }
    </style>
</head>
<body>
<div class="ticket">
    <div class="brand">Conference Ticket</div>
    <div class="title">{{ $conference['title'] }}</div>
    <table>
        <tr><td class="label">Attendee</td><td>{{ $registration['name'] }}</td></tr>
        <tr><td class="label">Email</td><td>{{ $registration['email'] }}</td></tr>
        @if($conference['dates'])
            <tr><td class="label">When</td><td>{{ $conference['dates'] }}</td></tr>
        @endif
        @if($conference['format'])
            <tr><td class="label">Format</td><td>{{ $conference['format'] }}</td></tr>
        @endif
        <tr><td class="label">Ticket</td><td class="code">{{ $registration['ticket_code'] }}</td></tr>
        @if($registration['amount'])
            <tr><td class="label">Paid</td><td>{{ $registration['currency'] }} {{ $registration['amount'] }}</td></tr>
        @else
            <tr><td class="label">Admission</td><td>Free</td></tr>
        @endif
    </table>
    <div class="qr">{!! $qr !!}</div>
    <div class="foot">Present this ticket (code or QR) at check-in.</div>
</div>
</body>
</html>
