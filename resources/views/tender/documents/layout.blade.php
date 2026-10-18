@php
    $color = $branding->primary_color ?: '#1867C0';
    $useImg = $branding->use_letterhead_image && $branding->letterhead_image;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 150px 45px 90px 45px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; line-height: 1.5; }
        header { position: fixed; top: -120px; left: 0; right: 0; height: 110px; }
        footer { position: fixed; bottom: -70px; left: 0; right: 0; height: 60px; font-size: 8px; color: #888; text-align: center; border-top: 1px solid #ddd; padding-top: 6px; }
        .lh-table { width: 100%; border-collapse: collapse; }
        .lh-logo { height: 62px; max-width: 240px; }
        .company-name { font-size: 17px; font-weight: bold; color: {{ $color }}; margin: 0; }
        .company-meta { font-size: 9px; color: #555; margin-top: 2px; }
        .accent-bar { height: 4px; background: {{ $color }}; margin-top: 8px; }
        h1.doc-title { color: {{ $color }}; font-size: 20px; margin: 0 0 4px 0; }
        .doc-sub { color: #666; font-size: 10px; margin-bottom: 14px; }
        .meta-box { background: #f5f7fa; border-left: 3px solid {{ $color }}; padding: 8px 12px; margin-bottom: 16px; font-size: 10px; }
        .meta-box b { color: #333; }
        h2 { color: {{ $color }}; font-size: 14px; margin: 18px 0 4px; border-bottom: 1px solid #e0e0e0; padding-bottom: 3px; }
        h3 { font-size: 12px; margin: 12px 0 3px; }
        table.data { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.data th { background: {{ $color }}; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
        table.data td { border-bottom: 1px solid #eee; padding: 6px 8px; }
        table.data tr:nth-child(even) td { background: #fafafa; }
        .text-right { text-align: right; }
        .totals td { padding: 4px 8px; }
        .totals .grand { font-size: 13px; font-weight: bold; color: {{ $color }}; border-top: 2px solid {{ $color }}; }
        .sig-grid { width: 100%; margin-top: 40px; }
        .sig-cell { width: 45%; vertical-align: bottom; padding-right: 30px; }
        .sig-line { border-top: 1px solid #333; margin-top: 40px; padding-top: 4px; font-size: 9px; }
        .sig-img { height: 55px; }
        .muted { color: #888; }
    </style>
</head>
<body>
    <header>
        @if ($useImg)
            <img src="{{ $branding->letterhead_image }}" style="width:100%; max-height:110px;">
        @else
            <table class="lh-table">
                <tr>
                    <td style="width:60%;">
                        @if ($branding->logo)
                            <img src="{{ $branding->logo }}" class="lh-logo">
                        @else
                            <p class="company-name">{{ $branding->company_name ?: config('app.name') }}</p>
                        @endif
                    </td>
                    <td style="width:40%; text-align:right; vertical-align:top;">
                        @if ($branding->logo)<p class="company-name">{{ $branding->company_name }}</p>@endif
                        <p class="company-meta">
                            @if ($branding->address){{ $branding->address }}<br>@endif
                            @if ($branding->phone)Tel: {{ $branding->phone }} @endif
                            @if ($branding->email) · {{ $branding->email }}@endif
                            @if ($branding->website)<br>{{ $branding->website }}@endif
                        </p>
                    </td>
                </tr>
            </table>
        @endif
        <div class="accent-bar"></div>
    </header>

    <footer>
        @php
            $initials = collect($signatures ?? [])->map(function ($s) {
                $parts = preg_split('/\s+/', trim($s['name'] ?? '')) ?: [];
                return collect($parts)->map(fn ($p) => strtoupper(substr($p, 0, 1)))->implode('');
            })->filter()->implode(' / ');
        @endphp
        @if ($initials !== '')
            <span style="float:right;">Initialled: <b>{{ $initials }}</b></span>
        @endif
        {{ $branding->footer_text ?: trim(($branding->company_name ? $branding->company_name.' · ' : '').collect([
            $branding->registration_number ? 'Reg: '.$branding->registration_number : null,
            $branding->vat_number ? 'VAT: '.$branding->vat_number : null,
            $branding->bbbee_level ? 'B-BBEE: '.$branding->bbbee_level : null,
        ])->filter()->implode(' · '), ' ·') }}
    </footer>

    <main>
        <h1 class="doc-title">{{ $title }}</h1>
        @if (!empty($subtitle))<p class="doc-sub">{{ $subtitle }}</p>@endif

        @if (!empty($meta))
            <div class="meta-box">
                @foreach ($meta as $k => $v)
                    @if ($v !== null && $v !== '')<b>{{ $k }}:</b> {{ $v }}&nbsp;&nbsp;&nbsp;@endif
                @endforeach
            </div>
        @endif

        {!! $bodyHtml !!}

        @if (!empty($signatures))
            <table class="sig-grid">
                <tr>
                    @foreach ($signatures as $sig)
                        <td class="sig-cell">
                            @if (!empty($sig['image']))<img src="{{ $sig['image'] }}" class="sig-img"><br>@endif
                            <div class="sig-line">
                                {{ $sig['name'] ?? '' }}<br>
                                <span class="muted">{{ $sig['role'] ?? '' }}@if(!empty($sig['date'])) · {{ $sig['date'] }}@endif</span>
                            </div>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    </main>
</body>
</html>
