<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Redirecting to payment…</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding-top: 80px; color: #374151; }
        .spinner { margin: 20px auto; width: 36px; height: 36px; border: 4px solid #e5e7eb; border-top-color: #4f46e5; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        button { margin-top: 16px; padding: 10px 18px; background: #4f46e5; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
    </style>
</head>
<body>
    <h3>Redirecting you to {{ ucfirst($provider) }} to complete payment…</h3>
    <div class="spinner"></div>
    <form id="gateway" action="{{ $url }}" method="{{ $method }}">
        @foreach($fields as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <noscript><button type="submit">Continue to payment</button></noscript>
    </form>
    <script>document.getElementById('gateway').submit();</script>
</body>
</html>
