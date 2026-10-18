<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Regulatory\MetricConventions;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SHEQ KPIs (§5.11).
 *
 * Every frequency rate comes from the active regulatory pack's conventions (SHEQ-REG-7) — LTIFR per
 * million hours, DIFR per two hundred thousand, and whatever a future pack decides. Nothing here
 * knows what a multiplier should be.
 *
 * Three principles run through the numbers:
 *
 * **A rate with no exposure hours is null, never zero.** Zero renders on a dashboard as flawless
 * safety; null renders as "no data". The difference matters most in exactly the month somebody
 * forgot to upload payroll hours.
 *
 * **Leading indicators sit beside lagging ones.** Injury rates tell you about last month. The
 * near-miss ratio, hazard reporting rate and CAPA closure tell you about next month, which is the
 * only one anyone can still change.
 *
 * **Every metric carries its inputs.** A KPI whose numerator cannot be shown is not auditable, and
 * a board that cannot drill into a number stops trusting it.
 */
class KpiService
{
    /**
     * Compute every KPI for a period and store the snapshot (SHEQ-ARC-4).
     *
     * @return array{metrics:array<string,mixed>, inputs:array<string,mixed>, exposure_estimated:bool}
     */
    /**
     * Exposure sources that count as measured rather than estimated (INT-3).
     *
     * A whitelist, not a blacklist. An injury frequency rate is only as good as its denominator,
     * and the difference between 180,000 hours from a payroll extract and 180,000 hours somebody
     * typed is the difference between a measurement and a guess — which is a difference a board
     * and a regulator are entitled to see.
     *
     * `ATTENDANCE` is here because the gate register is a real count of who was on site, derived
     * from scans rather than asserted. `MANUAL` is deliberately absent.
     */
    public const MEASURED_SOURCES = ['PAYROLL', 'TIME_SYSTEM', 'ATTENDANCE', 'IMPORT', 'API'];

    public function computePeriod(int $companyId, int $year, int $month, ?int $siteId = null): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $pack = $this->activePack($companyId, $siteId, $end);
        $conventions = $pack ? new MetricConventions($pack) : null;

        $exposure = $this->exposure($companyId, $siteId, $year, $month);
        $incidents = $this->incidentCounts($companyId, $siteId, $start, $end, $conventions);
        $leading = $this->leadingIndicators($companyId, $siteId, $start, $end, $exposure, $incidents);

        $counters = $incidents + ['hours_worked' => $exposure['total_hours']];

        $rates = [];
        foreach ($conventions?->definitions() ?? [] as $definition) {
            $rates[$definition['id']] = [
                'value' => $conventions->compute($definition['id'], $counters),
                'label' => $definition['label'] ?? $definition['id'],
                'numerator' => $counters[$definition['numerator']] ?? 0,
                'denominator' => $counters[$definition['denominator']] ?? 0,
                'multiplier' => $definition['multiplier'] ?? 1,
            ];
        }

        $metrics = [
            'rates' => $rates,
            'lagging' => $incidents,
            'leading' => $leading,
        ];

        $inputs = [
            'period' => sprintf('%04d-%02d', $year, $month),
            'site_id' => $siteId,
            'exposure' => $exposure,
            'pack' => $pack ? $pack->packId.'@'.$pack->version : null,
        ];

        DB::table('sheq_kpi_snapshots')->updateOrInsert(
            ['company_id' => $companyId, 'site_id' => $siteId, 'period_year' => $year, 'period_month' => $month],
            [
                // PRESERVE_ZERO_FRACTION, or a rate of 2.0 is stored as "2" and read back as an
                // integer. A KPI that changes type on the way through the projection breaks strict
                // comparisons downstream and reads wrongly in an exported API payload.
                'metrics' => json_encode($metrics, JSON_PRESERVE_ZERO_FRACTION),
                'inputs' => json_encode($inputs, JSON_PRESERVE_ZERO_FRACTION),
                'regulatory_pack_id' => $pack?->packId,
                'regulatory_pack_version' => $pack?->version,
                'exposure_estimated' => $exposure['estimated'],
                // Stamped by the database, not PHP. The application runs UTC while this connection's
                // session runs Africa/Johannesburg, so a naive timestamp sent from PHP is stored two
                // hours early (SHEQ-ADR-005). Projection lag is meaningless if its own timestamp is
                // wrong, so the one clock that agrees with the comparison is the database's.
                'computed_at' => DB::raw('now()'),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $breaches = $this->publishThresholdBreaches($companyId, $siteId, $year, $month, $metrics);

        return ['metrics' => $metrics, 'inputs' => $inputs,
            'exposure_estimated' => $exposure['estimated'], 'breaches' => $breaches];
    }

    /**
     * Announce indicators that crossed their threshold (`sheq.risk.threshold_breached`).
     *
     * Emitted from the computation rather than from a dashboard read: a breach is a fact about the
     * period, and SHEQ-ARC-4 keeps writes off GET paths. `sheq:kpis:compute` is what runs this.
     *
     * Published once per metric per period. Recomputing a month — which happens whenever a late
     * incident is filed against it — must not re-announce a breach the escalation chain already
     * acted on, or the people receiving these learn to ignore them.
     *
     * @return list<array{metric:string, value:float, threshold:float, direction:string}>
     */
    private function publishThresholdBreaches(int $companyId, ?int $siteId, int $year,
        int $month, array $metrics): array
    {
        $thresholds = DB::table('sheq_kpi_thresholds')
            ->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')
            // A site inherits the company-wide threshold unless it has one of its own.
            ->where(function ($q) use ($siteId) {
                $q->whereNull('site_id');

                if ($siteId !== null) {
                    $q->orWhere('site_id', $siteId);
                }
            })
            ->get(['metric', 'direction', 'threshold_value', 'severity', 'site_id']);

        if ($thresholds->isEmpty()) {
            return [];
        }

        // A site-specific threshold wins over the company default for the same metric.
        $applicable = [];

        foreach ($thresholds as $threshold) {
            $existing = $applicable[$threshold->metric] ?? null;

            if ($existing === null || ($threshold->site_id !== null && $existing->site_id === null)) {
                $applicable[$threshold->metric] = $threshold;
            }
        }

        $values = $this->flattenMetrics($metrics);
        $breaches = [];
        $publisher = app(EventPublisher::class);
        $period = sprintf('%04d-%02d', $year, $month);

        foreach ($applicable as $metric => $threshold) {
            if (! array_key_exists($metric, $values)) {
                continue;
            }

            $value = (float) $values[$metric];
            $limit = (float) $threshold->threshold_value;

            // Leading indicators breach downwards. Assuming "above" would mean the early-warning
            // indicators — the ones worth watching — never fired at all.
            $breached = strtoupper($threshold->direction) === 'BELOW'
                ? $value < $limit
                : $value > $limit;

            if (! $breached) {
                continue;
            }

            $subjectId = $siteId ?? 0;

            $alreadySent = DB::table('sheq_outbox_messages')
                ->where('company_id', $companyId)
                ->where('event_type', 'sheq.risk.threshold_breached')
                ->where('subject_id', $subjectId)
                ->whereRaw("payload->>'metric' = ?", [$metric])
                ->whereRaw("payload->>'period' = ?", [$period])
                ->exists();

            if ($alreadySent) {
                continue;
            }

            DB::transaction(fn () => $publisher->publish($companyId,
                'sheq.risk.threshold_breached', 'SITE', $subjectId, [
                    'site_id' => $siteId,
                    'metric' => $metric,
                    'value' => $value,
                    'threshold' => $limit,
                    'direction' => strtoupper($threshold->direction),
                    'severity' => $threshold->severity,
                    'period' => $period,
                ]));

            $breaches[] = ['metric' => $metric, 'value' => $value,
                'threshold' => $limit, 'direction' => strtoupper($threshold->direction)];
        }

        return $breaches;
    }

    /**
     * Flatten the metric tree to `metric id => value`.
     *
     * Rates are nested under their own ids while lagging and leading counters are flat maps, and a
     * threshold is set against a name rather than against a path through that structure.
     *
     * @return array<string, float|int>
     */
    private function flattenMetrics(array $metrics): array
    {
        $flat = [];

        foreach ($metrics['rates'] ?? [] as $id => $rate) {
            $flat[$id] = $rate['value'] ?? 0;
        }

        foreach (['lagging', 'leading'] as $group) {
            foreach ($metrics[$group] ?? [] as $key => $value) {
                if (is_scalar($value)) {
                    $flat[$key] = $value;
                }
            }
        }

        return $flat;
    }

    /**
     * Read a stored snapshot. Dashboards call this, never computePeriod — that is the whole point
     * of SHEQ-ARC-4, and `computed_at` lets the UI show how stale the figure is.
     */
    public function snapshot(int $companyId, int $year, int $month, ?int $siteId = null): ?array
    {
        $row = DB::table('sheq_kpi_snapshots')
            ->where('company_id', $companyId)->where('period_year', $year)->where('period_month', $month)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId), fn ($q) => $q->whereNull('site_id'))
            ->first();

        if ($row === null) {
            return null;
        }

        // Lag is measured against the database's clock, not PHP's.
        //
        // This connection's session runs Africa/Johannesburg while the application runs UTC, so a
        // timestamp written by Laravel and compared to PHP's now() is out by the offset — which
        // would make every snapshot look two hours stale and quietly break the SHEQ-ARC-4 SLO.
        // Comparing a stored value against `now()` inside the same database sidesteps the mismatch
        // entirely. See the note in SHEQ-ADR-005 on the platform-wide timezone discrepancy.
        $lag = DB::selectOne(
            'select extract(epoch from (now() - computed_at))::int as seconds
             from sheq_kpi_snapshots where id = ?',
            [$row->id]
        );

        return [
            'metrics' => json_decode($row->metrics, true),
            'inputs' => json_decode($row->inputs, true),
            'pack' => $row->regulatory_pack_id ? $row->regulatory_pack_id.'@'.$row->regulatory_pack_version : null,
            'exposure_estimated' => (bool) $row->exposure_estimated,
            'computed_at' => $row->computed_at,
            'lag_seconds' => max(0, (int) ($lag->seconds ?? 0)),
        ];
    }

    /**
     * Lagging counts — what already happened.
     *
     * `recordable_cases` follows the pack's recordability rules rather than a fixed list, because
     * whether restricted work counts is a jurisdictional question, not a coding one.
     *
     * @return array<string,int>
     */
    private function incidentCounts(
        int $companyId, ?int $siteId, Carbon $start, Carbon $end, ?MetricConventions $conventions = null
    ): array {
        $incidents = DB::table('sheq_incidents')
            ->where('company_id', $companyId)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereBetween('occurred_at', [$start, $end])
            ->whereNull('deleted_at')
            ->get(['outcome', 'treatment_level', 'incident_type', 'days_lost', 'days_restricted',
                'is_reportable', 'statutory_category', 'cost_estimate_minor']);

        $counts = [
            'total_incidents' => $incidents->count(),
            'fatalities' => 0,
            'lost_time_injuries' => 0,
            'disabling_injuries' => 0,
            'medical_treatment' => 0,
            'restricted_work' => 0,
            'first_aid' => 0,
            'recordable_cases' => 0,
            'near_misses' => 0,
            'reportable' => 0,
            'days_lost' => 0,
            'cost_minor' => 0,
        ];

        $counts['permanent_disability'] = 0;

        foreach ($incidents as $incident) {
            $outcome = strtoupper((string) $incident->outcome);
            $treatment = strtoupper((string) $incident->treatment_level);

            $counts['days_lost'] += (int) $incident->days_lost;
            $counts['cost_minor'] += (int) $incident->cost_estimate_minor;
            $counts['reportable'] += $incident->is_reportable ? 1 : 0;
            $counts['near_misses'] += strtoupper((string) $incident->incident_type) === 'NEAR_MISS' ? 1 : 0;

            // One incident lands in exactly one severity bucket, most severe first, so nothing is
            // counted twice when both outcome and treatment level are populated.
            $bucket = match (true) {
                $outcome === 'FATALITY' => 'fatalities',
                $outcome === 'PERMANENT_DISABILITY' => 'permanent_disability',
                in_array('LOST_TIME', [$outcome, $treatment], true) => 'lost_time_injuries',
                in_array('RESTRICTED_WORK', [$outcome, $treatment], true) => 'restricted_work',
                in_array('MEDICAL_TREATMENT', [$outcome, $treatment], true) => 'medical_treatment',
                in_array('FIRST_AID', [$outcome, $treatment], true) => 'first_aid',
                default => null,
            };

            if ($bucket !== null) {
                $counts[$bucket]++;
            }
        }

        // Disabling injury, in the SA convention DIFR uses: anything that stopped someone working
        // or ended their ability to. Fatalities included — a fatality is the limiting case, and
        // excluding it would flatter the rate in the worst possible month.
        $counts['disabling_injuries'] = $counts['fatalities']
            + $counts['permanent_disability']
            + $counts['lost_time_injuries'];

        // Recordability is the pack's rule, not this file's (SHEQ-REG-7): whether restricted work
        // or first aid counts differs by jurisdiction. Falling back to the common convention only
        // when no pack is active.
        $recordableLevels = ['LOST_TIME' => 'lost_time_injuries',
            'MEDICAL_TREATMENT' => 'medical_treatment',
            'RESTRICTED_WORK' => 'restricted_work',
            'FIRST_AID' => 'first_aid'];

        $counts['recordable_cases'] = $counts['fatalities'] + $counts['permanent_disability'];

        foreach ($recordableLevels as $level => $key) {
            $recordable = $conventions ? $conventions->isRecordable($level) : $level !== 'FIRST_AID';

            if ($recordable) {
                $counts['recordable_cases'] += $counts[$key];
            }
        }

        return $counts;
    }

    /**
     * Leading indicators — what tells you about next month.
     *
     * @param  array<string,mixed>  $exposure
     * @return array<string,mixed>
     */
    private function leadingIndicators(
        int $companyId, ?int $siteId, Carbon $start, Carbon $end, array $exposure, array $incidents
    ): array {
        $site = fn ($query) => $siteId ? $query->where('site_id', $siteId) : $query;

        $hazards = $site(DB::table('sheq_hazards')->where('company_id', $companyId)
            ->whereBetween('reported_at', [$start, $end])->whereNull('deleted_at'))->count();

        $hazardsTriaged = $site(DB::table('sheq_hazards')->where('company_id', $companyId)
            ->whereBetween('reported_at', [$start, $end])->whereNotNull('triaged_at')
            ->whereNull('deleted_at'))->count();

        $actions = $site(DB::table('sheq_actions')->where('company_id', $companyId)
            ->whereNull('deleted_at'));
        $actionRows = $actions->get(['status', 'due_date', 'effectiveness_result', 'hierarchy_level']);

        $open = $actionRows->whereIn('status', ['OPEN', 'IN_PROGRESS', 'PENDING_VERIFICATION']);
        $overdue = $open->filter(fn ($a) => Carbon::parse($a->due_date)->lt(Carbon::today()));
        $reviewed = $actionRows->whereNotNull('effectiveness_result');
        $effective = $reviewed->where('effectiveness_result', 'EFFECTIVE');

        $inspections = $site(DB::table('sheq_inspections')->where('company_id', $companyId)
            ->whereBetween('completed_at', [$start, $end])->whereNull('deleted_at'))
            ->get(['passed', 'failed_count']);

        $obligations = DB::table('sheq_statutory_obligations')
            ->where('company_id', $companyId)
            ->whereBetween('due_at', [$start, $end])->whereNull('deleted_at')
            ->get(['status', 'due_at', 'submitted_at']);

        $obligationsMetOnTime = $obligations->filter(fn ($o) => $o->submitted_at !== null
            && Carbon::parse($o->submitted_at)->lte(Carbon::parse($o->due_at)))->count();

        $headcount = (int) ($exposure['employee_headcount'] ?? 0);

        return array_merge([
            // The ratio the specification calls out: near-misses per recordable. A falling ratio
            // usually means people stopped reporting, not that the site got safer.
            'near_miss_ratio' => $this->ratio($incidents['near_misses'], $incidents['recordable_cases']),
            'hazards_reported' => $hazards,
            'hazard_reporting_rate_per_100' => $headcount > 0
                ? round(($hazards / $headcount) * 100, 1) : null,
            'hazard_triage_rate' => $hazards > 0 ? round(($hazardsTriaged / $hazards) * 100, 1) : null,

            'actions_open' => $open->count(),
            'actions_overdue' => $overdue->count(),
            'actions_overdue_percent' => $open->count() > 0
                ? round(($overdue->count() / $open->count()) * 100, 1) : null,
            'capa_effectiveness_percent' => $reviewed->count() > 0
                ? round(($effective->count() / $reviewed->count()) * 100, 1) : null,
            // Where the only remedy was administrative or PPE — the hierarchy of control, as a number.
            'weak_control_actions' => $actionRows->whereIn('hierarchy_level', ['ADMINISTRATIVE', 'PPE'])->count(),

            'inspections_completed' => $inspections->count(),
            'inspection_pass_rate' => $inspections->count() > 0
                ? round(($inspections->where('passed', true)->count() / $inspections->count()) * 100, 1) : null,

            'obligations_due' => $obligations->count(),
            'obligations_met_on_time' => $obligationsMetOnTime,
            'obligations_on_time_percent' => $obligations->count() > 0
                ? round(($obligationsMetOnTime / $obligations->count()) * 100, 1) : null,
        ], $this->laterLeadingIndicators($companyId, $siteId, $start, $end, $headcount));
    }

    /**
     * The four leading indicators CMD-4 names that this method predates.
     *
     * Behavioural observations, contractor compliance, competency expiry and management of change
     * were all built after the KPI projection was, so the projection quietly stopped being the
     * place the leading indicators live — the command centre would have had to read four
     * registers directly and the two screens would have disagreed.
     *
     * Kept as a separate method only because the one above is already long; it is the same block.
     *
     * @return array<string,mixed>
     */
    private function laterLeadingIndicators(int $companyId, ?int $siteId, Carbon $start,
        Carbon $end, int $headcount): array
    {
        $site = fn ($query) => $siteId ? $query->where('site_id', $siteId) : $query;

        $observations = $site(DB::table('sheq_observations')->where('company_id', $companyId)
            ->whereBetween('observed_at', [$start, $end])->whereNull('deleted_at'))
            ->get(['observation_type', 'work_stopped']);

        // Derived from the types' own `concerning` flag, so a fifth kind is classified by its
        // declaration rather than by whether somebody remembered this query.
        $concerning = array_keys(array_filter(ObservationService::TYPES,
            fn (array $t) => $t['concerning']));

        $unsafe = $observations->whereIn('observation_type', $concerning)->count();

        /*
         * Contractors on site who may not be. Counted as a state at the end of the period rather
         * than as events during it, because "how many blocked contractors were working here" is
         * a question about a moment, and the honest answer at month end is the one a board asks
         * for.
         */
        $contractors = DB::table('sheq_contractors as c')
            ->where('c.company_id', $companyId)
            ->whereNull('c.deleted_at')->where('c.is_active', true)
            ->when($siteId, fn ($q) => $q->whereExists(fn ($e) => $e
                ->from('sheq_contractor_site_approvals as a')
                ->whereColumn('a.contractor_id', 'c.id')
                ->where('a.site_id', $siteId)->where('a.company_id', $companyId)))
            ->get(['c.access_status']);

        $blocked = $contractors->whereIn('access_status',
            ['BLOCKED', 'SUSPENDED', 'NON_COMPLIANT'])->count();

        /*
         * Competencies lapsing inside ninety days. A leading indicator because it is the only one
         * on this list that describes something that has not gone wrong yet and will on a known
         * date — which makes it the one a planner can actually act on.
         */
        $today = Carbon::today();

        $expiring = DB::table('sheq_competency_records as r')
            ->join('sheq_competencies as k', 'k.id', '=', 'r.competency_id')
            ->where('r.company_id', $companyId)
            ->whereNull('r.deleted_at')->whereNull('r.revoked_at')
            ->whereNotNull('r.achieved_at')
            ->whereNotNull('r.expires_at')
            ->whereBetween('r.expires_at', [$today, $today->copy()->addDays(90)])
            ->selectRaw('count(*) as total,
                count(*) filter (where k.blocks_work_when_expired) as blocking')
            ->first();

        $changes = DB::table('sheq_changes')
            ->where('company_id', $companyId)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereNull('deleted_at')
            ->get(['status', 'effectiveness_review_due', 'effectiveness_reviewed_at',
                'risk_rating']);

        /*
         * A change is finished when it has been reviewed, rejected or cancelled — NOT when it has
         * been implemented. That distinction is the whole of MOC-5: the period between a change
         * going live and somebody confirming it worked is precisely the window this indicator
         * exists to make visible, and counting IMPLEMENTED as closed would hide every change
         * sitting in it.
         */
        $finished = [ChangeManagementService::REVIEWED, ChangeManagementService::REJECTED,
            ChangeManagementService::CANCELLED];

        return [
            'observations_recorded' => $observations->count(),
            'unsafe_observations' => $unsafe,
            'observation_rate_per_100' => $headcount > 0
                ? round(($observations->count() / $headcount) * 100, 1) : null,
            // The proportion of observations that found something wrong. A programme reporting
            // 100% safe is a programme nobody is really doing.
            'unsafe_observation_percent' => $observations->count() > 0
                ? round(($unsafe / $observations->count()) * 100, 1) : null,
            'work_stopped_count' => $observations->where('work_stopped', true)->count(),

            'contractors_on_site' => $contractors->count(),
            'contractors_blocked' => $blocked,
            'contractor_compliance_percent' => $contractors->count() > 0
                ? round((($contractors->count() - $blocked) / $contractors->count()) * 100, 1)
                : null,

            'competencies_expiring_90d' => (int) ($expiring->total ?? 0),
            // The ones that stop work when they lapse, which is the number that decides whether
            // next month has a labour problem.
            'competencies_expiring_blocking_90d' => (int) ($expiring->blocking ?? 0),

            'changes_open' => $changes->whereNotIn('status', $finished)->count(),
            // Implemented, the review has fallen due, and nobody has done it. The number that
            // says how many live changes nobody has confirmed are safe.
            'changes_awaiting_review' => $changes
                ->filter(fn ($c) => $c->effectiveness_reviewed_at === null
                    && $c->effectiveness_review_due !== null
                    && Carbon::parse($c->effectiveness_review_due)->lte($today))->count(),
            'changes_high_risk_open' => $changes->whereNotIn('status', $finished)
                ->whereIn('risk_rating', ['HIGH', 'CRITICAL'])->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function exposure(int $companyId, ?int $siteId, int $year, int $month): array
    {
        $rows = DB::table('sheq_exposure_hours')
            ->where('company_id', $companyId)
            ->where('period_year', $year)->where('period_month', $month)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereNull('deleted_at')
            ->get(['employee_hours', 'contractor_hours', 'employee_headcount', 'contractor_headcount', 'source']);

        return [
            'employee_hours' => (float) $rows->sum('employee_hours'),
            'contractor_hours' => (float) $rows->sum('contractor_hours'),
            'total_hours' => (float) ($rows->sum('employee_hours') + $rows->sum('contractor_hours')),
            'employee_headcount' => (int) $rows->sum('employee_headcount'),
            'contractor_headcount' => (int) $rows->sum('contractor_headcount'),
            /*
             * A single untrusted row taints the period: a board pack must not present a guess and
             * a payroll extract as though they were the same thing (SHEQ-DASH-4, INT-3).
             *
             * Inverted deliberately. This used to look for `source = 'MANUAL'`, which meant a row
             * marked ESTIMATED — the exact case INT-3 describes, where a payroll or time system
             * supplies an estimate — was treated as measured, and every rate derived from it was
             * presented as a measurement.
             *
             * So trust is now a whitelist: only a source known to be a real extract counts as
             * measured, and anything else is an estimate. That fails in the safe direction. A new
             * import added later is labelled an estimate until somebody deliberately adds it to
             * `MEASURED_SOURCES`, rather than silently acquiring the authority of a payroll run.
             */
            'estimated' => $rows->isEmpty()
                || $rows->contains(fn ($row) => ! in_array(
                    strtoupper((string) ($row->source ?? '')), self::MEASURED_SOURCES, true)),
            'captured' => $rows->isNotEmpty(),
        ];
    }

    private function activePack(int $companyId, ?int $siteId, Carbon $at): ?PackManifest
    {
        $row = DB::table('sheq_regulatory_pack_activations as a')
            ->join('sheq_regulatory_pack_versions as v', 'v.id', '=', 'a.pack_version_id')
            ->where('a.company_id', $companyId)->where('a.status', 'ACCEPTED')
            ->whereNull('a.deactivated_at')
            ->when($siteId, fn ($q) => $q->where(fn ($w) => $w->where('a.site_id', $siteId)->orWhereNull('a.site_id')))
            ->where('v.effective_from', '<=', $at->toDateString())
            ->orderByRaw('a.site_id is null')->orderByDesc('v.effective_from')
            ->first(['v.manifest']);

        return $row ? PackManifest::fromArray(json_decode($row->manifest, true)) : null;
    }

    private function ratio(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 2) : null;
    }

    /** Record exposure hours for a period (SHEQ-DASH-4). */
    public function recordExposure(int $companyId, array $input, ?int $actorId = null): void
    {
        DB::table('sheq_exposure_hours')->updateOrInsert(
            [
                'company_id' => $companyId,
                'site_id' => $input['site_id'] ?? null,
                'period_year' => $input['period_year'],
                'period_month' => $input['period_month'],
            ],
            [
                'uuid' => (string) Str::uuid(),
                'employee_hours' => $input['employee_hours'] ?? 0,
                'contractor_hours' => $input['contractor_hours'] ?? 0,
                'employee_headcount' => $input['employee_headcount'] ?? null,
                'contractor_headcount' => $input['contractor_headcount'] ?? null,
                'source' => $input['source'] ?? 'MANUAL',
                'notes' => $input['notes'] ?? null,
                'captured_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
