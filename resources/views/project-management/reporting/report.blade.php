{{--
    The monthly Project & Financial Report, as the document.

    Landscape and monochrome: it is a wide management table that gets printed, filed and
    photocopied, and a report that only reads in colour stops reading the moment it leaves the
    screen. DejaVu Sans because DomPDF needs an embedded font for anything beyond ASCII — the
    bullet character in the Challenges column is the obvious casualty otherwise.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $period['name'] }}</title>
    <style>
        @page { margin: 10mm 8mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 7.5pt; color: #000; }
        h1 { font-size: 13pt; margin: 0 0 1mm; }
        .muted { color: #444; }
        .meta { margin-bottom: 3mm; }
        .rule { border-block-end: 1px solid #000; margin: 2mm 0 3mm; }

        /*
         * Fixed layout, so the columns are the widths declared below rather than whatever the
         * longest heading happens to be.
         *
         * With automatic layout dompdf sizes each column to its content and, when the total exceeds
         * the page, simply runs off the right edge — it does not shrink to fit. A heading reading
         * "Total professional fee invoiced to date" is far wider than the number under it, and ten
         * fixed columns plus six months of cash flow put the last of them past the paper. The
         * report looked complete and was not.
         */
        /*
         * `width`, not `inline-size`.
         *
         * dompdf does not implement CSS logical properties, so `inline-size: 100%` was dropped
         * silently and the table had no width at all — it sized to its content and ran off the
         * right of the paper, taking the last months of cash flow with it. Every declared column
         * width was a percentage of a width that was never set.
         *
         * That is the whole of why this report "did not show the whole report", and none of the
         * other tuning could have fixed it. Physical properties only in this file.
         */
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #999; padding: 1mm 1.4mm; vertical-align: top; }

        /*
         * Only cell content breaks mid-word, and only when it has to — a long project name or an
         * unbroken reference must wrap rather than widen its column. Headings do not: breaking one
         * gives "Progr / ess", which reads as a fault in the report rather than as a narrow column.
         */
        td { word-wrap: break-word; overflow-wrap: break-word; }
        th { background: #eee; text-align: left; font-weight: bold; font-size: 7pt; }
        /*
         * `end` is a logical value dompdf does not know, so these were left-aligned all along —
         * a column of money that does not line up on its decimal point is the one thing a finance
         * table must get right.
         *
         * `nowrap` stays on the figures, which must never break, and comes off the headings, which
         * were being clipped instead of wrapping: "Outstanding" and "March 2026" both lost their
         * tails to it.
         */
        td.num, th.num { text-align: right; white-space: nowrap; }

        /*
         * Only the column headings wrap. The figures never do — a total that comes out as
         * "120000. / 00" is a different number at a glance — and the totals row is figures.
         */
        thead th.num { white-space: normal; }
        /* Bullets and line breaks are what the Challenges column is made of. */
        /*
         * No minimum width. It was 45mm on both narrative columns — 90mm of a 281mm page claimed
         * before anything else was measured — and `pre-wrap` on top of that stopped long lines
         * wrapping where they needed to. The bullets carry the line breaks now.
         */
        td.notes { white-space: normal; }
        tfoot th { background: #f6f6f6; }
        .comment { margin-top: 4mm; }
        .stamp { font-size: 6.5pt; color: #555; margin-top: 4mm; }

        /* The answer to the column, set apart from the commentary underneath it. */
        .status { font-weight: bold; margin-bottom: 0.6mm; }

        /*
         * Tight bullets.
         *
         * dompdf applies its own block margins and line spacing to list items, and inside a cell
         * this narrow that produced a finger's width of white between every point — the report
         * ran to a third of a page for three lines of text. Every space is stated here: nothing is
         * left to the default, because the default is what caused it.
         */
        .point {
            margin: 0 0 0.3mm; padding-left: 2.6mm;
            /* Hanging indent: the bullet sits in the margin and wrapped text lines up under itself. */
            text-indent: -2.6mm;
            line-height: 1.2;
        }
    </style>
</head>
<body>
    <h1>Project and Financial Reporting</h1>
    <div class="meta">
        <strong>{{ $period['label'] }}</strong>
        @if ($period['meeting_date'])
            &nbsp;·&nbsp; <strong>Meeting Date:</strong> {{ $period['meeting_date'] }}
        @endif
    </div>

    <div class="rule"></div>

    <table>
        {{--
            Declared once here rather than on every cell. The narrative columns get the room —
            status and challenges are what the meeting actually reads — and the money columns are
            sized to their figures rather than to their headings.
        --}}
        @php
            /*
             * Column widths, declared on the header cells.
             *
             * Not on a <colgroup>: dompdf ignores <col> widths, and it ignored them silently — the
             * table came out identical with and without one, sized to its content and running off
             * the paper. Widths on the first row's cells are what it actually reads.
             *
             * The figures are millimetres of a 281mm landscape page as percentages: a money column
             * needs about 14mm to hold "500000.00" on one line, and what is left after nine of
             * those goes to the words. They add to a hundred, which matters — an earlier set summed
             * to 112% and dompdf scaled everything down, breaking "Preliminary design" across lines
             * as "Preliminar / y design".
             */
            $monthCount = max(count($months), 1);
            $monthWidth = $showFinances ? round(33.0 / $monthCount, 3) : 0;
        @endphp
        <thead>
            <tr>
                <th style="width:{{ $showFinances ? '3' : '4' }}%">No.</th>
                <th style="width:{{ $showFinances ? '10' : '15' }}%">Project Name</th>
                <th style="width:{{ $showFinances ? '6' : '11' }}%">Project Manager</th>
                <th style="width:{{ $showFinances ? '6' : '11' }}%">Stage</th>
                <th class="num" style="width:{{ $showFinances ? '4' : '7' }}%">Progress</th>
                <th style="width:{{ $showFinances ? '11' : '26' }}%">Project Status</th>
                <th style="width:{{ $showFinances ? '9' : '26' }}%">Challenges</th>
                @if ($showFinances)
                    <th class="num" style="width:6%">Est. fee</th>
                    <th class="num" style="width:6%">Invoiced</th>
                    <th class="num" style="width:6%">Outstanding</th>
                    {{-- Dynamic: these follow the reporting month. --}}
                    @foreach ($months as $month)
                        <th class="num" style="width:{{ $monthWidth }}%">{{ $month['label'] }}</th>
                    @endforeach
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['no'] }}</td>
                    <td>{{ $row['project'] }}<br><span class="muted">{{ $row['project_code'] }}</span></td>
                    <td>{{ $row['project_manager_label'] }}</td>
                    <td>{{ $row['stage'] }}</td>
                    <td class="num">{{ $row['progress_percentage'] }}</td>
                    {{--
                        The status first, then what is happening under it.

                        These were run together into one block of text, so "On track" read as the
                        first line of the commentary rather than as the answer to the column's
                        question. The status is the thing scanned down the page in a meeting; the
                        notes are what is read once a row has been picked out.
                    --}}
                    <td class="notes">
                        @if (filled($row['project_status'] ?? null))
                            <div class="status">{{ $row['project_status'] }}</div>
                        @endif
                        @include('project-management.reporting._bullets', ['text' => $row['project_status_notes'] ?? null])
                    </td>
                    <td class="notes">
                        @include('project-management.reporting._bullets', ['text' => $row['challenges'] ?? null])
                    </td>
                    @if ($showFinances)
                        <td class="num">{{ $row['estimated_professional_fee'] ?? '' }}</td>
                        <td class="num">{{ $row['professional_fee_invoiced_to_date'] ?? '' }}</td>
                        <td class="num">{{ $row['professional_fee_outstanding'] ?? '' }}</td>
                        @foreach ($months as $month)
                            <td class="num">{{ $row['cashflow'][$month['key']] ?? '' }}</td>
                        @endforeach
                    @endif
                </tr>
            @empty
                <tr><td colspan="20">No projects on this report.</td></tr>
            @endforelse
        </tbody>
        @if ($showFinances && $summary)
            <tfoot>
                <tr>
                    <th colspan="10">Estimated Invoice cashflow projections (VAT Excl.)</th>
                    @foreach ($summary['cashflow'] as $month)
                        <th class="num">{{ $month['amount'] }}</th>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    @if ($period['general_comment'])
        <div class="comment">
            <strong>General comment</strong><br>
            <span style="white-space: pre-wrap">{{ $period['general_comment'] }}</span>
        </div>
    @endif

    <div class="stamp">{{ $period['name'] }} · printed {{ $printed }}</div>
</body>
</html>
