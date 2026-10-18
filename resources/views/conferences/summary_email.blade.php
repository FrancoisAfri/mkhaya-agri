<div style="font-family:sans-serif;max-width:600px;margin:auto;color:#1f2937">
    <h2 style="color:#4f46e5">{{ $title }}</h2>
    <p>Hi {{ $name }}, here's the recap of the session.</p>
    <p style="white-space:pre-wrap">{{ $summary }}</p>
    @if(count($takeaways))
        <h3>Key takeaways</h3>
        <ul>@foreach($takeaways as $t)<li>{{ $t }}</li>@endforeach</ul>
    @endif
    @if(count($actions))
        <h3>Action items</h3>
        <ul>@foreach($actions as $a)<li>{{ $a }}</li>@endforeach</ul>
    @endif
</div>
