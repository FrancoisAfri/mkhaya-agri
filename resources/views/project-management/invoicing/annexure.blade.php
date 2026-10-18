{{-- One template for all thirteen: the columns come from the annexure's line type. --}}
<table>
    <thead>
        <tr>
            @foreach ($body['columns'] as $column)
                <th class="{{ str_contains(strtolower($column), 'claim') || str_contains(strtolower($column), 'total') || str_contains(strtolower($column), 'amount') || str_contains(strtolower($column), 'tariff') ? 'num' : '' }}">{{ $column }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($body['rows'] as $row)
            <tr>
                @foreach ($row as $cell)
                    <td>{{ $cell }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($body['columns']) }}" class="center muted">No lines claimed.</td></tr>
        @endforelse
    </tbody>
</table>

<table class="totals" style="inline-size: 55%; margin-inline-start: auto;">
    <tr>
        <td>Previously claimed</td>
        <td class="num">{{ $body['totals']['previously_claimed'] }}</td>
    </tr>
    <tr>
        <td>This invoice</td>
        <td class="num">{{ $body['totals']['this_invoice'] }}</td>
    </tr>
    <tr class="grand">
        <td>Total claimed to date</td>
        <td class="num">{{ $body['totals']['total'] }}</td>
    </tr>
</table>

@if (! empty($body['projections']))
    {{-- Monthly planning figures. A note about the appointment, never part of the claim. --}}
    <h2>Projections</h2>
    <table>
        <thead>
            <tr>
                @foreach ($body['projections'] as $projection)
                    <th class="num">{{ $projection['month'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                @foreach ($body['projections'] as $projection)
                    <td class="num">{{ $projection['amount'] }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>
@endif
