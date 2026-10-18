<h2>Value of the works</h2>
<table>
    <thead>
        <tr>
            <th>External works</th>
            <th class="num">Structural works on buildings</th>
            <th class="num">Other</th>
            <th class="num">Total value</th>
            <th class="num">Excluding contingency allowance</th>
            <th class="num">Construction completed to date</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="num">{{ $body['value_of_works']['external'] }}</td>
            <td class="num">{{ $body['value_of_works']['structural'] }}</td>
            <td class="num">{{ $body['value_of_works']['other'] }}</td>
            <td class="num">{{ $body['value_of_works']['total'] }}</td>
            <td class="num">{{ $body['value_of_works']['contingency_allowance'] }}</td>
            {{-- Including escalation and variations, as the client's form states. --}}
            <td class="num">{{ $body['value_of_works']['completed_to_date'] }}</td>
        </tr>
    </tbody>
</table>

<table class="plain" style="margin-block-start: 3mm">
    <tr>
        <td><strong>Payment certificate:</strong> {{ $body['payment_certificate'] ?? '—' }}</td>
        <td><strong>Dated:</strong> {{ $body['certificate_dated'] ?? '—' }}</td>
        <td><strong>Fee:</strong> {{ $body['fee_percentage'] }}</td>
        <td><strong>Approved fee:</strong> {{ $body['approved_fee'] }}</td>
    </tr>
    @if ($body['adjusted_fee'])
        <tr>
            {{-- Printed, never used to calculate a claim: an adjusted fee is a proposal. --}}
            <td colspan="4" class="muted">Adjusted fee (yet to be approved): {{ $body['adjusted_fee'] }}</td>
        </tr>
    @endif
</table>

<h2>Determination of total fee</h2>
<table>
    <thead>
        <tr>
            <th>Stage</th>
            <th>Description</th>
            <th class="num">Fee apportionment</th>
            <th class="num">Progress to date</th>
            <th class="num">Fee to claim</th>
            <th class="num">Value to date</th>
            <th class="num">Previously claimed</th>
            <th class="num">This account</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($body['stages'] as $stage)
            <tr>
                <td>{{ $stage['code'] }}</td>
                <td>{{ $stage['label'] }}</td>
                <td class="num">{{ $stage['apportionment'] }}</td>
                <td class="num">{{ $stage['progress'] }}</td>
                <td class="num">{{ $stage['stage_fee'] }}</td>
                <td class="num">{{ $stage['value_to_date'] }}</td>
                <td class="num">{{ $stage['previously_claimed'] }}</td>
                <td class="num">{{ $stage['this_account'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- The client's own numbering, 3.10 to 3.14, kept exactly. --}}
<table class="totals" style="inline-size: 65%; margin-inline-start: auto;">
    <tr>
        <td>3.10&nbsp;&nbsp;Net total excluding V.A.T.</td>
        <td class="num">{{ $body['totals']['value_to_date']['excl_vat'] }}</td>
        <td class="num">{{ $body['totals']['this_account']['excl_vat'] }}</td>
    </tr>
    <tr>
        <td>3.11&nbsp;&nbsp;Value Added Tax</td>
        <td class="num">{{ $body['totals']['value_to_date']['vat'] }}</td>
        <td class="num">{{ $body['totals']['this_account']['vat'] }}</td>
    </tr>
    <tr>
        <td>3.12&nbsp;&nbsp;Total including V.A.T.</td>
        <td class="num">{{ $body['totals']['value_to_date']['incl_vat'] }}</td>
        <td class="num">{{ $body['totals']['this_account']['incl_vat'] }}</td>
    </tr>
    <tr>
        <td>3.13&nbsp;&nbsp;Amount previously paid including V.A.T.</td>
        <td class="num">{{ $body['totals']['previously_paid_incl_vat'] }}</td>
        <td></td>
    </tr>
    <tr class="grand">
        <td>3.14&nbsp;&nbsp;Fees now payable including V.A.T.</td>
        <td class="num">{{ $body['totals']['now_payable_incl_vat'] }}</td>
        <td></td>
    </tr>
</table>

@unless ($body['totals']['reconciles'])
    {{-- The two routes to the payable figure disagree, which happens when an earlier account was
         paid at an amount other than it claimed. Said out loud rather than reported as one of them. --}}
    <p class="draft">Note: the amount previously paid does not agree with what earlier accounts claimed.</p>
@endunless
