<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduled content did not publish</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .wrapper { max-width: 640px; margin: 30px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.12); }
        .header { background: linear-gradient(135deg, #7a1c1c 0%, #b83232 100%); padding: 32px 36px; }
        .header h1 { color: #fff; margin: 0; font-size: 21px; }
        .header p { color: rgba(255,255,255,.85); margin: 6px 0 0; font-size: 13px; }
        .body { padding: 28px 36px; }
        .body p { color: #444; line-height: 1.6; margin: 0 0 14px; font-size: 14px; }
        .item { border: 1px solid #eee; border-left: 4px solid #b83232; border-radius: 6px; padding: 14px 16px; margin: 0 0 12px; }
        .item h2 { font-size: 15px; color: #222; margin: 0 0 8px; }
        .meta { font-size: 12px; color: #888; margin: 0 0 8px; }
        .error { font-size: 13px; color: #7a1c1c; background: #fdf3f3; border-radius: 4px; padding: 8px 10px; margin: 0; word-break: break-word; }
        .btn { display: inline-block; background: #005f56; color: #fff !important; text-decoration: none; border-radius: 4px; padding: 11px 22px; font-size: 14px; font-weight: 700; margin-top: 6px; }
        .footer { background: #f9f9f9; border-top: 1px solid #eee; padding: 18px 36px; font-size: 12px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>Scheduled content did not publish</h1>
            <p>{{ $companyName }} — Content Marketing calendar</p>
        </div>
        <div class="body">
            <p>
                {{ count($failures) }} planned {{ count($failures) === 1 ? 'topic' : 'topics' }}
                {{ count($failures) === 1 ? 'has' : 'have' }} used up all automatic retries and will not
                be attempted again. Nothing was posted for {{ count($failures) === 1 ? 'it' : 'them' }}.
            </p>

            @foreach ($failures as $f)
                <div class="item">
                    <h2>{{ $f['topic'] }}</h2>
                    <p class="meta">
                        {{ $f['website'] }} &nbsp;·&nbsp; planned for {{ $f['planned_for'] }}
                        &nbsp;·&nbsp; {{ $f['attempts'] }} {{ $f['attempts'] === 1 ? 'attempt' : 'attempts' }}
                    </p>
                    <p class="error">{{ $f['error'] ?: 'No error was recorded — the run was stopped before it could report one.' }}</p>
                </div>
            @endforeach

            <p>Open the calendar and use <strong>Generate now</strong> on the topic to try it again once the cause is cleared.</p>
            <p><a class="btn" href="{{ $calendarUrl }}">Open the content calendar</a></p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Mkhaya Agri. This is an automated message — please do not reply directly to this email.
        </div>
    </div>
</body>
</html>
