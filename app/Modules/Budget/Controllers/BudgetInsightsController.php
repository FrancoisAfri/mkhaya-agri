<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\FinancialYear;
use App\Modules\Budget\Services\BudgetAnalyticsService;
use App\Modules\Budget\Services\BudgetReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BI for Budget Management: charts on one page, the rows behind them on another.
 *
 * The second matters more than it looks. A finance team that cannot export will rebuild these
 * numbers in a spreadsheet from whatever they can get at, and then there are two versions of the
 * budget — one on the screen and one in the board pack, disagreeing.
 */
class BudgetInsightsController extends Controller
{
    public function __construct(
        private readonly BudgetAnalyticsService $analytics,
        private readonly BudgetReportService $reports,
    ) {
    }

    /** Charts. */
    public function analytics(Request $request): Response
    {
        $this->authorize('viewAny', Budget::class);

        $financialYearId = $request->query('financial_year_id') ? (int) $request->query('financial_year_id') : null;

        return Inertia::render('Modules/Budget/Insights/Analytics', [
            'charts'  => $this->analytics->dashboard($financialYearId),
            'summary' => $this->analytics->summary($financialYearId),
            'financialYears' => $this->financialYears(),
            'filters' => ['financial_year_id' => $financialYearId],
        ]);
    }

    /** Tabular BI datasets, one per area of the module. */
    public function reports(Request $request): Response
    {
        $this->authorize('viewAny', Budget::class);

        $service = $this->service($request);
        $datasets = [];

        foreach (BudgetReportService::reportKeys() as $key => $meta) {
            [$headers, $rows] = $service->dataset($key);

            $datasets[$key] = [
                'label'   => $meta['label'],
                'area'    => $meta['area'],
                // Where the rows come from, so an empty table reads as "nobody has entered this
                // yet, here is where" rather than as a broken feature.
                'source'  => $meta['source'],
                'headers' => $headers,
                'rows'    => $rows,
                // The page says when it is showing a slice; a table that silently stops at 500 rows
                // is one somebody will draw a conclusion from.
                'truncated' => count($rows) >= 500,
            ];
        }

        return Inertia::render('Modules/Budget/Insights/Reports', [
            'summary' => $service->summary(),
            'reports' => $datasets,
            'financialYears' => $this->financialYears(),
            'budgets' => Budget::orderBy('code')->limit(500)->get(['id', 'code', 'name'])
                ->map(fn (Budget $b) => ['id' => $b->id, 'label' => "{$b->code} · {$b->name}"])->all(),
            'filters' => [
                'from'   => $request->query('from'),
                'to'     => $request->query('to'),
                'financial_year_id' => $request->query('financial_year_id'),
                'budget_id' => $request->query('budget_id'),
            ],
        ]);
    }

    /** The same dataset, unbounded, as CSV — Power BI, Excel, a warehouse. */
    public function export(Request $request, string $key): StreamedResponse
    {
        $this->authorize('viewAny', Budget::class);

        abort_unless(array_key_exists($key, BudgetReportService::reportKeys()), 404);

        [$headers, $rows] = $this->service($request)->dataset($key, full: true);

        $filename = 'budget-' . str_replace('_', '-', $key) . '-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // A BOM, so Excel opens the file as UTF-8 instead of mangling every name with an
            // accent in it — which on this platform is most of them.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map([$this, 'safeCell'], $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function service(Request $request): BudgetReportService
    {
        return $this->reports
            ->withRange($request->query('from'), $request->query('to'))
            ->forFinancialYear($request->query('financial_year_id') ? (int) $request->query('financial_year_id') : null)
            ->forBudget($request->query('budget_id') ? (int) $request->query('budget_id') : null);
    }

    private function financialYears(): array
    {
        return FinancialYear::orderByDesc('is_current')->orderByDesc('start_date')
            ->get(['id', 'name'])
            ->map(fn ($y) => ['id' => $y->id, 'label' => $y->name])->all();
    }

    /**
     * Neutralise a cell a spreadsheet would execute.
     *
     * A value beginning =, +, - or @ is a formula to Excel, and budget names come from users. The
     * prefix is what stops an exported report from running something on the machine that opens it.
     */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
