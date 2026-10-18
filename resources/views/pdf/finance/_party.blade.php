{{--
    Who the document is for, in full.

    The client block used to carry a name, an email and — if it happened to be captured — a VAT
    number. Everything else the record already held went unprinted: the postal address, the
    registration number, the contact person, the account code.

    That matters beyond looking thin. A tax invoice has to identify the recipient well enough for
    them to claim the input VAT against it, and an invoice that names a trust with no address and no
    VAT number is one their bookkeeper sends back. The data was always there; only the page was
    missing it.

    Expects: $party, $heading, $accent. Optional: $accountLabel.
--}}
@php
    /*
     * Street and postal as one block, and never the same line twice.
     *
     * Most records carry only one of the two, and plenty carry the same text in both — a postal
     * address copied into the physical field, or the reverse. Printing them as separate lines put
     * the client's address on the invoice twice, which reads as a fault in the document rather than
     * in the record.
     */
    $strip = [\App\Modules\Finance\Accounting\Support\AddressText::class, 'withoutTaxNumber'];

    $lines = array_values(array_unique(array_filter([
        $party->contact_name,
        $strip($party->address),
        $strip($party->postal_address),
        trim(implode(' ', array_filter([$party->city, $party->country]))),
    ], fn ($l) => filled($l))));

    /*
     * A VAT number typed into the address is lifted onto its own line rather than dropped: for
     * records cleaned up already this changes nothing, and for those still carrying it in the
     * address it moves the number instead of losing it.
     */
    $vat = $party->vat_number
        ?: \App\Modules\Finance\Accounting\Support\AddressText::taxNumberIn($party->address)
        ?: \App\Modules\Finance\Accounting\Support\AddressText::taxNumberIn($party->postal_address);

    // Facts a bookkeeper reconciles against, kept apart from the address so neither buries the other.
    $facts = array_filter([
        'VAT number' => $vat,
        'Reg number' => $party->registration_number,
        ($accountLabel ?? 'Account') => $party->customer_number ?: $party->code,
    ], fn ($v) => filled($v));
@endphp

<div>
    <div style="font-size:8px; letter-spacing:0.1em; text-transform:uppercase; color:#9ca3af;">{{ $heading }}</div>
    <div style="font-size:12.5px; font-weight:bold; color:#111827; margin-top:2px;">{{ $party->name }}</div>

    @foreach($lines as $line)
        <div style="color:#4b5563;">{{ $line }}</div>
    @endforeach

    @if($party->email || $party->phone)
        <div style="color:#6b7280; margin-top:2px;">
            {{ $party->email }}@if($party->email && $party->phone) · @endif{{ $party->phone }}
        </div>
    @endif

    {{--
        Each on its own line, labelled.

        Run together they read as a sentence nobody parses, and a VAT number is the single fact a
        recipient checks before they will claim against the document. Its own line is also what
        stops people typing it into the address field to make it appear at all — which put the whole
        address on the invoice twice.
    --}}
    @if($facts)
        <div style="margin-top:6px;">
            @foreach($facts as $label => $value)
                <div style="color:#4b5563; font-size:10px;">
                    {{ $label }}: <strong style="color:#111827;">{{ $value }}</strong>
                </div>
            @endforeach
        </div>
    @endif
</div>
