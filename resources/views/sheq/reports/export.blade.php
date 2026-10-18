{{--
    A SHEQ report as a PDF (CMD-8).

    The provenance block is the point of this template, not the table: CMD-8 asks every export to
    carry its filters, its period, when it was generated and who by, and a PDF is the format most
    likely to be printed, photographed and forwarded without any of that context travelling with
    it. So all four sit above the data in type large enough to survive a photocopy.

    `data_as_at` is separate from `generated_at` and the distinction is the one that matters: a
    report generated this morning from a projection computed last Tuesday is a stale report with a
    fresh timestamp, which is the most misleading document this module can produce.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $provenance['title'] }}</title>
    <style>
        @page { margin: 14mm 10mm; }
        body { font: 9pt/1.4 DejaVu Sans, sans-serif; color: #1a1a1a; }
        h1 { font-size: 15pt; margin: 0 0 2mm; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
        .meta td { padding: 1mm 2mm 1mm 0; vertical-align: top; font-size: 8.5pt; }
        .meta td.label { color: #5f5f5f; white-space: nowrap; width: 32mm; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td {
            border: 0.3pt solid #c8c8c8; padding: 1.2mm 1.6mm; text-align: left;
            font-size: 8pt; vertical-align: top;
        }
        table.data th { background: #f0f0f0; font-weight: bold; }
        table.data tr:nth-child(even) td { background: #fafafa; }
        .note { margin-top: 4mm; padding: 2mm; border: 0.3pt solid #b71c1c; color: #b71c1c;
                font-size: 8.5pt; }
        .empty { margin-top: 6mm; color: #5f5f5f; font-style: italic; }
    </style>
</head>
<body>
    <h1>{{ $provenance['title'] }}</h1>

    <table class="meta">
        <tr>
            <td class="label">Period</td>
            <td><strong>{{ $provenance['period'] }}</strong></td>
            <td class="label">Generated</td>
            <td>{{ $provenance['generated_at'] }} {{ $provenance['timezone'] }}</td>
        </tr>
        <tr>
            <td class="label">Report owner</td>
            <td colspan="3">{{ $provenance['owner'] }}</td>
        </tr>
        @if (! empty($provenance['data_as_at']))
            <tr>
                <td class="label">Figures computed</td>
                <td colspan="3">{{ $provenance['data_as_at'] }}</td>
            </tr>
        @endif
        @foreach ($provenance['filters'] as $label => $value)
            <tr>
                <td class="label">{{ $label }}</td>
                <td colspan="3">{{ $value }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="label">Rows</td>
            <td colspan="3">{{ number_format($total) }}</td>
        </tr>
    </table>

    @if ($truncated)
        {{-- Said on the document, because somebody will total the visible rows. --}}
        <div class="note">
            This PDF shows the first {{ number_format($limit) }} of {{ number_format($total) }}
            rows. The totals below are NOT the totals for the period — export the same report as
            CSV or Excel for the complete set.
        </div>
    @endif

    @if ($total === 0)
        <p class="empty">
            No records matched these filters for this period. That is a result, not an error —
            check the period and the filters above before reading it as "nothing happened".
        </p>
    @else
        <table class="data">
            <thead>
                <tr>
                    @foreach ($headings as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            @php $value = $row[$column] ?? null; @endphp
                            <td>{{ is_bool($value) ? ($value ? 'yes' : 'no') : $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
