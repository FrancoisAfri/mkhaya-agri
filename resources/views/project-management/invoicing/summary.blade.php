<h2>Summary of fees and disbursements</h2>

<table>
    <thead>
        <tr>
            <th>Description</th>
            <th>Reference</th>
            <th class="num">Previously claimed</th>
            <th class="num">Now claimed this invoice</th>
            <th class="num">Total claimed to date</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>FEES</strong></td>
            <td>{{ $body['fees']['reference'] }}</td>
            <td class="num">{{ $body['fees']['previously_claimed'] }}</td>
            <td class="num">{{ $body['fees']['now_claimed'] }}</td>
            <td class="num">{{ $body['fees']['total_to_date'] }}</td>
        </tr>
        <tr>
            <td colspan="3"><strong>SUB TOTAL FEES</strong></td>
            <td class="num"><strong>{{ $body['sub_total_fees'] }}</strong></td>
            <td></td>
        </tr>
        @foreach ($body['disbursements'] as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td>{{ $line['reference'] }}</td>
                <td class="num">{{ $line['previously_claimed'] }}</td>
                <td class="num">{{ $line['now_claimed'] }}</td>
                <td class="num">{{ $line['total_to_date'] }}</td>
            </tr>
        @endforeach
        <tr>
            <td colspan="3"><strong>SUB TOTAL DISBURSEMENTS</strong></td>
            <td class="num"><strong>{{ $body['sub_total_disbursements'] }}</strong></td>
            <td></td>
        </tr>
    </tbody>
</table>

<table class="totals" style="inline-size: 60%; margin-inline-start: auto;">
    <tr>
        <td>TOTAL FEES AND DISBURSEMENTS NOW CLAIMED</td>
        <td class="num">{{ $body['total_now_claimed'] }}</td>
    </tr>
    <tr>
        <td>PLUS : VAT</td>
        <td class="num">{{ $body['vat'] }}</td>
    </tr>
    <tr class="grand">
        <td>AMOUNT NOW DUE</td>
        <td class="num">{{ $header['currency'] }} {{ $body['amount_now_due'] }}</td>
    </tr>
</table>

<h2>Certification</h2>
<p>{{ $body['certification'] }}</p>

<table class="sign">
    <tr>
        @foreach ($body['signatories'] as $role)
            <td style="inline-size: 50%">
                <div class="line">{{ $role }}</div>
            </td>
        @endforeach
    </tr>
</table>
