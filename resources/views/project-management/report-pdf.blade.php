{{--
    A report as a document (§26).

    Deliberately plain: this goes in a board pack, gets printed, and is read on paper. No colour
    beyond a grey header row, because half of these are printed in black and white and a report
    that relies on colour to be legible stops being legible.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #111; }
        h1 { font-size: 13pt; margin: 0 0 2mm; }
        .meta { color: #666; font-size: 7.5pt; margin-bottom: 4mm; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.4pt solid #bbb; padding: 1.4mm 1.8mm; text-align: left; vertical-align: top; }
        th { background: #eee; font-weight: bold; }
        /* Repeat the header on every page — a fourteen-page table with headings only on page one
           is one nobody can read past the first page. */
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">{{ config('app.name') }} · {{ $printed }} · {{ count($rows) }} rows</div>

    <table>
        <thead>
            <tr>@foreach ($headers as $h)<th>{{ $h }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
