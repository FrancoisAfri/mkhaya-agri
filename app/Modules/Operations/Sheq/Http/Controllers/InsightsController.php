<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\KpiService;
use App\Modules\Operations\Sheq\Services\SheqReportService;
use App\Modules\Operations\Sheq\Support\ReportProvenance;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use App\Modules\Operations\Sheq\Support\SheqReportExport;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response as HttpResponse;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BI analysis and reports (§5.11, SHEQ-DASH-2).
 *
 * Reads the stored KPI projection (SHEQ-ARC-4) — never computes in-request. The trend is assembled
 * from up to twelve monthly snapshots, so a month with no snapshot appears as a gap rather than as
 * a zero: a chart that draws 0.0 LTIFR for a month nobody computed is telling an executive the
 * safest possible lie.
 *
 * Metric definitions come from the active regulatory pack (SHEQ-REG-7), so a mine reading DIFR and
 * a factory reading LTIFR each see their own convention rather than a hard-coded list.
 */
class InsightsController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(
        private readonly KpiService $kpis,
        private readonly SheqReportService $reports,
    ) {}

    /** Trend analysis across the last twelve periods. */
    public function analysis(Request $request): Response
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return Inertia::render('Modules/Sheq/Insights/Analysis', [
                'noCompany' => true, 'periods' => [], 'metrics' => [],
                'leading' => [], 'sites' => [], 'siteId' => null,
            ]);
        }

        $siteId = $this->permittedSite($request, $companyId);

        // Validated against this company, so a hand-edited query string cannot pull another
        // tenant's site into the filter.
        if ($siteId !== null && ! DB::table('sheq_sites')
            ->where('company_id', $companyId)->where('id', $siteId)->exists()) {
            $siteId = null;
        }

        $periods = [];
        $cursor = Carbon::today()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $snapshot = $this->kpis->snapshot($companyId, (int) $cursor->year, (int) $cursor->month, $siteId);

            $periods[] = [
                'period' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                // Null, not zero. The distinction is the whole point of this page.
                'rates' => $snapshot['metrics']['rates'] ?? null,
                'counts' => $snapshot['metrics']['lagging'] ?? null,
                'leading' => $snapshot['metrics']['leading'] ?? null,
                'hours' => $snapshot['inputs']['exposure']['total_hours'] ?? null,
                'computed_at' => $snapshot['computed_at'] ?? null,
            ];

            $cursor->subMonth();
        }

        $periods = array_reverse($periods);

        // The metrics the pack actually defines, discovered from whichever snapshot has them.
        $metrics = [];

        foreach ($periods as $period) {
            foreach (array_keys($period['rates'] ?? []) as $key) {
                $metrics[$key] = true;
            }
        }

        return Inertia::render('Modules/Sheq/Insights/Analysis', [
            'noCompany' => false,
            'periods' => $periods,
            'metrics' => array_keys($metrics),
            'sites' => DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('is_active', true)
                ->whereNull('deleted_at')->orderBy('path')
                ->get(['id', 'name'])->values(),
            'siteId' => $siteId,
            'hasAnySnapshot' => collect($periods)->contains(fn ($p) => $p['rates'] !== null),
        ]);
    }

    /**
     * The report pack: what a monthly safety report is assembled from.
     *
     * Deliberately the numbers rather than prose. §7's AI-5 would write the narrative, and that is
     * not built — printing a generated commentary here would be exactly the confident wrong answer
     * SHEQ-AI-11 refuses to ship.
     */
    /**
     * The report screen: pick a report, a period and a site.
     *
     * The catalogue lives in {@see SheqReportService} so this action never learns what any
     * individual report contains — it renders whichever one was asked for, and exports the same
     * rows through the same code path. A CSV that is built separately from the screen is a CSV that
     * eventually disagrees with it.
     */
    public function reports(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $catalogue = $this->reports->catalogue();

        if ($companyId === null) {
            return Inertia::render('Modules/Sheq/Insights/Reports', [
                'noCompany' => true, 'catalogue' => $catalogue, 'groups' => SheqReportService::GROUPS,
                'report' => null, 'rows' => [], 'sites' => [],
            ]);
        }

        [$key, $from, $to, $siteId, $fromDate, $toDate] =
            $this->reportFilters($request, $catalogue);

        return Inertia::render('Modules/Sheq/Insights/Reports', [
            'noCompany' => false,
            'catalogue' => $catalogue,
            'groups' => SheqReportService::GROUPS,
            'report' => $key,
            'periodic' => $catalogue[$key]['periodic'],
            // The dates as chosen, not the UTC window derived from them.
            'from' => $fromDate,
            'to' => $toDate,
            'siteId' => $siteId,
            'sites' => DB::table('sheq_sites')->where('company_id', $companyId)
                ->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all(),
            'rows' => $this->reports->run($key, $companyId, $from, $to, $siteId),
            'kpis' => $this->kpis->snapshot($companyId, (int) Carbon::now()->year,
                (int) Carbon::now()->month)['metrics']['rates'] ?? null,
        ]);
    }

    /**
     * The same report, as a file.
     *
     * Streamed rather than assembled in memory: a year of PPE issues or visitor movements across a
     * large site is a lot of rows, and the request that falls over is always the one somebody needs
     * for an inspection.
     */
    /**
     * Export a report (CMD-8).
     *
     * CSV, Excel or PDF, each carrying the same four provenance items. The format is a parameter
     * rather than three actions because the only difference between them is how the same rows and
     * the same `ReportProvenance` are rendered — and three actions would be three places for the
     * provenance to be forgotten from.
     */
    public function exportReport(Request $request): SymfonyResponse
    {
        $companyId = $this->requireCompany($request);
        $catalogue = $this->reports->catalogue();

        [$key, $from, $to, $siteId, $fromDate, $toDate] =
            $this->reportFilters($request, $catalogue);

        $format = strtolower($request->string('format')->toString() ?: 'csv');

        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            $format = 'csv';
        }

        $columns = array_keys($catalogue[$key]['columns']);
        $rows = $this->reports->run($key, $companyId, $from, $to, $siteId);
        // The provenance prints the period a reader chose, so it takes the local dates — an
        // export headed "2025-12-31 to 2026-12-30" for a request for the 2026 calendar year is
        // the kind of detail that makes somebody distrust the whole document.
        $provenance = $this->provenanceFor($request, $companyId, $key,
            Carbon::parse($fromDate), Carbon::parse($toDate), $siteId);

        return match ($format) {
            'xlsx' => $this->exportXlsx($provenance, $key, $columns, $rows),
            'pdf' => $this->exportPdf($provenance, $key, $columns, $rows),
            default => $this->exportCsv($provenance, $key, $columns, $rows),
        };
    }

    private function provenanceFor(Request $request, int $companyId, string $key,
        Carbon $from, Carbon $to, ?int $siteId): ReportProvenance
    {
        $user = $request->user();

        return new ReportProvenance(
            // The catalogue has no human title — its `columns` map is key => TYPE, not
            // key => label — so both the report name and the headings are humanised from their
            // keys. Reading the type out as a heading would have printed "text, number, date".
            title: $this->humanise($key),
            from: $from,
            to: $to,
            // Named as the reader chose them, not as the query used them. "Site: Tower block",
            // never "site_id: 19496" — a filter nobody can read is a filter nobody can check.
            filters: [
                'Site' => $siteId === null ? null : DB::table('sheq_sites')
                    ->where('company_id', $companyId)->where('id', $siteId)->value('name'),
            ],
            ownerName: $user?->person?->first_name === null ? null
                : trim($user->person->first_name.' '.($user->person->surname ?? '')),
            ownerEmail: $user?->email,
        );
    }

    /** @param  list<array<string,mixed>>  $rows */
    private function exportCsv(ReportProvenance $provenance, string $key, array $columns,
        array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($provenance, $columns, $rows) {
            $out = fopen('php://output', 'w');

            // A BOM, so Excel opens the accented and Nguni characters in these reports correctly
            // instead of turning a site name into mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($provenance->headerRows() as $preamble) {
                fputcsv($out, $preamble);
            }

            fputcsv($out, $columns);

            foreach ($rows as $row) {
                fputcsv($out, array_map(function ($c) use ($row) {
                    $value = $row[$c] ?? null;

                    return is_bool($value) ? ($value ? 'yes' : 'no') : $value;
                }, $columns));
            }

            fclose($out);
        }, $provenance->filename($key, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param  list<array<string,mixed>>  $rows */
    private function exportXlsx(ReportProvenance $provenance, string $key, array $columns,
        array $rows): SymfonyResponse
    {
        $headings = array_map([$this, 'humanise'], $columns);

        $body = array_map(fn (array $row) => array_map(function ($c) use ($row) {
            $value = $row[$c] ?? null;

            return is_bool($value) ? ($value ? 'yes' : 'no') : $value;
        }, $columns), $rows);

        return Excel::download(
            new SheqReportExport($provenance, $headings, $body),
            $provenance->filename($key, 'xlsx'),
        );
    }

    /** @param  list<array<string,mixed>>  $rows */
    private function exportPdf(ReportProvenance $provenance, string $key, array $columns,
        array $rows): HttpResponse
    {
        $headings = array_map([$this, 'humanise'], $columns);

        /*
         * Capped, and the cap is stated on the document.
         *
         * A PDF of forty thousand rows is a file nobody opens and a request that times out. A
         * truncated PDF that does not SAY it is truncated is worse than either, because somebody
         * totals the visible rows.
         */
        $limit = 2000;
        $truncated = count($rows) > $limit;

        $pdf = Pdf::loadView('sheq.reports.export', [
            'provenance' => $provenance->toArray(),
            'headings' => $headings,
            'columns' => $columns,
            'rows' => array_slice($rows, 0, $limit),
            'total' => count($rows),
            'truncated' => $truncated,
            'limit' => $limit,
        ])->setPaper('a4', count($columns) > 5 ? 'landscape' : 'portrait');

        return $pdf->download($provenance->filename($key, 'pdf'));
    }

    /**
     * Resolve the filters once, so the screen and the export cannot disagree about what was asked.
     *
     * @param  array<string, array{periodic:bool}>  $catalogue
     * @return array{0:string, 1:Carbon, 2:Carbon, 3:?int, 4:string, 5:string}
     */
    private function reportFilters(Request $request, array $catalogue): array
    {
        $key = $request->string('report')->toString();

        if (! isset($catalogue[$key])) {
            $key = array_key_first($catalogue);
        }

        // A month, because that is the period a safety meeting runs on.
        $from = $this->parseDate($request->string('from')->toString())
            ?? Carbon::now()->startOfMonth();
        $to = $this->parseDate($request->string('to')->toString())
            ?? Carbon::now()->endOfMonth();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        // Resolved here rather than taken as a parameter: this method is called by both the
        // screen and the export, and a second argument the two could pass differently is the
        // thing it exists to prevent.
        $companyId = $this->companyId($request);
        $siteId = $this->permittedSite($request, $companyId);

        /*
         * The chosen dates as the SITE reads them (REG-FIX-6).
         *
         * A report for "1 to 7 October" on a site in SAST previously ran from UTC midnight to
         * UTC midnight, which starts two hours late at the gate — so the night shift of the 1st
         * was missing and the night shift of the 8th was included. The attendance screen now uses
         * the site's local day, and a report disagreeing with the screen it is reconciled against
         * is worse than either being wrong alone.
         *
         * With no site chosen the fallback zone applies: a group's "today" has to be somebody's.
         */
        [$start, $end] = Timestamps::siteRange($siteId, $from, $to, $companyId);

        /*
         * Two different things, and conflating them is a mistake worth naming: the window the
         * QUERY uses is in UTC because that is what the stored column holds, and the dates the
         * SCREEN shows are the local ones the reader chose. Returning only the UTC pair makes the
         * date pickers jump back a day for every site east of Greenwich.
         */
        return [$key, $start, $end, $siteId, $from->toDateString(), $to->toDateString()];
    }

    /**
     * A key as a heading.
     *
     * `incident_type` becomes "Incident type". Deliberately not a translation lookup: these keys
     * are column names chosen by whoever wrote the report query, there are a few hundred of them
     * across the catalogue, and a missing key would print the dotted key itself on a document
     * somebody files.
     */
    private function humanise(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
