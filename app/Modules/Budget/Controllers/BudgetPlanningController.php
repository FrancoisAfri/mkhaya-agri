<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetCategory;
use App\Modules\Budget\Models\FinancialYear;
use App\Modules\Budget\Services\BudgetPlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Budget preparation: phasing a budget across the year, rolling a year over, and getting a
 * spreadsheet in without retyping it.
 *
 * All three are the same season of work — the fortnight before a financial year starts, when
 * everybody is doing this in Excel because the system had nothing for it.
 */
class BudgetPlanningController extends Controller
{
    public function __construct(private readonly BudgetPlanningService $planning)
    {
    }

    /** The preparation workspace: roll a year over, or bring one in from a file. */
    public function index(): Response
    {
        $this->authorize('create', Budget::class);

        $years = FinancialYear::withCount('periods')
            ->orderByDesc('is_current')->orderByDesc('start_date')
            ->get(['id', 'name', 'start_date', 'end_date', 'status', 'is_current']);

        return Inertia::render('Modules/Budget/Planning/Index', [
            'financialYears' => $years->map(fn (FinancialYear $y) => [
                'id'         => $y->id,
                'name'       => $y->name,
                'start_date' => $y->start_date?->toDateString(),
                'end_date'   => $y->end_date?->toDateString(),
                'status'     => $y->status,
                'is_current' => (bool) $y->is_current,
                'periods'    => $y->periods_count,
                'budgets'    => Budget::where('financial_year_id', $y->id)->count(),
                'allocated'  => round((float) Budget::where('financial_year_id', $y->id)->sum('allocated_amount'), 2),
            ])->all(),
            // What each column means and what it will accept, shown on the page rather than in a
            // manual nobody has. An import that fails because a column is called "Amount" instead
            // of "allocated_amount" is a support ticket the page could have prevented.
            'importColumns' => [
                ['key' => 'name',             'required' => true],
                ['key' => 'allocated_amount', 'required' => true],
                ['key' => 'expenditure_type', 'required' => false, 'values' => ['capex', 'opex']],
                ['key' => 'category',         'required' => false],
                ['key' => 'scope_type',       'required' => false, 'values' => ['department', 'project', 'category', 'general']],
                ['key' => 'scope_label',      'required' => false],
                ['key' => 'currency',         'required' => false],
                ['key' => 'description',      'required' => false],
            ],
            // The categories a file may name. Without this the writer is guessing at spellings, and
            // a guess that misses lands the budget under no category at all.
            'categories' => BudgetCategory::orderBy('name')->pluck('name')->all(),
            'importWarnings' => session('importWarnings', []),
        ]);
    }

    // ── Phasing ──────────────────────────────────────────────────────────────

    public function savePhasing(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate([
            'lines'             => ['required', 'array'],
            'lines.*.period_id' => ['required', 'integer'],
            'lines.*.amount'    => ['nullable', 'numeric', 'min:0'],
            'lines.*.note'      => ['nullable', 'string', 'max:255'],
        ]);

        $this->planning->setPhasing($budget, $data['lines']);

        return back()->with('success', __('budget_management.flash.phasing_saved'));
    }

    /** Twelve equal months, remainder on the last — the starting point most plans are edited from. */
    public function spreadPhasing(Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $this->planning->spreadEvenly($budget);

        return back()->with('success', __('budget_management.flash.phasing_spread'));
    }

    // ── Reforecast and variance explanations ─────────────────────────────────

    /** The owner's revised expectation for the periods still to come. */
    public function saveForecast(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate([
            'lines'             => ['required', 'array'],
            'lines.*.period_id' => ['required', 'integer'],
            'lines.*.amount'    => ['nullable', 'numeric', 'min:0'],
            'lines.*.note'      => ['nullable', 'string', 'max:500'],
        ]);

        $changed = $this->planning->setForecast($budget, $data['lines'], Auth::id());

        return back()->with('success', $changed
            ? "Forecast updated for {$changed} period(s)."
            : 'Nothing changed. Only open periods can be reforecast; closed periods count at actual.');
    }

    /** Why a closed period differs from its plan. */
    public function explainVariance(Request $request, Budget $budget, int $period): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate(['explanation' => ['required', 'string', 'min:5', 'max:2000']]);

        if (! $this->planning->explainVariance($budget, $period, $data['explanation'], Auth::id())) {
            return back()->with('error', 'Only a closed period of this budget\'s financial year can have its variance explained.');
        }

        return back()->with('success', 'Variance explanation saved.');
    }

    // ── Year end ─────────────────────────────────────────────────────────────

    public function carryForward(Request $request): RedirectResponse
    {
        $this->authorize('create', Budget::class);

        $data = $request->validate([
            'from_year_id' => ['required', 'integer', 'exists:financial_years,id'],
            'to_year_id'   => ['required', 'integer', 'different:from_year_id', 'exists:financial_years,id'],
            'mode'         => ['required', 'in:allocation,unspent'],
        ]);

        $result = $this->planning->carryForward(
            FinancialYear::findOrFail($data['from_year_id']),
            FinancialYear::findOrFail($data['to_year_id']),
            $data['mode'],
            Auth::id(),
        );

        return back()->with('success', __('budget_management.flash.carried_forward', [
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]));
    }

    /**
     * Close a financial year.
     *
     * Closing is a statement, not a cleanup: it stops new budgets being written against a year
     * whose numbers have been reported. Budgets already live inside it are left alone — a
     * commitment made in March still has to be invoiced in April, and closing the year does not
     * make that money go away.
     */
    public function closeYear(FinancialYear $financialYear): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $open = Budget::where('financial_year_id', $financialYear->id)
            ->whereIn('status', ['draft', 'pending'])
            ->count();

        if ($open > 0) {
            return back()->withErrors(['financial_year' => __('budget_management.planning.close_blocked', ['count' => $open])]);
        }

        $financialYear->update(['status' => 'closed', 'is_current' => false]);

        Budget::where('financial_year_id', $financialYear->id)
            ->whereIn('status', ['active', 'approved'])
            ->update(['status' => 'closed']);

        return back()->with('success', __('budget_management.flash.year_closed'));
    }

    // ── Import ───────────────────────────────────────────────────────────────

    public function import(Request $request): RedirectResponse
    {
        $this->authorize('create', Budget::class);

        $data = $request->validate([
            'financial_year_id' => ['required', 'integer', 'exists:financial_years,id'],
            'file'              => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $rows = $this->readCsv($request->file('file')->getRealPath());

        if ($rows === []) {
            return back()->withErrors(['file' => __('budget_management.planning.import_empty')]);
        }

        $result = $this->planning->import($rows, FinancialYear::findOrFail($data['financial_year_id']), Auth::id());

        // Partial success is reported as both halves. Saying only "12 created" hides the three that
        // did not, and those are the ones somebody has to go and fix. Warnings ride along in the
        // session so the page can show them without turning them into a failure.
        $response = back()
            ->with('success', __('budget_management.flash.imported', ['count' => $result['created']]))
            ->with('importWarnings', array_slice($result['warnings'], 0, 25));

        if ($result['errors']) {
            return $response->withErrors(['file' => implode(' ', array_slice($result['errors'], 0, 10))]);
        }

        return $response;
    }

    /**
     * The template, as a file rather than a list of column names on a page.
     *
     * A column reference somebody has to retype into a spreadsheet is one they will get wrong —
     * a header spelled "Allocated Amount " with a trailing space imports nothing and explains
     * nothing. Handing over the actual file removes that whole class of support ticket, and the
     * two example rows show what a good value looks like for every optional column.
     */
    public function template(): StreamedResponse
    {
        $this->authorize('create', Budget::class);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            // A BOM, so Excel opens it as UTF-8 rather than mangling the first header into
            // something the importer will not recognise when the file comes back.
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($this->planning->templateRows() as $row) {
                // `escape: ''` is the CSV-correct behaviour and PHP 8.4's coming default;
                // passing it explicitly keeps the stream clean of deprecation notices, which
                // would otherwise be written into the middle of the downloaded file.
                fputcsv($out, $row, escape: '');
            }

            fclose($out);
        }, 'budget-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Read a CSV into header-keyed rows.
     *
     * Headers are lower-cased and spaces become underscores, so "Allocated Amount" and
     * "allocated_amount" are the same column. People export from Excel; insisting on an exact
     * string is insisting they edit the file first.
     *
     * @return array<int, array<string, string|null>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        // Strip the BOM Excel writes, or the first header becomes "\xEF\xBB\xBFname" and no row
        // ever has a name.
        $first = fgets($handle);
        rewind($handle);

        if ($first !== false && str_starts_with($first, "\xEF\xBB\xBF")) {
            fseek($handle, 3);
        }

        $header = fgetcsv($handle, escape: '');

        if (! $header) {
            fclose($handle);

            return [];
        }

        $header = array_map(fn ($h) => str_replace(' ', '_', mb_strtolower(trim((string) $h))), $header);

        $rows = [];

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }

            // Pad or trim so a short row does not throw off array_combine and take the file with it.
            $line = array_pad(array_slice($line, 0, count($header)), count($header), null);
            $rows[] = array_combine($header, $line);
        }

        fclose($handle);

        return $rows;
    }
}
