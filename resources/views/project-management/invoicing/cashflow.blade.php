<h2>Estimated invoice cashflow projections</h2>

<table>
    <thead>
        <tr>
            <th>Month</th>
            {{-- VAT-inclusive, as the client's own cashflow sheet has it. --}}
            <th class="num">Amount (VAT Incl.)</th>
            <th class="num">Cumulative</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($body['rows'] as $row)
            <tr>
                <td>{{ $row['month'] }}</td>
                <td class="num">{{ $row['amount'] }}</td>
                <td class="num">{{ $row['cumulative'] }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="center muted">No cashflow projection captured.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <th>Total</th>
            <th class="num">{{ $body['total'] }}</th>
            <th></th>
        </tr>
    </tfoot>
</table>
