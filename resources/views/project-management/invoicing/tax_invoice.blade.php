{{-- The Tax Invoice restates the Summary's three figures; it has no arithmetic of its own. --}}
@if ($body['description'])
    <p>{!! nl2br(e($body['description'])) !!}</p>
@endif

<table class="totals" style="inline-size: 60%; margin-inline-start: auto;">
    @foreach ($body['lines'] as $line)
        <tr>
            <td>{{ $line['label'] }}</td>
            <td class="num">{{ $header['currency'] }} {{ $line['amount'] }}</td>
        </tr>
    @endforeach
    <tr>
        <td><strong>Subtotal</strong></td>
        <td class="num">{{ $header['currency'] }} {{ $body['subtotal'] }}</td>
    </tr>
    <tr>
        <td>VAT ({{ $body['vat_rate'] }})</td>
        <td class="num">{{ $header['currency'] }} {{ $body['vat'] }}</td>
    </tr>
    <tr class="grand">
        <td>Total amount due</td>
        <td class="num">{{ $header['currency'] }} {{ $body['total'] }}</td>
    </tr>
</table>

@if ($body['banking']['bank'] || $body['banking']['account_number'])
    <h2>Banking details</h2>
    <table class="plain">
        <tr>
            <td style="inline-size: 50%">
                @if ($body['banking']['bank'])<strong>Bank:</strong> {{ $body['banking']['bank'] }}<br>@endif
                @if ($body['banking']['account_name'])<strong>Account name:</strong> {{ $body['banking']['account_name'] }}<br>@endif
                @if ($body['banking']['account_number'])<strong>Account no.:</strong> {{ $body['banking']['account_number'] }}@endif
            </td>
            <td>
                @if ($body['banking']['branch_code'])<strong>Branch code:</strong> {{ $body['banking']['branch_code'] }}<br>@endif
                <strong>Reference:</strong> {{ $body['banking']['reference'] }}
            </td>
        </tr>
    </table>
@endif

<table class="sign">
    <tr>
        @foreach ($body['signatories'] as $role)
            <td style="inline-size: 50%"><div class="line">{{ $role }}</div></td>
        @endforeach
    </tr>
</table>
