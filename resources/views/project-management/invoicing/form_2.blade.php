@if (count($body['fee_block']['lines']))
    <h2>Fee</h2>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">This account</th>
                <th class="num">Previously claimed</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($body['fee_block']['lines'] as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $line['this_account'] }}</td>
                    <td class="num">{{ $line['previously_claimed'] }}</td>
                    <td class="num">{{ $line['total'] }}</td>
                </tr>
            @endforeach
            <tr>
                <td><strong>Sub total</strong></td>
                <td class="num"><strong>{{ $body['fee_block']['subtotal']['this_account'] }}</strong></td>
                <td class="num"><strong>{{ $body['fee_block']['subtotal']['previously_claimed'] }}</strong></td>
                <td class="num"><strong>{{ $body['fee_block']['subtotal']['total'] }}</strong></td>
            </tr>
            <tr>
                <td>Plus {{ rtrim(rtrim(number_format((float) $body['fee_block']['rate'] * 100, 2), '0'), '.') }}%</td>
                <td class="num">{{ $body['fee_block']['uplift']['this_account'] }}</td>
                <td class="num">{{ $body['fee_block']['uplift']['previously_claimed'] }}</td>
                <td class="num">{{ $body['fee_block']['uplift']['total'] }}</td>
            </tr>
        </tbody>
    </table>

    @if ($body['fee_block']['rate_looks_stale'])
        {{-- 14% where the rest of the account uses 15% — almost certainly a pre-2018 VAT rate left
             in the sheet. Reproduced as issued, but never silently. --}}
        <p class="draft">
            Note: this block is rated at {{ rtrim(rtrim(number_format((float) $body['fee_block']['rate'] * 100, 2), '0'), '.') }}%
            while the rest of this account uses {{ rtrim(rtrim(number_format((float) $body['vat_rate'] * 100, 2), '0'), '.') }}%.
        </p>
    @endif
@endif

<h2>Disbursements</h2>
<table>
    <thead>
        <tr>
            <th>Description</th>
            <th>Reference</th>
            <th class="num">This account</th>
            <th class="num">Previously claimed</th>
            <th class="num">Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($body['categories']['lines'] as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td>{{ $line['reference'] ?? '' }}</td>
                <td class="num">{{ $line['this_account'] }}</td>
                <td class="num">{{ $line['previously_claimed'] }}</td>
                <td class="num">{{ $line['total'] }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="center muted">No disbursements claimed.</td></tr>
        @endforelse
    </tbody>
</table>

<table class="totals" style="inline-size: 60%; margin-inline-start: auto;">
    <tr>
        <td>Sub total disbursements</td>
        <td class="num">{{ $body['categories']['subtotal']['total'] }}</td>
    </tr>
    <tr>
        <td>Plus VAT</td>
        <td class="num">{{ $body['categories']['vat']['total'] }}</td>
    </tr>
    <tr>
        <td>Less previously claimed including V.A.T.</td>
        <td class="num">{{ $body['previously_claimed_incl_vat'] }}</td>
    </tr>
    <tr class="grand">
        <td>Now payable</td>
        <td class="num">{{ $header['currency'] }} {{ $body['now_payable'] }}</td>
    </tr>
</table>
