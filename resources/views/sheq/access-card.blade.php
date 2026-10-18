{{--
  A contractor site access card, printed at CR80 (85.6 × 54 mm) — the size of a bank card, so it
  fits the holders and lanyards a site already owns.

  Self-contained on purpose. Every style is inline and the photograph and QR are data URIs, so the
  card prints identically from any browser, on a machine with no network, with nothing to load and
  nothing to fail. A card that reflows because a stylesheet did not arrive is a card a guard stops
  trusting.

  What it prints is what a guard needs to match the person in front of them to the register: the
  face, the name, the trade, who they work for, the site and the project. Nothing more. No ID
  number, no medical fact, no certificate — a card is photographed and forwarded on WhatsApp, so
  whatever is on it is published. The live answer stays behind the code.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('sheq-pass.card.title') }} · {{ $card['name'] }}</title>
    <style>
        :root { color-scheme: light; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            background: #eceef1;
            color: #10131a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .sheet { display: flex; flex-direction: column; align-items: center; gap: 16px; }

        /* CR80. Fixed in millimetres so what prints is the size it claims to be. */
        .card {
            width: 85.6mm;
            height: 54mm;
            background: #ffffff;
            border-radius: 3mm;
            overflow: hidden;
            display: flex;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .18);
        }

        .card--back { flex-direction: column; justify-content: space-between; padding: 4mm; }

        .stripe {
            width: 5mm;
            background: #1d2733;
            flex: 0 0 5mm;
        }

        /*
         * `min-width: 0` on every flex and grid item down this chain, not only on the text itself.
         * A flex item defaults to `min-width: auto`, which means it refuses to shrink below its
         * content — so a long employer name pushed the whole column wider and printed straight
         * across the QR instead of ellipsing.
         */
        .body {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            padding: 3.2mm 3mm;
            display: flex;
            flex-direction: column;
            gap: 2mm;
        }

        /*
         * The site and the project run the full width of the card rather than sharing the line
         * with the photograph. In a column beside it they had about 30 mm and ellipsed to
         * "Secunda …", which is not a site a guard can check anything against.
         */
        .head { display: flex; gap: 2.6mm; min-width: 0; }

        .photo {
            width: 20mm;
            height: 25mm;
            flex: 0 0 20mm;
            border-radius: 1.5mm;
            object-fit: cover;
            background: #e4e7ec;
            border: 0.3mm solid #d3d8e0;
        }

        /* Says a photograph is missing rather than leaving a hole that looks like a printing fault. */
        .photo--none {
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: 2mm;
            line-height: 1.3;
            color: #78839a;
            padding: 1.5mm;
        }

        .who { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        .who .trade { margin-bottom: 0; }

        .issuer {
            font-size: 2.1mm;
            letter-spacing: .35mm;
            text-transform: uppercase;
            color: #6b7688;
            margin-bottom: 1mm;
        }

        .card--company .name { -webkit-line-clamp: 3; }

        .name {
            font-size: 3.9mm;
            font-weight: 700;
            line-height: 1.15;
            /* Two lines then ellipsis: a long name must not push the site and project off the card. */
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .trade { font-size: 2.6mm; color: #39455a; margin-top: .6mm; }

        /*
         * Two columns, the label column sized to the widest label there actually is. A fixed
         * label width works in English and nowhere else: "Registration" is "Inombolo yokubhalisa"
         * in isiZulu, which overflowed its box and shunted its own value out of line with the row
         * above it.
         */
        .rows {
            margin-top: auto;
            display: grid;
            grid-template-columns: max-content minmax(0, 1fr);
            column-gap: 1.8mm;
            row-gap: .9mm;
            align-items: baseline;
            min-width: 0;
        }

        /* The label and value are the grid's children, not the wrapper. */
        .row { display: contents; }

        .rows dt {
            color: #6b7688;
            text-transform: uppercase;
            letter-spacing: .12mm;
            font-size: 2mm;
            line-height: 1.25;
            margin: 0;
        }

        .rows dd {
            margin: 0;
            font-size: 2.4mm;
            line-height: 1.25;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }

        /* Wide enough to hold the code at its printed size plus its caption; the code overflowed
           its own column and printed over the rows beside it when this was narrower than the SVG. */
        .code {
            flex: 0 0 21mm;
            padding: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.2mm;
        }

        .code svg { width: 20mm; height: 20mm; display: block; }

        .code { align-self: flex-start; }

        .code span { font-size: 1.9mm; color: #78839a; text-align: center; line-height: 1.2; }

        .back-head {
            display: flex;
            align-items: baseline;
            gap: 2mm;
            padding-bottom: 2mm;
            border-bottom: 0.25mm solid #e1e5ec;
        }

        .back-head b { font-size: 3.2mm; }

        .back-head span {
            font-size: 2.2mm;
            color: #6b7688;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }

        .back-text { font-size: 2.3mm; line-height: 1.45; color: #39455a; }

        .back-text strong { display: block; font-size: 2.6mm; color: #10131a; margin-bottom: 1mm; }

        .back-foot { font-size: 2mm; color: #78839a; display: flex; justify-content: space-between; gap: 2mm; }

        .hint {
            max-width: 85.6mm;
            font-size: 12px;
            line-height: 1.5;
            color: #4b5566;
            text-align: center;
        }

        .hint button {
            font: inherit;
            margin-top: 8px;
            padding: 8px 18px;
            border: 0;
            border-radius: 6px;
            background: #1d2733;
            color: #fff;
            cursor: pointer;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .hint { display: none; }
            .card { box-shadow: none; border: 0.2mm solid #c6ccd6; page-break-inside: avoid; }
            .sheet { gap: 6mm; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <!-- Front -->
    <div class="card {{ ($card['kind'] ?? null) === 'CONTRACTOR_WORKER' ? '' : 'card--company' }}">
        <div class="stripe" aria-hidden="true"></div>

        <div class="body">
            @php
                // A contractor's card is a company's card: it carries no face, so it gets no
                // photograph frame at all rather than an empty one reading "no photograph", which
                // would have a guard looking for a person who does not exist.
                $isPerson = ($card['kind'] ?? null) === 'CONTRACTOR_WORKER';
            @endphp

            <div class="head">
                @if ($isPerson)
                    @if ($photo)
                        <img class="photo" src="{{ $photo }}" alt="{{ $card['name'] }}">
                    @else
                        <div class="photo photo--none">{{ __('sheq-pass.card.no_photo') }}</div>
                    @endif
                @endif

                <div class="who">
                    <div class="issuer">{{ __('sheq-pass.card.title') }}</div>
                    <div class="name">{{ $card['name'] }}</div>
                    @if ($card['trade'])
                        <div class="trade">{{ $card['trade'] }}</div>
                    @endif
                </div>

                <div class="code">
                    {!! $qr !!}
                    <span>{{ __('sheq-pass.card.scan') }}</span>
                </div>
            </div>

            <dl class="rows">
                @if ($card['employer'])
                    <div class="row">
                        <dt>{{ __('sheq-pass.card.employer') }}</dt>
                        <dd>{{ $card['employer'] }}</dd>
                    </div>
                @endif
                <div class="row">
                    <dt>{{ __('sheq-pass.card.site') }}</dt>
                    <dd>{{ $card['site'] ?? __('sheq-pass.card.not_set') }}</dd>
                </div>
                @if ($isPerson)
                    <div class="row">
                        <dt>{{ __('sheq-pass.card.project') }}</dt>
                        <dd>{{ $card['project'] ?? __('sheq-pass.card.not_set') }}</dd>
                    </div>
                @elseif (! empty($card['reference']))
                    {{-- A company's own identifier rather than a project: a contractor is not
                         placed on one, and the registration number is what a gatehouse matches
                         against a delivery note or an order. --}}
                    <div class="row">
                        <dt>{{ __('sheq-pass.card.registration') }}</dt>
                        <dd>{{ $card['reference'] }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>

    <!-- Back: what the card does not promise, which is the part people get wrong. -->
    <div class="card card--back">
        <div class="back-head">
            <b>{{ $card['name'] }}</b>
            <span>{{ $card['employer'] ?? $card['site'] }}</span>
        </div>

        <div class="back-text">
            <strong>{{ __('sheq-pass.card.back_title') }}</strong>
            {{ __('sheq-pass.card.back_body') }}
        </div>

        <div class="back-foot">
            <span>{{ __('sheq-pass.card.issued', ['date' => $card['issued_on']]) }}</span>
            @if (! empty($card['valid_until']))
                <span>{{ __('sheq-pass.card.valid_until', ['date' => $card['valid_until']]) }}</span>
            @endif
        </div>
    </div>

    <p class="hint">
        {{ __('sheq-pass.card.print_hint') }}
        <br>
        <button type="button" onclick="window.print()">{{ __('sheq-pass.card.print') }}</button>
    </p>
</div>
</body>
</html>
