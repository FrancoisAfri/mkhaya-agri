<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quality, counted (QLT-8).
 *
 * Six figures, and the one that matters most is the one people least expect: the first-pass rate.
 * NCR counts and rework costs are lagging — they tell you what has already been paid for. The
 * first-pass rate is the share of inspections that passed without a non-conformance, and it moves
 * before the cost does, which makes it the only figure here that can change a decision while the
 * decision is still open.
 *
 * Repeat defects are counted on the defect CODE, never on the words. "Honeycombing on the column
 * again" is unfindable prose; the same defect against a code, a location and a contractor is
 * countable. That is the whole reason the vocabulary exists, and a repeat-defect figure computed
 * from free text would be confidently wrong.
 */
class QualityAnalytics
{
    /**
     * Everything the requirement lists, for a period.
     *
     * One method rather than six endpoints, because a quality manager reads them together: the
     * NCR count is meaningless without the inspection count behind it, and the rework cost is
     * meaningless without knowing whose it was.
     *
     * @return array<string,mixed>
     */
    public function summary(int $companyId, ?Carbon $from = null, ?Carbon $to = null,
        ?int $siteId = null): array
    {
        $from ??= Carbon::today()->subMonths(3);
        $to ??= Carbon::now();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'ncrs' => $this->ncrCounts($companyId, $from, $to, $siteId),
            'repeat_defects' => $this->repeatDefects($companyId, $from, $to, $siteId),
            'inspections' => $this->inspectionRates($companyId, $from, $to, $siteId),
            'rework' => $this->reworkTotals($companyId, $from, $to, $siteId),
            'by_contractor' => $this->contractorScores($companyId, $from, $to, $siteId),
            'by_discipline' => $this->byDiscipline($companyId, $from, $to, $siteId),
            'by_month' => $this->byMonth($companyId, $from, $to, $siteId),
        ];
    }

    /** @return array<string,int> */
    private function ncrCounts(int $companyId, Carbon $from, Carbon $to, ?int $siteId): array
    {
        $base = fn () => DB::table('sheq_ncrs')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('raised_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId));

        $open = [QualityService::OPEN, QualityService::IN_PROGRESS,
            QualityService::AWAITING_VERIFICATION];

        return [
            'total' => (int) $base()->count(),
            'open' => (int) $base()->whereIn('status', $open)->count(),
            'overdue' => (int) $base()->whereIn('status', $open)
                ->whereNotNull('due_date')->where('due_date', '<', Carbon::today())->count(),
            'critical' => (int) $base()->where('severity', QualityService::CRITICAL)->count(),
            'major' => (int) $base()->where('severity', QualityService::MAJOR)->count(),
            'closed' => (int) $base()->where('status', QualityService::CLOSED)->count(),
            /*
             * Closed by somebody other than whoever did the work. Counted and shown because
             * QLT-7's rule is only worth having if a host can see it holding — a rule nobody can
             * audit is a rule that quietly stops applying.
             */
            'independently_verified' => (int) $base()
                ->where('status', QualityService::CLOSED)
                ->whereNotNull('verified_by')->whereNotNull('corrected_by')
                ->whereColumn('verified_by', '!=', 'corrected_by')->count(),
        ];
    }

    /**
     * Defects seen more than once, worst first.
     *
     * The figure that distinguishes a quality problem from a quality incident. One failed cube is
     * a bad pour; the same failure eleven times is a batching plant, a supplier or a method, and
     * nothing in a list of individual NCRs will ever say so.
     *
     * @return list<array<string,mixed>>
     */
    private function repeatDefects(int $companyId, Carbon $from, Carbon $to, ?int $siteId): array
    {
        return DB::table('sheq_ncrs as n')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'n.contractor_id')
            ->where('n.company_id', $companyId)->whereNull('n.deleted_at')
            ->whereNotNull('n.defect_code')
            ->whereBetween('n.raised_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('n.site_id', $siteId))
            ->groupBy('n.defect_code', 'n.discipline')
            ->havingRaw('count(*) > 1')
            ->orderByRaw('count(*) desc')
            ->selectRaw('n.defect_code, n.discipline, count(*) as total,
                count(distinct n.contractor_id) as contractors,
                count(distinct n.location_text) as locations,
                sum(case when n.severity in (?, ?) then 1 else 0 end) as high_severity',
                [QualityService::MAJOR, QualityService::CRITICAL])
            ->limit(25)->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Inspection pass rate and first-pass rate.
     *
     * They are different and the difference is the point. The PASS rate is how many inspections
     * were marked passed. The FIRST-PASS rate is how many passed with no non-conformance raised
     * against them — which catches the inspection recorded as a pass with three NCRs attached,
     * and that combination is extremely common because an inspector passes the element and
     * raises the defects separately.
     *
     * @return array<string,mixed>
     */
    private function inspectionRates(int $companyId, Carbon $from, Carbon $to,
        ?int $siteId): array
    {
        $completed = DB::table('sheq_inspections')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId));

        $total = (int) (clone $completed)->count();
        $passed = (int) (clone $completed)->where('passed', true)->count();

        $withNcr = (int) (clone $completed)
            ->whereIn('id', DB::table('sheq_ncrs')
                ->where('company_id', $companyId)->whereNotNull('inspection_id')
                ->select('inspection_id'))
            ->count();

        return [
            'completed' => $total,
            'passed' => $passed,
            // Null rather than nought where nothing was inspected: "no inspections" and "nothing
            // passed" are different, and a rate of 0% reads as the second.
            'pass_rate' => $total === 0 ? null : (int) round($passed / $total * 100),
            'with_ncr' => $withNcr,
            'first_pass_rate' => $total === 0 ? null
                : (int) round(max(0, $total - $withNcr) / $total * 100),
        ];
    }

    /** @return array<string,mixed> */
    private function reworkTotals(int $companyId, Carbon $from, Carbon $to, ?int $siteId): array
    {
        $rows = DB::table('sheq_rework')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw('count(*) as events, coalesce(sum(cost_minor), 0) as cost_minor,
                coalesce(sum(hours), 0) as hours,
                sum(case when is_verified then 1 else 0 end) as verified')
            ->first();

        return [
            'events' => (int) $rows->events,
            'cost' => round(((int) $rows->cost_minor) / 100, 2),
            'hours' => round((float) $rows->hours, 2),
            'verified' => (int) $rows->verified,
            'unverified' => (int) $rows->events - (int) $rows->verified,
            'by_reason' => DB::table('sheq_rework')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
                ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
                ->whereNotNull('reason_code')
                ->groupBy('reason_code')->orderByRaw('count(*) desc')
                ->selectRaw('reason_code, count(*) as total,
                    coalesce(sum(cost_minor), 0) as cost_minor')
                ->get()->map(fn ($r) => [
                    'reason_code' => $r->reason_code,
                    'total' => (int) $r->total,
                    'cost' => round(((int) $r->cost_minor) / 100, 2),
                ])->all(),
        ];
    }

    /**
     * A quality score per contractor (QLT-8).
     *
     * Deliberately NOT merged into the safety score. They answer different questions asked by
     * different people — a safety manager decides whether a firm may be on site, a quantity
     * surveyor decides whether to use them again — and a single blended number would be the one
     * figure neither of them trusts. Shown side by side instead.
     *
     * Normalised by NCRs per inspection rather than per worker, because quality is a property of
     * the work inspected and not of headcount: a contractor who did three elements and failed
     * two is worse than one who did ninety and failed four, and a per-worker figure says the
     * opposite.
     *
     * @return list<array<string,mixed>>
     */
    private function contractorScores(int $companyId, Carbon $from, Carbon $to,
        ?int $siteId): array
    {
        $ncrs = DB::table('sheq_ncrs')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('contractor_id')
            ->whereBetween('raised_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->groupBy('contractor_id')
            ->selectRaw('contractor_id, count(*) as ncrs,
                sum(case when severity = ? then 1 else 0 end) as critical,
                sum(case when severity = ? then 1 else 0 end) as major,
                sum(case when status = ? and due_date < ? then 1 else 0 end) as overdue',
                [QualityService::CRITICAL, QualityService::MAJOR,
                    QualityService::OPEN, Carbon::today()->toDateString()])
            ->get()->keyBy('contractor_id');

        $rework = DB::table('sheq_rework')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('contractor_id')
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->groupBy('contractor_id')
            ->selectRaw('contractor_id, count(*) as events,
                coalesce(sum(cost_minor), 0) as cost_minor, coalesce(sum(hours), 0) as hours')
            ->get()->keyBy('contractor_id');

        $inspections = DB::table('sheq_inspections')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('subject_type', 'CONTRACTOR')->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->groupBy('subject_id')
            ->selectRaw('subject_id, count(*) as total,
                sum(case when passed then 1 else 0 end) as passed')
            ->get()->keyBy('subject_id');

        $ids = collect($ncrs->keys())->merge($rework->keys())->merge($inspections->keys())
            ->unique()->filter()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $names = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereIn('id', $ids)
            ->pluck('name', 'id');

        return $ids->map(function ($id) use ($ncrs, $rework, $inspections, $names) {
            $n = $ncrs->get($id);
            $w = $rework->get($id);
            $i = $inspections->get($id);

            $inspected = (int) ($i->total ?? 0);
            $ncrCount = (int) ($n->ncrs ?? 0);

            return [
                'contractor_id' => (int) $id,
                'name' => $names[$id] ?? 'Unknown',
                'ncrs' => $ncrCount,
                'critical' => (int) ($n->critical ?? 0),
                'major' => (int) ($n->major ?? 0),
                'overdue' => (int) ($n->overdue ?? 0),
                'rework_events' => (int) ($w->events ?? 0),
                'rework_cost' => round(((int) ($w->cost_minor ?? 0)) / 100, 2),
                'rework_hours' => round((float) ($w->hours ?? 0), 2),
                'inspections' => $inspected,
                'inspection_pass_rate' => $inspected === 0 ? null
                    : (int) round(((int) $i->passed) / $inspected * 100),
                'score' => $this->score($ncrCount, (int) ($n->critical ?? 0),
                    (int) ($n->major ?? 0), (int) ($w->events ?? 0), $inspected),
            ];
        })->sortBy(fn (array $r) => $r['score'] ?? 101)->values()->all();
    }

    /**
     * 100 down to 0, weighted by severity.
     *
     * Per inspection where there were inspections, and per NCR otherwise — a contractor nobody
     * inspected still has a count worth showing, and scoring them against zero inspections would
     * divide by nothing. Null where there is nothing at all to go on, because an unscored
     * contractor and one scoring 100 are different facts.
     */
    private function score(int $ncrs, int $critical, int $major, int $reworkEvents,
        int $inspections): ?float
    {
        if ($ncrs === 0 && $reworkEvents === 0 && $inspections === 0) {
            return null;
        }

        $penalty = ($ncrs - $critical - $major) * 2 + $major * 6 + $critical * 12
            + $reworkEvents * 4;

        $basis = $inspections > 0 ? $inspections : max(1, $ncrs);

        return round(max(0, 100 - ($penalty / $basis) * 10), 2);
    }

    /** @return list<array<string,mixed>> */
    private function byDiscipline(int $companyId, Carbon $from, Carbon $to, ?int $siteId): array
    {
        return DB::table('sheq_ncrs')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('discipline')
            ->whereBetween('raised_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->groupBy('discipline')->orderByRaw('count(*) desc')
            ->selectRaw('discipline, count(*) as total,
                sum(case when severity in (?, ?) then 1 else 0 end) as high_severity',
                [QualityService::MAJOR, QualityService::CRITICAL])
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    /** @return list<array<string,mixed>> */
    private function byMonth(int $companyId, Carbon $from, Carbon $to, ?int $siteId): array
    {
        return DB::table('sheq_ncrs')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('raised_at', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->groupByRaw("to_char(raised_at, 'YYYY-MM')")
            ->orderByRaw("to_char(raised_at, 'YYYY-MM')")
            ->selectRaw("to_char(raised_at, 'YYYY-MM') as period, count(*) as total")
            ->get()->map(fn ($r) => (array) $r)->all();
    }
}
