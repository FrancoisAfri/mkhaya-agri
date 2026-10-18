<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: 'DejaVu Serif', Georgia, serif; color: #1f2937; }
        .cert { width: 100%; height: 540px; box-sizing: border-box; padding: 48px 64px;
                border: 14px solid {{ $accent }}; }
        .inner { border: 2px solid {{ $accent }}; height: 100%; box-sizing: border-box;
                 padding: 32px 40px; text-align: center; }
        .eyebrow { letter-spacing: 6px; text-transform: uppercase; font-size: 12px; color: #6b7280; }
        h1 { font-size: 34px; margin: 8px 0 4px; color: {{ $accent }}; }
        .subtitle { font-size: 13px; color: #6b7280; margin-bottom: 26px; }
        .name { font-size: 30px; margin: 18px 0 6px; }
        .rule { width: 280px; border: 0; border-top: 1px solid #d1d5db; margin: 6px auto 18px; }
        .body { font-size: 14px; line-height: 1.6; max-width: 560px; margin: 0 auto; }
        .meta { margin-top: 34px; font-size: 11px; color: #6b7280; }
        .number { margin-top: 6px; font-size: 10px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="cert">
        <div class="inner">
            <div class="eyebrow">Certificate of Attendance</div>
            <h1>{{ $conference['title'] }}</h1>
            <div class="subtitle">@if($conference['dates']){{ $conference['dates'] }} · @endif{{ $conference['format'] }}</div>

            <div style="font-size:13px;color:#6b7280">This is to certify that</div>
            <div class="name">{{ $attendee['name'] }}</div>
            <hr class="rule">
            <div class="body">
                attended and participated in {{ $conference['title'] }}.
            </div>

            <div class="meta">Issued on {{ $issued_on }}</div>
            <div class="number">Certificate No. {{ $certificate_number }}</div>
        </div>
    </div>
</body>
</html>
