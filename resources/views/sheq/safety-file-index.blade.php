{{--
    The index inside a safety file bundle.

    It is the thing that makes a zip of certificates into a file. Three jobs, each of them
    something a folder of documents cannot do on its own:

      1. State the sections with nothing in them. A bundle of what exists is a selective bundle,
         and the gaps are what an auditor came to find.
      2. Distinguish a document that was recorded from one whose certificate was actually
         supplied. In a zip the first simply would not appear, and its absence would read as
         "never supplied".
      3. Say when it was made. A safety file is a snapshot of expiry dates; one printed in March
         says nothing true in September.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Safety file — {{ $contractor->name }}</title>
    <style>
        @page { margin: 18mm 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1a1a1a; }
        h1 { font-size: 16pt; margin: 0 0 2mm; }
        h2 { font-size: 11pt; margin: 6mm 0 2mm; border-bottom: 0.4mm solid #999; padding-bottom: 1mm; }
        .muted { color: #555; }
        .small { font-size: 8.5pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 1.4mm 2mm; vertical-align: top; }
        thead th { border-bottom: 0.3mm solid #666; font-size: 8.5pt; text-transform: uppercase; }
        tbody tr { border-bottom: 0.2mm solid #ddd; }
        .state { font-weight: bold; }
        .satisfied { color: #1b5e20; }
        .missing { color: #8a6100; }
        .expired { color: #8a1c1c; }
        .box { border: 0.3mm solid #999; padding: 3mm; margin: 0 0 4mm; }
        .gap { background: #fdf6e3; }
        .footer { margin-top: 6mm; border-top: 0.3mm solid #999; padding-top: 2mm; }
    </style>
</head>
<body>

<h1>Safety file</h1>
<p class="muted" style="margin:0 0 4mm">
    {{ $contractor->name }}@if($contractor->trade) · {{ $contractor->trade }}@endif
    @if($contractor->registration_number) · {{ $contractor->registration_number }}@endif
    <br>
    Held by {{ $company }} · requirements from the {{ $profileName }} profile
</p>

<div class="box">
    <table>
        <tr>
            <td><strong>Sections demanded</strong><br>{{ $file['summary']['required'] }}</td>
            <td><strong>Satisfied</strong><br>{{ $file['summary']['satisfied'] }}</td>
            <td><strong>Complete</strong><br>
                @if($file['summary']['percent'] === null)
                    Nothing demanded yet
                @else
                    {{ $file['summary']['complete'] ? 'Yes' : 'No' }}
                    ({{ $file['summary']['percent'] }}%)
                @endif
            </td>
            <td><strong>Documents in this bundle</strong><br>{{ $documents }}</td>
        </tr>
    </table>
</div>

{{-- The gaps, before the contents. This is what the bundle is read for. --}}
@if(count($file['summary']['missing']) > 0)
    <div class="box gap">
        <strong>{{ count($file['summary']['missing']) }} demanded section(s) are not satisfied</strong>
        <table class="small" style="margin-top:2mm">
            @foreach($file['summary']['missing'] as $gap)
                <tr>
                    <td style="width:60%">{{ $gap['name'] }}</td>
                    <td>
                        @if($gap['state'] === 'EXPIRED')
                            Everything filed in it has lapsed
                        @else
                            Nothing filed
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

<h2>Contents</h2>
<table>
    <thead>
        <tr>
            <th style="width:6%">#</th>
            <th style="width:30%">Section</th>
            <th style="width:16%">State</th>
            <th>In the bundle</th>
        </tr>
    </thead>
    <tbody>
    @foreach($file['sections'] as $index => $section)
        @php
            $class = ['SATISFIED' => 'satisfied', 'EXPIRED' => 'expired'][$section['state']] ?? 'missing';
            $label = ['SATISFIED' => 'Satisfied', 'EXPIRED' => 'Lapsed'][$section['state']] ?? 'Nothing filed';
        @endphp
        <tr>
            <td>{{ sprintf('%02d', $index + 1) }}</td>
            <td>
                {{ $section['name'] }}
                @if($section['is_required'])<br><span class="small muted">Demanded</span>@endif
            </td>
            <td class="state {{ $class }}">{{ $label }}</td>
            <td class="small">
                @if($section['kind'] === 'RECORD')
                    {{-- Counted from the host's own records, so there is nothing to put in a zip.
                         Said plainly, or the empty folder reads as missing evidence. --}}
                    {{ $section['count'] }} record(s) held in the host's system
                    @if($section['is_history'] ?? false)
                        <span class="muted">— history, not evidence</span>
                    @else
                        <span class="muted">— not filed as documents</span>
                    @endif
                @elseif(count($section['items']) === 0)
                    <span class="muted">—</span>
                @else
                    @foreach($section['items'] as $item)
                        {{ $item['title'] ?: $item['document_type'] }}
                        @if($item['expires_on'])
                            @if($item['expired'])
                                <span class="expired">(expired {{ $item['expires_on'] }})</span>
                            @else
                                (to {{ $item['expires_on'] }})
                            @endif
                        @endif
                        @if(! isset($attachments[$item['uuid']]))
                            <span class="missing">— recorded, no file attached</span>
                        @endif
                        <br>
                    @endforeach
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

@if(count($skipped) > 0)
    {{-- Never silently. A bundle missing documents without saying so looks complete, which is
         worse than no bundle at all. --}}
    <h2>Not in this bundle</h2>
    <table class="small">
        @foreach($skipped as $note)
            <tr><td>{{ $note }}</td></tr>
        @endforeach
    </table>
@endif

<div class="footer small muted">
    Generated {{ $generatedAt->format('j F Y \a\t H:i') }} from {{ $company }}'s records.
    A safety file is a snapshot of expiry dates: this bundle describes the position on the day it
    was made and says nothing about any date after it. Uncontrolled once printed or forwarded.
</div>

</body>
</html>
