<div style="font-family: sans-serif; max-width: 560px; margin: auto; color: #1f2937;">
    <h2 style="color: #4f46e5;">{{ $title }}</h2>
    <p>Hi {{ $name }},</p>
    <p>You’re invited to <strong>{{ $title }}</strong>@if($startDate), taking place on <strong>{{ $startDate }}</strong>@endif.</p>
    <p>Please let us know if you’ll attend:</p>
    <p>
        <a href="{{ $acceptUrl }}" style="display:inline-block;padding:10px 18px;background:#16a34a;color:#fff;border-radius:6px;text-decoration:none;margin-right:8px;">Accept</a>
        <a href="{{ $declineUrl }}" style="display:inline-block;padding:10px 18px;background:#e5e7eb;color:#111;border-radius:6px;text-decoration:none;">Decline</a>
    </p>
    <p style="color:#9ca3af;font-size:12px;">If the buttons don’t work, paste this link into your browser:<br>{{ $acceptUrl }}</p>
</div>
