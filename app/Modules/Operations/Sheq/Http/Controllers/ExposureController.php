<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\KpiService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hours worked — the denominator under every injury rate (SHEQ-DASH-4, SHEQ-REG-7).
 *
 * `KpiService::recordExposure()` existed and nothing called it, so `sheq_exposure_hours` stayed
 * empty on every installation. LTIFR, TRIR, DIFR, FIFR and the severity rate are all
 * `numerator ÷ hours × multiplier`, and with no hours there is nothing to divide by: the figures a
 * board is shown either cannot be computed or are computed against an estimate nobody entered.
 *
 * The screen's job is therefore narrow and unglamorous — twelve months of hours per site — and it
 * carries one piece of judgement with it. An hours figure keyed by hand and one extracted from
 * payroll are not the same evidence, so the source is recorded and a period holding any manual row
 * is marked estimated wherever its rates appear. A board pack must not present a guess and a
 * payroll extract as though they were interchangeable.
 */
class ExposureController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly KpiService $kpis) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $year = (int) ($request->integer('year') ?: Carbon::now()->year);

        return Inertia::render('Modules/Sheq/Exposure/Index', [
            'noCompany' => $companyId === null,
            'year' => $year,
            'years' => range(Carbon::now()->year, Carbon::now()->year - 4),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all(),
            'rows' => $companyId === null ? [] : $this->rows($companyId, $year),
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            // A site id from another tenant must read as "does not exist": `updateOrInsert` keys on
            // it, so an unchecked id would stamp this company's hours against a stranger's site.
            'site_id' => ['nullable', 'integer', function (string $attribute, $value, \Closure $fail) use ($companyId) {
                if ($value !== null && ! DB::table('sheq_sites')
                    ->where('company_id', $companyId)->where('id', $value)->exists()) {
                    $fail('That site does not exist in this company.');
                }
            }],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            // Zero is a real answer — a site that was shut for the month worked no hours, and
            // that is different from nobody having told us.
            'employee_hours' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'contractor_hours' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'employee_headcount' => ['nullable', 'integer', 'min:0'],
            'contractor_headcount' => ['nullable', 'integer', 'min:0'],
            /*
             * How the figure was arrived at.
             *
             * `PAYROLL` means extracted; `MANUAL` means somebody keyed it. The distinction is not
             * bookkeeping: a period holding any manual row is reported as estimated, so a rate
             * computed from a guess is never shown beside one computed from a payroll extract as
             * though they carried the same weight.
             */
            'source' => ['required', Rule::in(['MANUAL', 'PAYROLL', 'TIME_ATTENDANCE', 'IMPORT'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->kpis->recordExposure($companyId, $validated, $request->user()?->id);

        return back()->with('success', 'Hours recorded. The injury rates for that month now have a denominator.');
    }

    /**
     * A year of months, with the gaps shown as gaps.
     *
     * Every month is listed whether or not anything was captured, because a missing month is the
     * thing somebody needs to see: it is a month whose rates cannot be computed, and an absence is
     * invisible if only the rows that exist are listed.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(int $companyId, int $year): array
    {
        $captured = DB::table('sheq_exposure_hours as e')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'e.site_id')
            ->where('e.company_id', $companyId)->where('e.period_year', $year)
            ->whereNull('e.deleted_at')
            ->get([
                'e.uuid', 'e.period_month', 'e.site_id', 'e.employee_hours', 'e.contractor_hours',
                'e.employee_headcount', 'e.contractor_headcount', 'e.source', 'e.notes',
                's.name as site_name',
            ])
            ->groupBy('period_month');

        $thisYear = $year === Carbon::now()->year;

        return collect(range(1, 12))
            // A month that has not happened yet is not a gap.
            ->filter(fn (int $month) => ! $thisYear || $month <= Carbon::now()->month)
            ->map(function (int $month) use ($captured, $year) {
                $rows = $captured->get($month, collect());

                return [
                    'period_month' => $month,
                    'label' => Carbon::createFromDate($year, $month, 1)->format('F'),
                    'entries' => $rows->map(fn ($r) => (array) $r)->values()->all(),
                    'employee_hours' => (float) $rows->sum('employee_hours'),
                    'contractor_hours' => (float) $rows->sum('contractor_hours'),
                    'total_hours' => (float) ($rows->sum('employee_hours') + $rows->sum('contractor_hours')),
                    'captured' => $rows->isNotEmpty(),
                    // The same rule the rates apply, so the screen and the board pack agree.
                    'estimated' => $rows->isEmpty() || $rows->contains('source', 'MANUAL'),
                ];
            })
            ->values()
            ->all();
    }
}
