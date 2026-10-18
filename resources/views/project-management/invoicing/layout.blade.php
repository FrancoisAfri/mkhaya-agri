{{--
    The shared skeleton for every document in an account pack.

    Monochrome on purpose: these are printed, filed and photocopied, and a report that only reads
    in colour is a report that stops reading the moment it leaves the screen. DejaVu Sans because
    DomPDF needs an embedded font for anything beyond ASCII — a client name with an accent in it
    otherwise arrives as boxes.

    `thead { display: table-header-group }` repeats the header on every page of a long annexure,
    and `page-break-inside: avoid` keeps a claim line off two pages at once.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $header['invoice_number'] }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #000; }
        h1 { font-size: 13pt; margin: 0 0 2mm; letter-spacing: .5px; }
        h2 { font-size: 9.5pt; margin: 5mm 0 2mm; text-transform: uppercase; letter-spacing: .4px; }
        .muted { color: #444; }
        .right { text-align: right; }
        .center { text-align: center; }
        .rule { border-block-end: 1px solid #000; margin: 2mm 0 3mm; }

        table { inline-size: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #999; padding: 1.2mm 1.6mm; vertical-align: top; }
        th { background: #eee; text-align: start; font-weight: bold; }
        td.num, th.num { text-align: end; white-space: nowrap; }

        table.plain, table.plain th, table.plain td { border: none; padding: 0.6mm 0; }
        .totals td { border: none; padding: 1mm 0; }
        .totals tr.grand td { border-block-start: 1.2px solid #000; font-weight: bold; font-size: 10pt; }

        .sign { margin-block-start: 12mm; }
        .sign td { border: none; padding-block-start: 10mm; }
        .sign .line { border-block-start: 1px solid #000; padding-block-start: 1.5mm; }

        .stamp { font-size: 7.5pt; color: #555; margin-block-start: 6mm; }
        .draft { color: #b00; font-weight: bold; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
@foreach ($documents as $document)
    @php($header = $document['header'])
    @php($body = $document['body'])

    <h1>{{ $document['title'] }}</h1>

    <table class="plain">
        <tr>
            <td style="inline-size: 50%">
                <strong>{{ $header['consultant_name'] ?? config('app.name') }}</strong><br>
                @if ($header['consultant_address'])
                    <span class="muted">{!! nl2br(e($header['consultant_address'])) !!}</span><br>
                @endif
                @if ($header['consultant_vat_number'])
                    <span class="muted">VAT no.: {{ $header['consultant_vat_number'] }}</span><br>
                @endif
                @if ($header['consultant_reference'])
                    <span class="muted">Our ref.: {{ $header['consultant_reference'] }}</span>
                @endif
            </td>
            <td class="right">
                <strong>Invoice no.:</strong> {{ $header['invoice_number'] }}<br>
                <strong>Account no.:</strong> {{ $header['account_number'] }}<br>
                <strong>Date:</strong> {{ $header['invoice_date'] }}<br>
                @if ($header['period'])
                    <strong>For period:</strong> {{ $header['period'] }}<br>
                @endif
                @if ($header['status'] !== 'generated' && $header['status'] !== 'sent')
                    <span class="draft">{{ strtoupper($header['status']) }}</span>
                @endif
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="plain">
        <tr>
            <td style="inline-size: 50%">
                <strong>To:</strong> {{ $header['client_name'] ?? '—' }}<br>
                @if ($header['client_address'])
                    <span class="muted">{!! nl2br(e($header['client_address'])) !!}</span><br>
                @endif
                @if ($header['client_vat_number'])
                    <span class="muted">VAT no.: {{ $header['client_vat_number'] }}</span><br>
                @endif
                @if ($header['attention'])
                    <strong>Attention:</strong> {{ $header['attention'] }}
                @endif
            </td>
            <td class="right">
                <strong>Project:</strong> {{ $header['project'] }}<br>
                @if ($header['project_code'])
                    <span class="muted">{{ $header['project_code'] }}</span><br>
                @endif
                @if ($header['client_project_number'])
                    <strong>Client project no.:</strong> {{ $header['client_project_number'] }}<br>
                @endif
                @if ($header['file_number'])
                    <strong>File no.:</strong> {{ $header['file_number'] }}<br>
                @endif
                @if ($header['vendor_number'])
                    <strong>Vendor no.:</strong> {{ $header['vendor_number'] }}
                @endif
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    {{-- Annexures share one template, chosen by number; everything else has its own. The strip
         must be anchored to `annexure_`, or `form_1` becomes `form`. --}}
    @php($partial = str_starts_with($document['kind'], 'annexure_') ? 'annexure' : $document['kind'])
    @include('project-management.invoicing.' . $partial)

    <div class="stamp">
        {{ $header['account_type'] }} · printed {{ $printed }}
    </div>

    @if (! $loop->last)
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
