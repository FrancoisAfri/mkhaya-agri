<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Offer Letter</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11pt;
            color: #1a1a1a;
            line-height: 1.6;
            background: #ffffff;
        }

        .page {
            padding: 40px 50px;
        }

        /* ── Header ── */
        .header {
            text-align: center;
            margin-bottom: 32px;
            border-bottom: 2px solid #1a3c6e;
            padding-bottom: 18px;
        }

        .company-name {
            font-size: 18pt;
            font-weight: bold;
            color: #1a3c6e;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .doc-title {
            font-size: 15pt;
            font-weight: bold;
            color: #2c2c2c;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 6px;
        }

        .doc-date {
            font-size: 10pt;
            color: #555555;
        }

        /* ── Salutation & body ── */
        .salutation {
            margin-bottom: 14px;
            font-size: 11pt;
        }

        .body-para {
            margin-bottom: 18px;
            font-size: 11pt;
            text-align: justify;
        }

        /* ── Section headings ── */
        .section-title {
            font-size: 11pt;
            font-weight: bold;
            color: #1a3c6e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 24px;
            margin-bottom: 10px;
            border-left: 3px solid #1a3c6e;
            padding-left: 8px;
        }

        /* ── Key terms table ── */
        .terms-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        .terms-table tr:nth-child(odd) {
            background-color: #f5f7fb;
        }

        .terms-table td {
            padding: 7px 10px;
            font-size: 10.5pt;
            border-bottom: 1px solid #e0e4ed;
        }

        .terms-table td.label {
            font-weight: bold;
            color: #333333;
            width: 38%;
        }

        .terms-table td.value {
            color: #1a1a1a;
        }

        /* ── Conditions list ── */
        .conditions-list {
            margin-left: 20px;
            margin-bottom: 18px;
        }

        .conditions-list li {
            margin-bottom: 5px;
            font-size: 11pt;
        }

        /* ── Acceptance section ── */
        .acceptance-box {
            background-color: #f0f4fb;
            border: 1px solid #c5d2e8;
            border-radius: 4px;
            padding: 14px 16px;
            margin-bottom: 24px;
            font-size: 10.5pt;
        }

        /* ── Signature section ── */
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 36px;
            margin-bottom: 24px;
        }

        .signature-table td {
            width: 50%;
            vertical-align: top;
            padding: 0 10px;
        }

        .signature-table td:first-child {
            padding-left: 0;
        }

        .signature-table td:last-child {
            padding-right: 0;
        }

        .sig-line {
            border-bottom: 1px solid #333333;
            margin-bottom: 6px;
            height: 40px;
        }

        .sig-label {
            font-size: 10pt;
            font-weight: bold;
            color: #333333;
        }

        .sig-sublabel {
            font-size: 9pt;
            color: #666666;
            margin-top: 2px;
        }

        /* ── Footer ── */
        .footer {
            border-top: 1px solid #d0d5df;
            margin-top: 40px;
            padding-top: 10px;
            text-align: center;
            font-size: 8.5pt;
            color: #888888;
        }
    </style>
</head>
<body>
<div class="page">

    <!-- ── Header ───────────────────────────────────────────────────────────── -->
    <div class="header">
        <div class="company-name">MkhayaHub</div>
        <div class="doc-title">Letter of Offer</div>
        <div class="doc-date">{{ now()->format('d F Y') }}</div>
    </div>

    <!-- ── Salutation ────────────────────────────────────────────────────────── -->
    <div class="salutation">
        Dear {{ $offer->candidate?->full_name ?? 'Candidate' }},
    </div>

    <!-- ── Opening paragraph ─────────────────────────────────────────────────── -->
    <div class="body-para">
        We are pleased to offer you the position of <strong>{{ $offer->position_title }}</strong> at
        <strong>MkhayaHub</strong>, subject to the terms and conditions set out in this letter.
    </div>

    <!-- ── Key Terms ─────────────────────────────────────────────────────────── -->
    <div class="section-title">Key Terms</div>

    <table class="terms-table">
        <tr>
            <td class="label">Position</td>
            <td class="value">{{ $offer->position_title }}</td>
        </tr>
        @if($offer->department)
        <tr>
            <td class="label">Department</td>
            <td class="value">{{ $offer->department }}</td>
        </tr>
        @endif
        <tr>
            <td class="label">Start Date</td>
            <td class="value">{{ $offer->start_date ? $offer->start_date->format('d F Y') : '—' }}</td>
        </tr>
        <tr>
            <td class="label">Salary</td>
            <td class="value">
                {{ $offer->salary_currency ?? 'ZAR' }}
                {{ $offer->salary_amount ? number_format((float) $offer->salary_amount, 2) : '—' }}
                per month
            </td>
        </tr>
        @if($offer->contract_type)
        <tr>
            <td class="label">Employment Type</td>
            <td class="value">{{ ucwords(str_replace('_', ' ', $offer->contract_type)) }}</td>
        </tr>
        @endif
        <tr>
            <td class="label">Offer Expiry Date</td>
            <td class="value">{{ $offer->expires_at ? $offer->expires_at->format('d F Y') : '—' }}</td>
        </tr>
    </table>

    <!-- ── Conditions ────────────────────────────────────────────────────────── -->
    <div class="section-title">Conditions</div>

    <div class="body-para">This offer is subject to:</div>
    <ul class="conditions-list">
        <li>Satisfactory reference checks</li>
        <li>Satisfactory background screening</li>
        <li>Proof of qualifications</li>
        <li>Right to work verification</li>
    </ul>

    <!-- ── Acceptance ────────────────────────────────────────────────────────── -->
    <div class="section-title">Acceptance</div>

    <div class="acceptance-box">
        To accept this offer, please sign below or confirm via the online portal before
        <strong>{{ $offer->expires_at ? $offer->expires_at->format('d F Y') : 'the specified expiry date' }}</strong>.
        Failure to respond by this date will result in the offer lapsing automatically.
    </div>

    <!-- ── Signatures ────────────────────────────────────────────────────────── -->
    <table class="signature-table">
        <tr>
            <td>
                <div class="sig-line"></div>
                <div class="sig-label">Authorised Signatory</div>
                <div class="sig-sublabel">MkhayaHub HR</div>
            </td>
            <td>
                <div class="sig-line"></div>
                <div class="sig-label">Candidate Acceptance</div>
                <div class="sig-sublabel">Date</div>
            </td>
        </tr>
    </table>

    <!-- ── Footer ────────────────────────────────────────────────────────────── -->
    <div class="footer">
        Confidential &mdash; {{ $offer->uuid ?? $offer->id }}
    </div>

</div>
</body>
</html>
