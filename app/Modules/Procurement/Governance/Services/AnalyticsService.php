<?php

namespace App\Modules\Procurement\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-module analytics (§3.1, §3.4): the questions a head of procurement is actually asked.
 *
 * ReportingService answers "how much did we spend, with whom, and how fast". That is the finance
 * view and it was built first for good reason. It cannot answer the governance questions — how
 * competitive were our tenders, where do approvals stall, how often do we deviate from the normal
 * route, and is any of it getting better or worse — because those live in tables it never reads.
 *
 * Three principles are load-bearing here, and each exists because the obvious alternative misleads.
 *
 * **Every rate carries its denominator.** A single-bid rate of 40% means something entirely
 * different over five tenders than over five hundred, and a percentage presented without its base is
 * a rumour. Every rate this class returns travels with the counts it was derived from.
 *
 * **Durations are medians, not averages.** One tender that sat unopened over a festive season drags
 * a mean so far that the number stops describing anything. The median says what normally happens,
 * which is the question being asked; the mean is reported alongside it precisely so a large gap
 * between the two is visible rather than hidden.
 *
 * **A measure that cannot be computed answers null, not zero.** Zero deviations and no deviation
 * data are opposite findings, and a dashboard that renders them identically will eventually be used
 * to claim a clean bill of health nobody earned.
 */
class AnalyticsService
{
    /**
     * The procurement funnel: what was planned, what reached the market, what was awarded (§3.1).
     *
     * Counts rather than money, because the attrition between these stages is the finding. A large
     * drop between planned and tendered is a planning problem; a drop between tendered and awarded
     * is a market or specification problem, and they have different owners.
     *
     * @return array<string, mixed>
     */
    public function pipeline(int $companyId, Carbon $from, Carbon $to): array
    {
        $plans = DB::table('procgov_plans')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $planItems = DB::table('procgov_plan_items as i')
            ->join('procgov_plans as p', 'p.id', '=', 'i.plan_id')
            ->where('i.company_id', $companyId)
            ->whereBetween('i.created_at', [$from, $to])
            ->count();

        $records = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);

        $tenders = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to]);

        return [
            'plans' => $plans,
            'plan_items' => $planItems,
            'records_opened' => (clone $records)->count(),
            'tenders' => (clone $tenders)->count(),
            'tenders_awarded' => (clone $tenders)->whereNotNull('awarded_at')->count(),
            'records_closed' => (clone $records)->whereNotNull('closed_at')->count(),

            /*
             * Planned procurement that actually reached the market.
             *
             * Null rather than zero when nothing was planned: "we executed none of our plan" and "we
             * had no plan" are different failures and only one of them is about execution.
             */
            'plan_to_market_pct' => $planItems > 0
                ? round(((clone $tenders)->whereNotNull('plan_item_id')->count() / $planItems) * 100, 1)
                : null,
        ];
    }

    /**
     * Competition (§3.3): the measure that most often exposes a captured procurement.
     *
     * A tender attracting one bid is not illegal and is sometimes unavoidable, but a *pattern* of
     * them is the single clearest signal in this dataset — and it is invisible in spend reporting,
     * where a one-bid award and a ten-bid award look identical.
     *
     * @return array<string, mixed>
     */
    public function competition(int $companyId, Carbon $from, Carbon $to): array
    {
        $counts = DB::table('procgov_tender_reviews as r')
            ->leftJoin('procgov_bidders as b', function ($join) {
                $join->on('b.review_id', '=', 'r.id')->whereNull('b.deleted_at');
            })
            ->where('r.company_id', $companyId)
            ->whereNull('r.deleted_at')
            ->whereBetween('r.created_at', [$from, $to])
            ->groupBy('r.id')
            ->select('r.id', DB::raw('count(b.id) as bidders'))
            ->pluck('bidders')
            ->map(fn ($n) => (int) $n)
            ->all();

        $withBids = array_values(array_filter($counts, fn ($n) => $n > 0));
        $tenders = count($counts);

        $disqualified = DB::table('procgov_bidders as b')
            ->join('procgov_tender_reviews as r', 'r.id', '=', 'b.review_id')
            ->where('b.company_id', $companyId)
            ->whereNull('b.deleted_at')
            ->whereBetween('r.created_at', [$from, $to]);

        $totalBidders = (clone $disqualified)->count();

        return [
            'tenders' => $tenders,
            'tenders_with_no_bids' => $tenders - count($withBids),
            'single_bid_tenders' => count(array_filter($counts, fn ($n) => $n === 1)),

            // Deliberately measured against tenders that received anything at all: including
            // no-bid tenders in the denominator would understate the concentration among those
            // that did attract interest.
            'single_bid_pct' => $withBids !== []
                ? round((count(array_filter($withBids, fn ($n) => $n === 1)) / count($withBids)) * 100, 1)
                : null,

            'median_bidders' => $this->median($withBids),
            'average_bidders' => $withBids !== [] ? round(array_sum($withBids) / count($withBids), 2) : null,

            'bidders' => $totalBidders,
            'disqualified' => (clone $disqualified)->where('b.is_disqualified', true)->count(),
            'disqualified_pct' => $totalBidders > 0
                ? round(((clone $disqualified)->where('b.is_disqualified', true)->count() / $totalBidders) * 100, 1)
                : null,
            'late_bids' => (clone $disqualified)->where('b.is_late', true)->count(),
        ];
    }

    /**
     * Electronic intake (§1.1): how many invitations turn into bids, and how many arrive late.
     *
     * A low conversion is worth knowing before the committee sits — it usually means the tender
     * documents were unclear or the window was too short, both of which are fixable next time and
     * neither of which shows up anywhere else.
     *
     * @return array<string, mixed>|null null when electronic intake is not installed
     */
    public function intake(int $companyId, Carbon $from, Carbon $to): ?array
    {
        if (! Schema::hasTable('procgov_bid_invitations')) {
            return null;
        }

        $invitations = DB::table('procgov_bid_invitations')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $submissions = DB::table('procgov_bid_submissions')
            ->where('company_id', $companyId)
            ->whereBetween('submitted_at', [$from, $to]);

        $submitted = (clone $submissions)->count();

        return [
            'invitations' => $invitations,
            'submissions' => $submitted,
            'conversion_pct' => $invitations > 0 ? round(($submitted / $invitations) * 100, 1) : null,
            'late' => (clone $submissions)->where('is_late', true)->count(),
            'late_pct' => $submitted > 0
                ? round(((clone $submissions)->where('is_late', true)->count() / $submitted) * 100, 1)
                : null,
            'awaiting_opening' => (clone $submissions)->whereNull('opened_at')->count(),
        ];
    }

    /**
     * Deviation intensity (§5): how often the organisation leaves the normal route.
     *
     * Expressed against procurements opened in the same window rather than as a bare count, because
     * thirty deviations against a thousand procurements and thirty against forty are opposite
     * findings and a count alone cannot distinguish them.
     *
     * @return array<string, mixed>
     */
    public function deviations(int $companyId, Carbon $from, Carbon $to): array
    {
        $deviations = DB::table('procgov_deviations')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to]);

        $total = (clone $deviations)->count();

        $records = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to])
            ->count();

        $turnaround = (clone $deviations)
            ->whereNotNull('submitted_at')
            ->whereNotNull('decided_at')
            ->get(['submitted_at', 'decided_at'])
            ->map(fn ($d) => Carbon::parse($d->submitted_at)->floatDiffInDays(Carbon::parse($d->decided_at)))
            ->all();

        return [
            'total' => $total,
            'records_opened' => $records,
            'rate_pct' => $records > 0 ? round(($total / $records) * 100, 1) : null,

            'approved' => (clone $deviations)->where('status', 'APPROVED')->count(),
            'rejected' => (clone $deviations)->where('status', 'REJECTED')->count(),
            'pending' => (clone $deviations)->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW'])->count(),

            'high_risk' => (clone $deviations)->where('risk_level', 'HIGH')->count(),

            'median_turnaround_days' => $this->median($turnaround),

            'by_type' => (clone $deviations)
                ->select('deviation_type_code', DB::raw('count(*) as n'), DB::raw('coalesce(sum(procurement_value),0) as value'))
                ->groupBy('deviation_type_code')
                ->orderByDesc('n')
                ->get()
                ->map(fn ($r) => [
                    'code' => $r->deviation_type_code ?: 'UNSPECIFIED',
                    'count' => (int) $r->n,
                    'value' => (float) $r->value,
                ])
                ->all(),
        ];
    }

    /**
     * Where approvals stall (§4.4).
     *
     * The slowest *step* matters more than the slowest chain: a chain is slow because one seat in it
     * is slow, and only the step-level view names the seat. Reported per step definition so a
     * bottleneck is actionable — "legal review takes eleven days" is a finding somebody can own.
     *
     * @return array<string, mixed>
     */
    public function approvals(int $companyId, Carbon $from, Carbon $to): array
    {
        $requests = DB::table('procgov_approval_requests')
            ->where('company_id', $companyId)
            ->whereBetween('requested_at', [$from, $to]);

        $completed = (clone $requests)
            ->whereNotNull('completed_at')
            ->get(['requested_at', 'completed_at'])
            ->map(fn ($r) => Carbon::parse($r->requested_at)->floatDiffInDays(Carbon::parse($r->completed_at)))
            ->all();

        $pending = (clone $requests)->where('status', 'PENDING');

        $steps = DB::table('procgov_approval_steps as s')
            ->join('procgov_approval_requests as r', 'r.id', '=', 's.request_id')
            ->where('s.company_id', $companyId)
            ->whereBetween('r.requested_at', [$from, $to])
            ->whereNotNull('s.activated_at')
            ->whereNotNull('s.decided_at')
            ->get(['s.name', 's.activated_at', 's.decided_at', 's.due_at']);

        $byStep = [];

        foreach ($steps as $step) {
            $days = Carbon::parse($step->activated_at)->floatDiffInDays(Carbon::parse($step->decided_at));
            $byStep[$step->name][] = [
                'days' => $days,
                'breached' => $step->due_at !== null && Carbon::parse($step->decided_at)->greaterThan(Carbon::parse($step->due_at)),
            ];
        }

        $slowest = [];

        foreach ($byStep as $name => $rows) {
            $slowest[] = [
                'step' => $name,
                'decisions' => count($rows),
                'median_days' => $this->median(array_column($rows, 'days')),
                'breached' => count(array_filter($rows, fn ($r) => $r['breached'])),
            ];
        }

        usort($slowest, fn ($a, $b) => ($b['median_days'] ?? 0) <=> ($a['median_days'] ?? 0));

        return [
            'raised' => (clone $requests)->count(),
            'completed' => count($completed),
            'pending' => (clone $pending)->count(),

            // Past its service level and still undecided — the queue somebody has to clear today.
            'overdue' => (clone $pending)->whereNotNull('due_at')->where('due_at', '<', Carbon::now())->count(),

            'median_days_to_decide' => $this->median($completed),
            'average_days_to_decide' => $completed !== [] ? round(array_sum($completed) / count($completed), 2) : null,

            'slowest_steps' => array_slice($slowest, 0, 8),
        ];
    }

    /**
     * Award distribution by preference band, jurisdiction and currency (§1.6).
     *
     * The question a preference framework exists to answer, and one no spend report can: not how
     * much was spent, but who it went to and under which rules.
     *
     * @return array<string, mixed>
     */
    public function awards(int $companyId, Carbon $from, Carbon $to): array
    {
        $winners = DB::table('procgov_bidders as b')
            ->join('procgov_tender_reviews as r', 'r.id', '=', 'b.review_id')
            ->where('b.company_id', $companyId)
            ->whereNull('b.deleted_at')
            ->where('b.overall_rank', 1)
            ->where('b.is_disqualified', false)
            ->whereNotNull('r.awarded_at')
            ->whereBetween('r.awarded_at', [$from, $to]);

        $byLevel = (clone $winners)
            ->select('b.bbbee_level', DB::raw('count(*) as n'))
            ->groupBy('b.bbbee_level')
            ->orderBy('b.bbbee_level')
            ->get()
            ->map(fn ($r) => ['level' => $r->bbbee_level, 'count' => (int) $r->n])
            ->all();

        $byJurisdiction = Schema::hasColumn('procgov_tender_reviews', 'jurisdiction')
            ? (clone $winners)
                ->select('r.jurisdiction', DB::raw('count(*) as n'))
                ->groupBy('r.jurisdiction')
                ->get()
                ->map(fn ($r) => ['jurisdiction' => $r->jurisdiction ?: 'ZA', 'count' => (int) $r->n])
                ->all()
            : [];

        return [
            'awarded' => (clone $winners)->count(),
            'by_preference_level' => $byLevel,
            'by_jurisdiction' => $byJurisdiction,

            'by_currency' => (clone $winners)
                ->select('b.currency', DB::raw('count(*) as n'))
                ->groupBy('b.currency')
                ->get()
                ->map(fn ($r) => ['currency' => $r->currency ?: '—', 'count' => (int) $r->n])
                ->all(),
        ];
    }

    /**
     * Monthly trend for the measures worth watching over time (§3.4).
     *
     * A single period total says where you are; only a series says which way you are going, and the
     * direction is usually the actionable half. Months rather than weeks because procurement volumes
     * at most organisations make a weekly series mostly noise.
     *
     * @return list<array<string, mixed>>
     */
    public function trend(int $companyId, Carbon $from, Carbon $to): array
    {
        $months = [];
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->endOfMonth();

        // Bounded: a mis-entered financial year should not ask the database for four hundred months.
        while ($cursor->lessThanOrEqualTo($end) && count($months) < 36) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();

            $months[] = [
                'month' => $monthStart->format('Y-m'),
                'label' => $monthStart->format('M Y'),
                'records_opened' => DB::table('procgov_records')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->whereBetween('opened_at', [$monthStart, $monthEnd])->count(),
                'tenders' => DB::table('procgov_tender_reviews')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->whereBetween('created_at', [$monthStart, $monthEnd])->count(),
                'deviations' => DB::table('procgov_deviations')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->whereBetween('created_at', [$monthStart, $monthEnd])->count(),
                'awarded_value' => (float) DB::table('procgov_records')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->whereBetween('opened_at', [$monthStart, $monthEnd])->sum('awarded_value'),
            ];

            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * The headline numbers, and the ones that should make somebody act today.
     *
     * @return array<string, mixed>
     */
    public function headlines(int $companyId, Carbon $from, Carbon $to): array
    {
        $competition = $this->competition($companyId, $from, $to);
        $approvals = $this->approvals($companyId, $from, $to);
        $deviations = $this->deviations($companyId, $from, $to);

        return [
            'single_bid_pct' => $competition['single_bid_pct'],
            // The denominator travels with the rate, so the screen can print "40% of 5" rather than
            // a naked percentage that reads the same over five tenders as over five hundred.
            'single_bid_of' => $competition['tenders'] - $competition['tenders_with_no_bids'],
            'deviation_rate_pct' => $deviations['rate_pct'],
            'overdue_approvals' => $approvals['overdue'],
            'median_approval_days' => $approvals['median_days_to_decide'],
        ];
    }

    /**
     * The middle value, or null for an empty set.
     *
     * Null rather than 0 because zero days to decide is a real and very different claim from having
     * nothing to measure.
     *
     * @param  list<float|int>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;

        return round((float) $median, 2);
    }
}
