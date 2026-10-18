{{--
    The Implementing Agent's payment certificate — the workbook's `AI` sheet.

    Read top to bottom the way the funder's people work through it: who certifies, what the KPIs
    say, the fee claim, the construction claim, what is retained, what is payable, and finally the
    schedule of everything paid so far.

    The certification blocks are printed with ruled signature lines rather than captured on screen.
    Six different people at three organisations sign this, most of them nowhere near the Hub, so
    the honest thing is a page they can sign — not a checkbox implying they already did.
--}}

<table class="plain" style="margin-block-end: 3mm">
    <tr>
        <td style="inline-size: 50%">
            <strong>Project funder:</strong> {{ $header['project_funder'] ?? '—' }}<br>
            <strong>Tender no.:</strong> {{ $header['tender_number'] ?? '—' }}
        </td>
        <td class="right">
            <strong>This certificate no.:</strong> {{ $header['certificate_number'] ?? '—' }}<br>
            <strong>Certificate of completion:</strong> {{ $body['completion_certificate'] ?? 'N/A' }}
        </td>
    </tr>
</table>

<table>
    <tr>
        <th>Total approved value</th>
        <td class="num">{{ $body['total_approved_value'] }}</td>
        <th>Total construction cost</th>
        <td class="num">{{ $body['total_construction_cost'] }}</td>
    </tr>
</table>

@if ($body['kpis'])
    <h2>KPIs</h2>
    <table>
        <thead>
            <tr><th>Question</th><th style="inline-size: 20mm">Yes or No</th></tr>
        </thead>
        <tbody>
            @foreach ($body['kpis'] as $kpi)
                <tr>
                    <td>{{ $kpi['question'] }}</td>
                    <td class="center">{{ $kpi['answer'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2>Particulars of management fee claim</h2>

@if ($body['management_fee']['exceeds_maximum'])
    <p class="draft">{{ $body['management_fee']['warning'] }}</p>
@endif

<table class="totals">
    <tr>
        <td>Max. management fee (VAT incl.)</td>
        <td class="num">{{ $body['management_fee']['max_incl_vat'] }}</td>
    </tr>
    <tr>
        <td>Total management fee claimed</td>
        <td class="num">{{ $body['management_fee']['total_claimed'] }}</td>
    </tr>
    <tr>
        <td>Management fee not claimed yet</td>
        <td class="num">{{ $body['management_fee']['not_claimed_yet'] }}</td>
    </tr>
    <tr>
        <td>Less: management fee claimed previously</td>
        <td class="num">{{ $body['management_fee']['claimed_previously'] }}</td>
    </tr>
    <tr class="grand">
        <td>Management fee on this claim (A)</td>
        <td class="num">{{ $body['management_fee']['this_claim'] }}</td>
    </tr>
</table>

<h2>Particulars of payment to construction company</h2>

<table class="totals">
    <tr><td>Total value for work done to date [VAT excl.]</td><td class="num">{{ $body['construction']['work_done'] }}</td></tr>
    <tr><td>Value of all nominated sub-contractors work to date</td><td class="num">{{ $body['construction']['nominated_subcontractors'] }}</td></tr>
    <tr><td>Escalation of cost to date</td><td class="num">{{ $body['construction']['escalation'] }}</td></tr>
    <tr><td>Less: total penalties recovered to date</td><td class="num">{{ $body['construction']['penalties_recovered'] }}</td></tr>
    <tr><td>Plus invoice values for materials on site (80%)</td><td class="num">{{ $body['construction']['materials_on_site'] }}</td></tr>
    <tr><td>Subtotal</td><td class="num">{{ $body['construction']['subtotal_with_materials'] }}</td></tr>
    <tr><td>VAT</td><td class="num">{{ $body['construction']['vat'] }}</td></tr>
    <tr><td>Total value of work done to date</td><td class="num">{{ $body['construction']['work_done_incl_vat'] }}</td></tr>
    <tr><td>Less: total value previously certified and paid</td><td class="num">{{ $body['construction']['previously_certified'] }}</td></tr>
    <tr><td>Total value for construction (B)</td><td class="num">{{ $body['construction']['total_for_construction'] }}</td></tr>
    <tr><td>Less: retention</td><td class="num">{{ $body['construction']['retention'] }}</td></tr>
    <tr class="grand">
        <td>Total value due for construction</td>
        <td class="num">{{ $body['construction']['due_for_construction'] }}</td>
    </tr>
</table>

<h2>Retention account and total for this certificate</h2>

<table>
    <tr>
        <th>Previous retention held</th>
        <td class="num">{{ $body['retention_account']['previous_held'] }}</td>
        <th>Management fee</th>
        <td class="num">{{ $body['payment_summary']['management_fee'] }}</td>
    </tr>
    <tr>
        <th>Retention on this certificate</th>
        <td class="num">{{ $body['retention_account']['this_certificate'] }}</td>
        <th>Payment to contractor</th>
        <td class="num">{{ $body['payment_summary']['payment_to_contractor'] }}</td>
    </tr>
    <tr>
        <th>Total in retention account</th>
        <td class="num">{{ $body['retention_account']['total'] }}</td>
        <th>Cession payments</th>
        <td class="num">{{ $body['payment_summary']['cession_payments'] }}</td>
    </tr>
    {{-- Retention appears here and is deliberately outside the total below: it is money withheld,
         not money paid. --}}
    <tr>
        <th></th>
        <td></td>
        <th>Retention on this certificate (held)</th>
        <td class="num">{{ $body['payment_summary']['retention'] }}</td>
    </tr>
    <tr>
        <th></th>
        <td></td>
        <th>Total value for this certificate (A + B)</th>
        <td class="num"><strong>{{ $body['certificate_total'] }}</strong></td>
    </tr>
</table>

<h2>Summary of actual payments made [VAT incl.]</h2>

<table>
    <thead>
        <tr>
            <th style="inline-size: 10mm">No</th>
            <th style="inline-size: 22mm">Date</th>
            <th>Name of service provider</th>
            <th class="num">Payment to consultant</th>
            <th class="num">Payment to contractor</th>
            <th class="num">Cession payments</th>
            <th class="num">Total amount certified</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($body['schedule']['rows'] as $row)
            <tr>
                <td>{{ $row['number'] }}</td>
                <td>{{ $row['paid_on'] ?? '' }}</td>
                <td>{{ $row['service_provider'] ?? '' }}</td>
                <td class="num">{{ $row['to_consultant'] }}</td>
                <td class="num">{{ $row['to_contractor'] }}</td>
                <td class="num">{{ $row['cession'] }}</td>
                <td class="num">{{ $row['total'] }}</td>
            </tr>
        @endforeach
        <tr>
            <th colspan="3">Total</th>
            <td class="num">{{ $body['schedule']['totals']['to_consultant'] }}</td>
            <td class="num">{{ $body['schedule']['totals']['to_contractor'] }}</td>
            <td class="num">{{ $body['schedule']['totals']['cession'] }}</td>
            <td class="num"><strong>{{ $body['schedule']['totals']['total'] }}</strong></td>
        </tr>
    </tbody>
</table>

@if ($body['notes'])
    <h2>Notes</h2>
    <p>{!! nl2br(e($body['notes'])) !!}</p>
@endif

<h2>Certification and payment authorisation</h2>

<p class="muted">
    I hereby certify that the project has been inspected and that the work per the value claimed has
    been done according to specifications and standards and as per the amount claimed.
</p>

<table class="sign">
    @foreach (array_chunk($body['certifications'], 2) as $pair)
        <tr>
            @foreach ($pair as $signatory)
                <td style="inline-size: 50%">
                    <div class="line">
                        {{ $signatory['name'] ?: '' }}<br>
                        <span class="muted">{{ $signatory['role'] }} · signature · date</span>
                    </div>
                </td>
            @endforeach
        </tr>
    @endforeach
</table>
