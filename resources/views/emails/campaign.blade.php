<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $campaign->subject }}</title>
<style>
  body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f4f5; color: #1a1a1a; }
  .wrapper { max-width: 640px; margin: 32px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  .header  { background: #7367f0; padding: 28px 40px; text-align: center; }
  .header h1 { margin: 0; color: #fff; font-size: 22px; font-weight: 600; }
  .body    { padding: 36px 40px; font-size: 15px; line-height: 1.7; }
  .footer  { background: #f4f4f5; padding: 20px 40px; text-align: center; font-size: 12px; color: #888; border-top: 1px solid #e5e5e5; }
  .footer a { color: #7367f0; text-decoration: none; }
  img.tracking-pixel { display: block; width: 1px; height: 1px; border: 0; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>{{ config('app.name') }}</h1>
  </div>
  <div class="body">
    {!! $campaign->body !!}
  </div>
  <div class="footer">
    <p>You are receiving this email because you are a member of {{ config('app.name') }}.</p>
    <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
  </div>
</div>
{{-- Open-tracking pixel --}}
<img src="{{ url('/track/email-open/' . $recipient->open_token) }}"
     alt="" width="1" height="1" class="tracking-pixel">
</body>
</html>
