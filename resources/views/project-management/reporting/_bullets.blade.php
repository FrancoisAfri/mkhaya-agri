{{--
    Free text as bullet points.

    People type these as lines — one challenge per line, sometimes already prefixed with a dash or a
    bullet character. Rendering the block verbatim produced a paragraph that read as one long
    sentence, and the meeting could not count the items.

    ── Why this is not a <ul> ───────────────────────────────────────────────────

    It was, and the list element is what broke the page. dompdf will not shrink a list below its own
    minimum width whatever the column is declared as, so the moment these notes became bullets the
    two narrative columns grew, took the month columns' share with them, and the last two months of
    cash flow fell off the paper. The list also carried block margins that put a finger's width of
    white between every point.

    A block per line with a hanging indent has the same minimum width as the text inside it, which
    wraps — so the column stays exactly as wide as it was declared, and every space is one this
    file asked for.

    Blank lines are dropped and any marker the author typed is removed, so a list somebody already
    bulleted by hand does not come out with two bullets on every line.
--}}
@php
    $points = collect(preg_split('/\r\n|\r|\n/', (string) ($text ?? '')))
        ->map(fn ($line) => trim(preg_replace('/^\s*[-*\x{2022}\x{00B7}]\s*/u', '', $line)))
        ->filter(fn ($line) => $line !== '')
        ->values();
@endphp

@if ($points->count() > 1)
    @foreach ($points as $point)
        <div class="point">&bull;&nbsp;{{ $point }}</div>
    @endforeach
@elseif ($points->count() === 1)
    {{-- One item is a sentence, not a list. A lone bullet looks like a mistake. --}}
    {{ $points->first() }}
@endif
