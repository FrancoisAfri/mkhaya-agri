@php($L = fn (string $k, array $p = []) => \App\Modules\Agri\Support\UiLabels::get('agri.trace_public.'.$k, $p))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $s['product'] }} — {{ $L('title') }}</title>
    <style>
        :root { --ink: #1f2a1f; --muted: #5f6b5f; --line: #e3e8e0; --bg: #f6f8f4; --card: #fff; --accent: #2e7d32; --warn: #b71c1c; }
        @media (prefers-color-scheme: dark) { :root { --ink: #e8eee6; --muted: #a7b3a5; --line: #2c352b; --bg: #121611; --card: #1a2019; --accent: #81c784; --warn: #ef9a9a; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); }
        main { max-width: 560px; margin: 0 auto; padding: 24px 16px 40px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin-bottom: 14px; }
        h1 { font-size: 1.6rem; margin: 0 0 4px; }
        .sub { color: var(--muted); margin: 0; }
        dl { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: 10px 16px; }
        dt { color: var(--muted); }
        dd { margin: 0; font-weight: 600; }
        ul { margin: 0; padding-left: 18px; }
        .badge { display: inline-block; background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent); border-radius: 999px; padding: 2px 10px; font-size: .85rem; margin: 2px 4px 2px 0; font-weight: 600; }
        .warn { border-color: var(--warn); color: var(--warn); font-weight: 600; }
        footer { color: var(--muted); font-size: .8rem; text-align: center; margin-top: 18px; }
        .brand { display: flex; align-items: center; gap: 10px; margin: 0 0 14px; font-weight: 600; }
        .brand img { height: 36px; width: auto; max-width: 160px; object-fit: contain; }
    </style>
</head>
<body>
<main>
    @php($brand = $s['brand'] ?? ['white_label' => false, 'name' => \App\Modules\Tenancy\Services\CompanyBranding::PRODUCT, 'logo_data_uri' => null])
    @if ($brand['white_label'])
        <div class="brand">@if ($brand['logo_data_uri'])<img src="{{ $brand['logo_data_uri'] }}" alt="">@endif<span>{{ $brand['name'] }}</span></div>
    @endif
    @if ($s['withdrawn'])
        <div class="card warn">{{ $L('withdrawn') }}</div>
    @endif
    <div class="card">
        <p class="sub">{{ $L('title') }}</p>
        <h1>{{ $s['product'] }}</h1>
        @if (! empty($s['farm']) || ! empty($s['region']))
            <p class="sub">{{ $L('grown_by', ['where' => collect([$s['farm'] ?? null, $s['region'] ?? null])->filter()->implode(', ')]) }}</p>
        @endif
    </div>
    <div class="card">
        <dl>
            @if (! empty($s['crop']))<dt>{{ $L('crop') }}</dt><dd>{{ $s['crop'] }}@if (! empty($s['varieties'])) · {{ implode(', ', $s['varieties']) }}@endif</dd>@endif
            @if (! empty($s['grade']))<dt>{{ $L('grade') }}</dt><dd>{{ $s['grade'] }}</dd>@endif
            @if (! empty($s['fields']))<dt>{{ $L('field') }}</dt><dd>{{ implode(', ', $s['fields']) }}</dd>@endif
            @if (! empty($s['harvested']))<dt>{{ $L('harvested') }}</dt><dd>{{ $s['harvested']['from'] === $s['harvested']['to'] ? $s['harvested']['from'] : $L('date_range', ['from' => $s['harvested']['from'], 'to' => $s['harvested']['to']]) }}</dd>@endif
            @if (! empty($s['packed_on']))<dt>{{ $L('packed') }}</dt><dd>{{ $s['packed_on'] }}</dd>@endif
            @if (! empty($s['best_before']))<dt>{{ $L('best_before') }}</dt><dd>{{ $s['best_before'] }}</dd>@endif
            <dt>{{ $L('lot') }}</dt><dd>{{ $s['lot'] }}</dd>
        </dl>
        @if ($s['from_growers'])<p class="sub" style="margin-top: 12px">{{ $L('from_growers') }}</p>@endif
    </div>
    @if (! empty($s['certifications']))
        <div class="card">
            <p class="sub" style="margin-bottom: 6px">{{ $L('certifications') }}</p>
            @foreach ($s['certifications'] as $c)<span class="badge">{{ $c }}</span>@endforeach
        </div>
    @endif
    <footer>{{ $L('footer', ['brand' => $brand['name']]) }}</footer>
</main>
</body>
</html>
