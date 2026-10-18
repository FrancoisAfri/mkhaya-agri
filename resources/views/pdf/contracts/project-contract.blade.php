{{--
    The contract as it goes out for signature.

    Rendered once, when the contract is sent, and it is those exact bytes everybody signs. Rendering
    afresh for each signer would mean two people putting their name to two different documents if a
    template, a logo or a font moved in between.

    Deliberately plain: dompdf supports a narrow slice of CSS, and an agreement that reflows
    differently on someone else's machine is not a document you can rely on. No flex, no grid, no
    web fonts.

    The signature block is left empty on purpose. It is filled by burning the signatures into the
    page when the last party signs — see ProjectContractService::storeSignedDocument().
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $contract->contract_number }}</title>
    <style>
        @page { margin: 22mm 18mm 24mm 18mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5pt;
            line-height: 1.55;
            color: #1a1a1a;
        }

        .masthead { border-bottom: 1.5pt solid #333; padding-bottom: 8pt; margin-bottom: 16pt; }
        .masthead .number { font-size: 15pt; font-weight: bold; }
        .masthead .meta { font-size: 9pt; color: #555; margin-top: 3pt; }

        h1 { font-size: 15pt; margin: 14pt 0 8pt; }
        h2 { font-size: 12pt; margin: 12pt 0 6pt; }
        h3 { font-size: 11pt; margin: 10pt 0 5pt; }
        p  { margin: 0 0 7pt; }
        ul, ol { margin: 0 0 7pt 14pt; }

        blockquote {
            margin: 0 0 8pt; padding: 6pt 8pt;
            border-left: 2pt solid #bbb; background: #f6f6f6;
            font-size: 9.5pt;
        }

        table { width: 100%; border-collapse: collapse; margin: 0 0 8pt; }
        th, td { border: 0.5pt solid #999; padding: 4pt 6pt; text-align: left; vertical-align: top; }
        th { background: #f0f0f0; }

        .terms { width: 100%; margin-bottom: 14pt; font-size: 9.5pt; }
        .terms td { border: 0; padding: 2pt 0; }
        .terms .label { color: #555; width: 32%; }

        /* Kept with the block that follows so a signature never lands alone on a last page. */
        .sign-block { page-break-inside: avoid; margin-top: 18pt; }
        .sign-grid { width: 100%; border-collapse: collapse; }
        .sign-grid td { border: 0; padding: 6pt 10pt 0 0; width: 50%; vertical-align: top; }
        .sign-rule { border-bottom: 0.75pt solid #333; height: 34pt; }
        .sign-label { font-size: 8.5pt; color: #555; padding-top: 3pt; }

        .foot { font-size: 8pt; color: #777; margin-top: 14pt; border-top: 0.5pt solid #ccc; padding-top: 5pt; }
    </style>
</head>
<body>

<div class="masthead">
    <div class="number">{{ $contract->contract_number }}</div>
    <div class="meta">
        {{ $contract->title }}<br>
        {{ trim(($project->code ?? '') . ' — ' . ($project->name ?? '')) }}
        · {{ __('project_management.contracts.version_short', ['no' => $contract->current_version]) }}
    </div>
</div>

<table class="terms">
    @if ($contractorName)
        <tr>
            <td class="label">{{ __('project_management.contractors.col_contractor') }}</td>
            <td>{{ $contractorName }}</td>
        </tr>
    @endif
    @if ($showValue)
        <tr>
            <td class="label">{{ __('project_management.contractors.contract_value') }}</td>
            <td>{{ $contract->currency }} {{ number_format((float) $contract->contract_value, 2) }}</td>
        </tr>
    @endif
    @if ($contract->start_date)
        <tr>
            <td class="label">{{ __('project_management.contractors.start_date') }}</td>
            <td>{{ $contract->start_date->toDateString() }}</td>
        </tr>
    @endif
    @if ($contract->end_date)
        <tr>
            <td class="label">{{ __('project_management.contractors.end_date') }}</td>
            <td>{{ $contract->end_date->toDateString() }}</td>
        </tr>
    @endif
</table>

{{-- Sanitised before it ever reaches here; see Support\ContractBody. --}}
{!! $body !!}

<div class="sign-block">
    <h2>{{ __('project_management.contracts.signatures') }}</h2>
    <table class="sign-grid">
        <tr>
            @foreach ($signatories as $s)
                <td>
                    <div class="sign-rule"></div>
                    <div class="sign-label">
                        {{ $s['name'] }}<br>
                        {{-- Spelled out: a key built from a variable is one no search and no
                             translator ever finds, and this one prints on a legal document. --}}
                        {{ $s['party'] === 'contractor'
                            ? __('project_management.contracts.party_contractor')
                            : __('project_management.contracts.party_company') }}
                    </div>
                </td>
            @endforeach
        </tr>
    </table>
</div>

<div class="foot">
    {{ __('project_management.contracts.pdf_footer', [
        'number'  => $contract->contract_number,
        'version' => $contract->current_version,
        'at'      => $generatedAt,
    ]) }}
</div>

</body>
</html>
