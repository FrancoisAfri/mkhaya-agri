<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; text-align: center; color: #111827; }
        .wrap { padding: 18px 14px; }
        .conf { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; }
        .name { font-size: 22px; font-weight: bold; margin: 14px 0 4px; }
        .org { font-size: 13px; color: #4b5563; }
        .ticket { display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 10px; background: #eef2ff; color: #4f46e5; font-size: 11px; }
        .qr { margin-top: 16px; }
        .code { margin-top: 8px; font-size: 10px; color: #9ca3af; letter-spacing: 1px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="conf">{{ $conference->title }}</div>
        <div class="name">{{ $registration->name }}</div>
        @if(!empty($registration->custom_fields['organization']) || $registration->ticket)
            <div class="org">{{ $registration->custom_fields['organization'] ?? '' }}</div>
        @endif
        @if($registration->ticket)
            <div class="ticket">{{ $registration->ticket->name }}</div>
        @endif
        <div class="qr">{!! $qr !!}</div>
        <div class="code">{{ $registration->ticket_code }}</div>
    </div>
</body>
</html>
