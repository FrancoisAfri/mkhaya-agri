{{--
    What a scanned contractor or worker pass resolves to (CON-12, CON-13).

    Plain server-rendered HTML on purpose. This loads on a guard's phone, on a bad signal, at a
    boom with a queue behind it — one request, no bundle, no session. A verdict that takes four
    seconds to appear is a verdict nobody waits for, and the first thing on the screen has to be
    readable at arm's length without being interpreted.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Never indexed and never cached: the verdict is only true at the moment it was asked. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ($result['found'] ?? false) ? ($result['name'] ?? __('sheq-pass.page.title_check')) : __('sheq-pass.page.title_unknown') }}</title>
    <style>
        :root {
            --ok: #1b5e20; --ok-bg: #e8f5e9;
            --no: #b71c1c; --no-bg: #ffebee;
            --unknown: #4e342e; --unknown-bg: #efebe9;
            --ink: #1a1a1a; --muted: #5f5f5f; --line: #e0e0e0; --paper: #fff;
        }
        @media (prefers-color-scheme: dark) {
            :root { --ink: #f2f2f2; --muted: #b9b9b9; --line: #3a3a3a; --paper: #171717;
                    --ok-bg: #11320f; --no-bg: #3a1113; --unknown-bg: #2a2320; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; background: var(--paper); color: var(--ink);
               font: 16px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .wrap { max-width: 520px; margin: 0 auto; }
        /* The verdict, readable at arm's length. Colour is never the only signal — the words say
           it too, for a guard who cannot tell red from green and for a photocopied printout. */
        .verdict { border-radius: 12px; padding: 20px 16px; text-align: center; margin-bottom: 16px; }
        .verdict h1 { margin: 0; font-size: 28px; letter-spacing: .02em; }
        .verdict p { margin: 6px 0 0; font-size: 15px; }
        .approved { background: var(--ok-bg); color: var(--ok); }
        .denied   { background: var(--no-bg); color: var(--no); }
        /* Amber, and deliberately not a shade of either: a guard glancing at a phone in sunlight
           must not read "could not check" as a near-miss version of approved or of refused. */
        .review   { background: #fff4e0; color: #8a4b00; }
        .unknown  { background: var(--unknown-bg); color: var(--unknown); }
        .who { margin: 0 0 16px; }
        .who strong { font-size: 19px; }
        .who span { color: var(--muted); font-size: 14px; }
        .reasons { background: var(--no-bg); color: var(--no); border-radius: 10px;
                   padding: 12px 14px; margin-bottom: 16px; }
        .reasons ul { margin: 6px 0 0; padding-left: 20px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em;
             color: var(--muted); margin: 20px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 9px 0; border-bottom: 1px solid var(--line); vertical-align: top; }
        td.mark { width: 28px; font-weight: 700; }
        td.mark.y { color: var(--ok); }
        td.mark.n { color: var(--no); }
        td.label { width: 36%; }
        td.note { color: var(--muted); font-size: 14px; }
        .ppe { background: var(--unknown-bg); border-radius: 10px; padding: 12px 14px;
               margin-top: 16px; font-size: 15px; }
        footer { margin-top: 22px; color: var(--muted); font-size: 12px; }
    </style>
</head>
<body>
<div class="wrap">

@if (! ($result['found'] ?? false))
    {{--
        An unknown code is reported as unknown, not as denied. They are different facts and a guard
        has to tell them apart: a denial means this person may not come in; an unknown code means
        the card is not one of ours or has been replaced. Answering "denied" to a rotated card
        sends somebody to argue about their compliance instead of being issued a new one.
    --}}
    <div class="verdict unknown">
        <h1>{{ __('sheq-pass.page.unknown_heading') }}</h1>
        <p>{{ __('sheq-pass.page.unknown_lead') }}</p>
    </div>
    <p>{{ __('sheq-pass.page.unknown_body') }}</p>
@else
    {{--
        Three answers, not two (COM-3).

        A check that could not be run is neither a pass nor a refusal. Rendered as DENIED, a
        momentarily unreachable register turns away a crew of twelve; rendered as APPROVED, it
        waves through whoever happens to arrive while it is down. So it gets its own colour and
        its own instruction, which is to ask somebody — the only correct thing to do when the
        honest answer is that nobody knows.
    --}}
    @php
        $outcome = $result['outcome'] ?? ($result['allowed'] ? 'ALLOWED' : 'DENIED');
        $band = match ($outcome) {
            'ALLOWED' => 'approved',
            'REVIEW_REQUIRED', 'UNKNOWN' => 'review',
            default => 'denied',
        };
    @endphp
    <div class="verdict {{ $band }}">
        <h1>{{ __('sheq-pass.page.outcome.'.$outcome) }}</h1>
        <p>
            {{ $result['subject_type'] === 'CONTRACTOR'
                ? __('sheq-pass.page.contractor_pass')
                : __('sheq-pass.page.worker_pass') }}
            @if ($result['site']) · {{ $result['site'] }} @endif
            @if ($result['activity']) · {{ $result['activity'] }} @endif
        </p>
    </div>

    <p class="who">
        <strong>{{ $result['name'] }}</strong><br>
        @if ($result['subject_type'] === 'CONTRACTOR_WORKER')
            <span>{{ $result['contractor'] }}</span>
        @elseif ($company)
            <span>{{ __('sheq-pass.page.verified_against', ['company' => $company]) }}</span>
        @endif
    </p>

    @if ($band === 'review')
        {{-- What to do about it, because "could not be verified" with no instruction is how a
             guard ends up deciding policy at a boom on their own. --}}
        <div class="reasons">
            <strong>{{ __('sheq-pass.page.review_heading') }}</strong>
            <p style="margin:.35rem 0 0">{{ __('sheq-pass.page.review_body') }}</p>
        </div>
    @endif

    @if (! empty($result['reasons']))
        {{-- The exact reason. "Access denied" at a boom with a queue behind it starts an argument;
             "working-at-height competency expired on 3 March" ends one. --}}
        <div class="reasons">
            <strong>{{ __('sheq-pass.page.refused_because') }}</strong>
            <ul>
                @foreach ($result['reasons'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <h2>{{ __('sheq-pass.page.checks') }}</h2>
    <table>
        @foreach ($result['checks'] as $check)
            <tr>
                {{-- A glyph as well as a colour. --}}
                <td class="mark {{ $check['ok'] ? 'y' : 'n' }}">{{ $check['ok'] ? '✓' : '✕' }}</td>
                <td class="label">{{ __('sheq-pass.'.$check['key']) }}</td>
                <td class="note">{{ $check['note'] }}</td>
            </tr>
        @endforeach
    </table>

    @if (! empty($result['ppe']))
        {{--
            What the work requires, from the risk assessment in force. Reported rather than
            asserted: a contractor issues their own PPE and this system holds no issue record for
            their people, so the honest thing is to tell the guard what to look for.
        --}}
        <div class="ppe">
            <strong>{{ __('sheq-pass.page.ppe_heading') }}</strong><br>
            {{ implode(', ', $result['ppe']) }}<br>
            <span style="color: var(--muted); font-size: 13px">{{ __('sheq-pass.page.ppe_note') }}</span>
        </div>
    @endif

    @if (! empty($result['advisory']))
        <h2>{{ __('sheq-pass.page.advisory_heading') }}</h2>
        <table>
            @foreach ($result['advisory'] as $note)
                <tr><td class="note" colspan="3">{{ $note }}</td></tr>
            @endforeach
        </table>
    @endif

    <footer>
        {{ __('sheq-pass.page.footer', ['at' => $result['scanned_at']]) }}
    </footer>
@endif

</div>
</body>
</html>
