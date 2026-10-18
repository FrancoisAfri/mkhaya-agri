<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Redirecting to secure payment…</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; background: #f6f7fb; color: #333; }
        .card { text-align: center; }
        .spinner { width: 38px; height: 38px; border: 3px solid #ddd; border-top-color: #1867C0; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 16px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        button { margin-top: 14px; padding: 10px 18px; border: 0; border-radius: 8px; background: #1867C0; color: #fff; font-size: 15px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner"></div>
        <p>Redirecting you to the secure payment page…</p>
        <form id="pf" action="{{ $redirect['url'] }}" method="{{ $redirect['method'] }}">
            @foreach (($redirect['fields'] ?? []) as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <button type="submit">Continue to payment</button>
        </form>
    </div>
    <script>document.getElementById('pf').submit();</script>
</body>
</html>
