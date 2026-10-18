<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#1d2530;margin:28px}h1{font-size:18px;margin:0 0 4px}.meta{color:#6a7480;font-size:10px;margin-bottom:18px}.body{white-space:pre-wrap;line-height:1.5}</style>
</head><body>
<h1>{{ $title }}</h1>
<div class="meta">{{ $instance->reference }} · {{ $instance->title }} · generated {{ now()->setTimezone('Africa/Johannesburg')->format('j M Y H:i') }}</div>
<div class="body">{{ $body }}</div>
</body></html>
