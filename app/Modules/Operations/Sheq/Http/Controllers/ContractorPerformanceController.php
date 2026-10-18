<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\ContractorPerformanceService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a contractor's safety record actually is, and how the number was arrived at (CON-20, CON-21).
 *
 * The score existed and could not be explained. A contractor told their score is 61 asks why, and
 * the only answer available was to read the source code. That is not a pedantic objection: these
 * figures decide who gets work, they get disputed in meetings, and a number that cannot show its
 * working loses to whoever argues hardest — which defeats the whole point of counting instead of
 * rating by hand.
 *
 * So the page leads with the arithmetic: every factor, how many of them there were, what each one
 * costs, and what that came to. Then the trend, because one month is an anecdote and a renewal
 * decision is about the direction.
 */
class ContractorPerformanceController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly ContractorPerformanceService $performance) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $period = $this->period($request);

        $contractors = $companyId === null ? [] : DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->get(['id', 'uuid', 'name'])->map(fn ($c) => (array) $c)->all();

        $selected = $this->selected($request, $contractors);

        return Inertia::render('Modules/Sheq/Registers/ContractorPerformance', [
            'noCompany' => $companyId === null,
            'period' => $period,
            'periods' => $this->periods(),
            'contractors' => array_map(
                fn (array $c) => ['uuid' => $c['uuid'], 'name' => $c['name']], $contractors),
            'selected' => $selected === null ? null
                : ['uuid' => $selected['uuid'], 'name' => $selected['name']],
            /*
             * The factor catalogue, including the one that cannot be counted. A host reading a
             * score needs to know what is NOT in it — a missing factor they assume is included is
             * worse than one they know is absent.
             */
            'factors' => collect(ContractorPerformanceService::FACTORS)
                ->map(fn (array $f, string $key) => $f + ['key' => $key])->values()->all(),
            'weights' => $companyId === null ? [] : $this->performance->weightsFor($companyId),
            'leaderboard' => $companyId === null ? []
                : $this->performance->leaderboard($companyId, $period),
            'history' => $selected === null || $companyId === null ? []
                : $this->performance->history($companyId, (int) $selected['id'], 24),
            'comparison' => $selected === null || $companyId === null ? null
                : $this->performance->comparison($companyId, (int) $selected['id'], $period),
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
        ]);
    }

    /** Recompute the month on demand, for every contractor. */
    public function recompute(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $period = $this->period($request);

        $count = $this->performance->computeAll($companyId,
            Carbon::createFromFormat('Y-m', $period)->startOfMonth());

        return back()->with('success', "Recounted {$count} contractors for {$period}.");
    }

    /** Change what each factor costs (CON-20's configurable factors). */
    public function updateWeights(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'weights' => ['present', 'array'],
            'weights.*' => ['integer', 'min:0', 'max:50'],
        ]);

        $saved = $this->performance->saveWeights($companyId, $data['weights'],
            $request->user()?->id);

        /*
         * Not recomputed automatically, and that is deliberate. Reweighting rewrites what every
         * past month MEANS, and a host should be able to change a weight, see what it does to
         * this month, and decide — rather than silently restating two years of history the
         * moment they move a slider.
         */
        return back()->with('success', 'Saved. Recount a period to apply the new weights to it.');
    }

    private function period(Request $request): string
    {
        $period = (string) $request->query('period', Carbon::today()->format('Y-m'));

        return preg_match('/^\d{4}-\d{2}$/', $period) === 1
            ? $period
            : Carbon::today()->format('Y-m');
    }

    /** The last two years of months, newest first. */
    private function periods(int $months = 24): array
    {
        $month = Carbon::today()->startOfMonth();

        return collect(range(0, $months - 1))
            ->map(fn (int $back) => $month->copy()->subMonths($back)->format('Y-m'))->all();
    }

    /** @param  list<array<string,mixed>>  $contractors */
    private function selected(Request $request, array $contractors): ?array
    {
        $uuid = $request->query('contractor');

        foreach ($contractors as $contractor) {
            if ($contractor['uuid'] === $uuid) {
                return $contractor;
            }
        }

        return $contractors[0] ?? null;
    }
}
