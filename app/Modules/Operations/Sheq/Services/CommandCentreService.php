<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Compliance\ComplianceDecisionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The live SHEQ command centre (CMD-1 to CMD-7).
 *
 * This screen has one failure mode that matters more than any other, and it is not being wrong —
 * it is being **stale and confident**. A dashboard that silently shows last Tuesday's numbers is
 * read as the present and acted on as the present, which is why CMD-3 asks for data freshness and
 * why every block returned from here carries its own `as_at` rather than the page carrying one.
 * The permit count is live to the second; the injury rate is a monthly projection that may be
 * three weeks old; and presenting them under a single timestamp would make one of those two
 * statements a lie.
 *
 * Two other decisions worth stating:
 *
 * **Nothing here recomputes a KPI.** The leading and lagging indicators come from the projection
 * `sheq:kpis:compute` writes, and the site risk scores from the table `SiteRiskScoreService`
 * writes. A dashboard that computes its own figures is a second implementation of every rule in
 * the module, and the first time it disagrees with the report somebody printed, both become
 * unusable. Where this service queries a register directly it is for a count of things happening
 * NOW — open permits, people on site — which no projection can hold.
 *
 * **Every tile knows where it drills to.** CMD-2 asks for Group → Region → Site → Department →
 * Contractor → Worker → Evidence, and a tile that cannot say what its own number is made of is a
 * number nobody can act on. So each tile carries a `drill` key naming the route and the filter
 * that reproduces it, and `drill()` below is the one place that resolves a level to its rows.
 */
class CommandCentreService
{
    /**
     * The eleven things CMD-1 asks the main screen to show.
     *
     * Declared as data so the screen can lay them out, the test can walk the requirement's list,
     * and a tile added later cannot be one the drill-down has no case for.
     */
    public const TILES = [
        'critical_risks' => ['label' => 'Critical risks', 'tone' => 'error',
            'drill' => '/sheq/risk-intelligence'],
        'blocked_contractors' => ['label' => 'Blocked contractors', 'tone' => 'error',
            'drill' => '/sheq/contractors'],
        'non_compliant_workers' => ['label' => 'Non-compliant workers', 'tone' => 'error',
            'drill' => '/sheq/compliance'],
        'active_permits' => ['label' => 'Permits open now', 'tone' => 'warning',
            'drill' => '/sheq/permits'],
        'overdue_capa' => ['label' => 'Overdue CAPA', 'tone' => 'warning',
            'drill' => '/sheq/actions'],
        'statutory_deadlines' => ['label' => 'Statutory deadlines due', 'tone' => 'warning',
            'drill' => '/sheq/obligations'],
        'incidents' => ['label' => 'Incidents this month', 'tone' => 'error',
            'drill' => '/sheq/incidents'],
        'site_occupancy' => ['label' => 'People on site now', 'tone' => 'info',
            'drill' => '/sheq/attendance'],
        'inspections' => ['label' => 'Inspections this month', 'tone' => 'info',
            'drill' => '/sheq/inspections'],
        'audits' => ['label' => 'Audits open', 'tone' => 'info',
            'drill' => '/sheq/audits'],
        'environmental_breaches' => ['label' => 'Environmental breaches', 'tone' => 'warning',
            'drill' => '/sheq/environmental-registers'],
    ];

    /** The drill-down path CMD-2 names, in order. */
    public const LEVELS = ['GROUP', 'REGION', 'SITE', 'DEPARTMENT', 'CONTRACTOR', 'WORKER',
        'EVIDENCE'];

    public function __construct(
        private readonly KpiService $kpis,
        private readonly SiteRiskScoreService $siteRisk,
        private readonly RiskIntelligenceService $risk,
    ) {}

    /**
     * The main screen (CMD-1, CMD-3).
     *
     * @param  int|null  $siteId  Narrows every tile to one site. Null is the group view.
     */
    public function board(int $companyId, ?int $siteId = null): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $period = $now->format('Y-m');

        $site = fn ($query) => $siteId !== null ? $query->where('site_id', $siteId) : $query;

        $counts = [
            // The highest residual risks on an approved assessment. Not "risks" in general: a
            // register of two thousand accepted low risks is not a number anybody reads.
            'critical_risks' => (int) DB::table('sheq_risk_lines as l')
                ->join('sheq_risk_assessments as a', 'a.id', '=', 'l.risk_assessment_id')
                ->where('l.company_id', $companyId)->whereNull('l.deleted_at')
                ->where('a.status', 'APPROVED')
                ->when($siteId !== null, fn ($q) => $q->where('a.site_id', $siteId))
                ->whereIn('l.residual_band', ['HIGH', 'VERY_HIGH', 'CRITICAL'])->count(),

            'blocked_contractors' => (int) DB::table('sheq_contractors as c')
                ->where('c.company_id', $companyId)
                ->whereNull('c.deleted_at')->where('c.is_active', true)
                ->whereIn('c.access_status', ['BLOCKED', 'SUSPENDED'])
                ->when($siteId !== null, fn ($q) => $q->whereExists(fn ($e) => $e
                    ->from('sheq_contractor_site_approvals as a')
                    ->whereColumn('a.contractor_id', 'c.id')
                    ->where('a.site_id', $siteId)->where('a.company_id', $companyId)))
                ->count(),

            'non_compliant_workers' => $this->nonCompliantWorkers($companyId, $siteId),

            // Live, to the second. The whole point of a command centre.
            'active_permits' => (int) $site(DB::table('sheq_permits')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereIn('status', ['ACTIVE', 'APPROVED', 'SUSPENDED']))->count(),

            'overdue_capa' => (int) $site(DB::table('sheq_actions')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereNotIn('status', ['VERIFIED', 'CLOSED', 'CANCELLED'])
                ->whereNotNull('due_date')->whereDate('due_date', '<', $now))->count(),

            // Statutory deadlines are company-wide obligations; they have no site column, so the
            // tile shows the same number in a site view. Stated on the tile rather than silently
            // filtered to zero, which would read as "nothing is due".
            'statutory_deadlines' => (int) DB::table('sheq_statutory_obligations')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereNotIn('status', ['SUBMITTED', 'CLOSED', 'WAIVED'])
                ->whereNotNull('due_at')->where('due_at', '<=', $now->copy()->addDays(30))
                ->count(),

            'incidents' => (int) $site(DB::table('sheq_incidents')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('occurred_at', '>=', $monthStart))->count(),

            'site_occupancy' => (int) $site(DB::table('sheq_site_attendance')
                ->where('company_id', $companyId)->whereNull('exited_at'))->count(),

            'inspections' => (int) $site(DB::table('sheq_inspections')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('completed_at', '>=', $monthStart))->count(),

            'audits' => (int) $site(DB::table('sheq_audits')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'CLOSED']))->count(),

            'environmental_breaches' => (int) DB::table('sheq_environmental_readings as r')
                ->join('sheq_environmental_meters as m', 'm.id', '=', 'r.meter_id')
                ->where('r.company_id', $companyId)->where('r.breached_threshold', true)
                ->when($siteId !== null, fn ($q) => $q->where('m.site_id', $siteId))
                ->where('r.reading_date', '>=', $monthStart->toDateString())->count(),
        ];

        $tiles = [];

        foreach (self::TILES as $key => $tile) {
            $tiles[] = array_merge($tile, [
                'key' => $key,
                'value' => $counts[$key] ?? 0,
                // Live counts are read now; the two month-to-date ones are as fresh as this
                // request and say so. Nothing here is older than the request itself, which is
                // exactly what distinguishes these tiles from the indicators below.
                'as_at' => $now->toIso8601String(),
                'company_wide' => $key === 'statutory_deadlines',
            ]);
        }

        return [
            'tiles' => $tiles,
            'site_id' => $siteId,
            'period' => $period,
            // CMD-3. One per block, because they are not the same age and a single page-level
            // timestamp would make the oldest of them look as fresh as the newest.
            'as_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Leading and lagging indicators (CMD-4, CMD-5), read from the projection.
     *
     * Carries the projection's own `computed_at` rather than the time of this request, because
     * that is the honest answer to "how old is this" and the difference can be a month.
     */
    public function indicators(int $companyId, ?int $siteId = null, ?Carbon $at = null): array
    {
        $at ??= Carbon::now();

        $snapshot = $this->kpis->snapshot($companyId, $at->year, $at->month, $siteId);

        if ($snapshot === null) {
            return [
                'leading' => [], 'lagging' => [], 'rates' => [],
                'computed_at' => null, 'lag_seconds' => null,
                // Said plainly. A dashboard of empty tiles reads as "a safe month" rather than
                // as "nobody has run the projection".
                'why_empty' => 'No KPI snapshot has been computed for this period yet. The '
                    .'figures appear once `sheq:kpis:compute` has run — an empty dashboard here '
                    .'means the projection has not run, not that nothing happened.',
                'stale' => true,
            ];
        }

        $metrics = $snapshot['metrics'] ?? [];

        /*
         * Staleness from the snapshot's own `lag_seconds`, NOT from comparing `computed_at` to
         * PHP's clock.
         *
         * `KpiService::snapshot()` computes that lag inside the database on purpose: this
         * connection's session runs Africa/Johannesburg while the application runs UTC, so a
         * timestamp written by Laravel and compared against `Carbon::now()` is out by the offset.
         * Doing it here in PHP would have made every snapshot read as two hours older than it is
         * — which at a 26-hour threshold is invisible, and at any tighter one would have flagged
         * a perfectly fresh dashboard as stale.
         */
        $lag = (int) ($snapshot['lag_seconds'] ?? 0);

        return [
            'leading' => $metrics['leading'] ?? [],
            'lagging' => $metrics['lagging'] ?? [],
            'rates' => $metrics['rates'] ?? [],
            'exposure_estimated' => (bool) ($snapshot['exposure_estimated'] ?? false),
            'computed_at' => $snapshot['computed_at'] ?? null,
            'lag_seconds' => $lag,
            // Twenty-six hours, so a daily cron that ran late is not flagged and one that did not
            // run at all is. A figure nobody has refreshed in two days is being read as today's.
            'stale' => $lag > 26 * 3600,
            'why_empty' => null,
        ];
    }

    /**
     * The risk heatmap (CMD-6).
     *
     * Sites down one axis and the score's own signals across the other, so a cell is "how much of
     * this site's risk comes from this signal" rather than an abstract colour. That is what makes
     * it interactive in a useful way: a cell is a filter, not a decoration.
     */
    public function heatmap(int $companyId, ?string $period = null): array
    {
        $period ??= Carbon::now()->format('Y-m');
        $board = $this->siteRisk->board($companyId, $period);

        $signals = array_map(fn (string $key) => [
            'key' => $key,
            'label' => SiteRiskScoreService::SIGNALS[$key]['label'],
        ], SiteRiskScoreService::signalKeys());

        $rows = [];

        foreach ($board as $site) {
            $shares = [];

            foreach ($site['breakdown'] as $contribution) {
                $shares[$contribution['key']] = [
                    'share' => $contribution['share'],
                    'count' => $contribution['count'],
                    'points' => $contribution['points'],
                ];
            }

            $rows[] = [
                'site_id' => $site['site_id'],
                'site_name' => $site['site_name'],
                'risk_score' => $site['risk_score'],
                'risk_band' => $site['risk_band'],
                'people_on_site' => $site['people_on_site'],
                // Every signal present, including the zeroes, because a heatmap with ragged rows
                // cannot be read down a column — and reading down a column is how somebody spots
                // that four sites all have the same problem.
                'cells' => array_map(fn (array $s) => $shares[$s['key']]
                    ?? ['share' => 0, 'count' => 0, 'points' => 0], $signals),
            ];
        }

        return [
            'period' => $period,
            'signals' => $signals,
            'rows' => $rows,
            'bands' => SiteRiskScoreService::BANDS,
            'as_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * The executive dashboard (CMD-7).
     *
     * The eight things CMD-7 names, and nothing else. An executive dashboard that grows a ninth
     * panel every quarter is one nobody opens, and the discipline of matching the requirement
     * exactly is the only thing that stops that.
     */
    public function executive(int $companyId, ?string $period = null): array
    {
        $period ??= Carbon::now()->format('Y-m');
        $at = Carbon::createFromFormat('Y-m-d', $period.'-01');
        $indicators = $this->indicators($companyId, null, $at);
        $monthStart = $at->copy()->startOfMonth();
        $monthEnd = $at->copy()->endOfMonth();

        $board = $this->siteRisk->board($companyId, $period, 10);

        return [
            'period' => $period,

            // 1. Top risks — the sites, not the risk lines: an executive acts on a site.
            'top_risks' => array_slice($board, 0, 5),

            // 2. Worst-performing contractors, from the score that already exists.
            'worst_contractors' => DB::table('sheq_contractor_scores as s')
                ->join('sheq_contractors as c', 'c.id', '=', 's.contractor_id')
                ->where('s.company_id', $companyId)->where('s.period', $period)
                ->whereNotNull('s.score')->whereNull('c.deleted_at')
                ->orderBy('s.score')
                ->limit(5)
                ->get(['c.uuid', 'c.name', 's.score', 's.rating', 's.breakdown'])
                ->map(fn ($r) => array_merge((array) $r, [
                    'breakdown' => json_decode((string) $r->breakdown, true) ?: [],
                ]))->all(),

            // 3. Compliance.
            'compliance' => [
                'contractor_compliance_percent' =>
                    $indicators['leading']['contractor_compliance_percent'] ?? null,
                'contractors_blocked' => $indicators['leading']['contractors_blocked'] ?? null,
                'obligations_on_time_percent' =>
                    $indicators['leading']['obligations_on_time_percent'] ?? null,
                'competencies_expiring_blocking_90d' =>
                    $indicators['leading']['competencies_expiring_blocking_90d'] ?? null,
            ],

            // 4. Incidents.
            'incidents' => [
                'total' => $indicators['lagging']['total_incidents'] ?? null,
                'fatalities' => $indicators['lagging']['fatalities'] ?? null,
                'lost_time_injuries' => $indicators['lagging']['lost_time_injuries'] ?? null,
                'recordable_cases' => $indicators['lagging']['recordable_cases'] ?? null,
                'days_lost' => $indicators['lagging']['days_lost'] ?? null,
                'rates' => $indicators['rates'] ?? [],
            ],

            // 5. Cost. In minor units throughout, because a rounded rand on a board pack that
            // somebody then adds up is how a total stops reconciling.
            'cost' => $this->cost($companyId, $monthStart, $monthEnd),

            // 6. CAPA.
            'capa' => [
                'open' => $indicators['leading']['actions_open'] ?? null,
                'overdue' => $indicators['leading']['actions_overdue'] ?? null,
                'overdue_percent' => $indicators['leading']['actions_overdue_percent'] ?? null,
                'effectiveness_percent' =>
                    $indicators['leading']['capa_effectiveness_percent'] ?? null,
                'weak_control_actions' => $indicators['leading']['weak_control_actions'] ?? null,
            ],

            // 7. Environmental performance.
            'environmental' => $this->environmental($companyId, $monthStart, $monthEnd),

            // 8. Quality.
            'quality' => $this->quality($companyId, $monthStart, $monthEnd),

            'computed_at' => $indicators['computed_at'],
            'stale' => $indicators['stale'],
            'as_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * One step of CMD-2's drill-down.
     *
     * Group → Region → Site → Department → Contractor → Worker → Evidence. Each level returns
     * rows that each carry the parameters for the next, so the screen never has to know the
     * hierarchy — which is what keeps the two from disagreeing about what a region is.
     *
     * `EVIDENCE` is the bottom and is deliberately different: it returns records rather than
     * aggregates, because that is the point of a drill-down. A dashboard you cannot get to a
     * document from is a dashboard nobody trusts.
     */
    public function drill(int $companyId, string $level, array $filters = []): array
    {
        $level = strtoupper($level);

        if (! in_array($level, self::LEVELS, true)) {
            $level = 'GROUP';
        }

        return match ($level) {
            'GROUP' => $this->drillRegions($companyId),
            'REGION' => $this->drillSites($companyId, $filters['region_id'] ?? null),
            'SITE' => $this->drillDepartments($companyId, (int) ($filters['site_id'] ?? 0)),
            'DEPARTMENT' => $this->drillContractors($companyId, (int) ($filters['site_id'] ?? 0)),
            'CONTRACTOR' => $this->drillWorkers($companyId,
                (int) ($filters['contractor_id'] ?? 0)),
            'WORKER', 'EVIDENCE' => $this->drillEvidence($companyId, $filters),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Regions, which on this system are sites with children.
     *
     * `sheq_sites` is a tree — `parent_id` and `path` — so a region is not a separate concept
     * with its own table; it is a site somebody has put other sites underneath. A tenant with a
     * flat estate therefore has no regions, and the level collapses to the sites themselves
     * rather than showing an empty screen.
     */
    private function drillRegions(int $companyId): array
    {
        $parents = DB::table('sheq_sites as s')
            ->where('s.company_id', $companyId)->whereNull('s.deleted_at')
            ->whereNull('s.parent_id')
            ->whereExists(fn ($q) => $q->from('sheq_sites as c')
                ->whereColumn('c.parent_id', 's.id')->whereNull('c.deleted_at'))
            ->orderBy('s.name')
            ->get(['s.id', 's.name', 's.code']);

        if ($parents->isEmpty()) {
            // No region layer on this estate. Skipping straight to sites is right: an empty
            // "regions" screen would read as a configuration error rather than as a flat estate.
            return ['level' => 'REGION', 'collapsed' => true,
                'rows' => $this->drillSites($companyId, null)['rows']];
        }

        return [
            'level' => 'GROUP',
            'collapsed' => false,
            'rows' => $parents->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'code' => $r->code,
                'sites' => (int) DB::table('sheq_sites')->where('parent_id', $r->id)
                    ->whereNull('deleted_at')->count(),
                'next' => ['level' => 'REGION', 'region_id' => $r->id],
            ])->all(),
        ];
    }

    private function drillSites(int $companyId, ?int $regionId): array
    {
        $period = Carbon::now()->format('Y-m');

        $rows = DB::table('sheq_sites as s')
            ->leftJoin('sheq_site_risk_scores as r', function ($join) use ($period) {
                $join->on('r.site_id', '=', 's.id')->where('r.period', $period);
            })
            ->where('s.company_id', $companyId)->whereNull('s.deleted_at')
            ->where('s.is_active', true)
            ->when($regionId !== null, fn ($q) => $q->where('s.parent_id', $regionId))
            ->orderByRaw('r.risk_score IS NULL')->orderByDesc('r.risk_score')->orderBy('s.name')
            ->get(['s.id', 's.name', 's.code', 'r.risk_score', 'r.risk_band']);

        return [
            'level' => 'REGION',
            'collapsed' => false,
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'code' => $r->code,
                'risk_score' => $r->risk_score, 'risk_band' => $r->risk_band,
                'next' => ['level' => 'SITE', 'site_id' => $r->id],
            ])->all(),
        ];
    }

    /**
     * Departments, from the company unit tree.
     *
     * Read through the units a site's incidents and observations are actually attributed to
     * rather than through the whole org chart, because a drill-down that lists four hundred
     * departments with nothing in them is one nobody uses.
     */
    private function drillDepartments(int $companyId, int $siteId): array
    {
        $rows = DB::table('sheq_observations as o')
            ->leftJoin('company_units as u', 'u.id', '=', 'o.department_unit_id')
            ->where('o.company_id', $companyId)->where('o.site_id', $siteId)
            ->whereNotNull('o.department_unit_id')->whereNull('o.deleted_at')
            ->groupBy('u.id', 'u.name')
            ->orderByDesc(DB::raw('count(*)'))
            ->get([
                'u.id', 'u.name',
                DB::raw('count(*) as observations'),
            ]);

        return [
            'level' => 'SITE',
            'collapsed' => $rows->isEmpty(),
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name ?? 'Unattributed',
                'observations' => (int) $r->observations,
                'next' => ['level' => 'DEPARTMENT', 'site_id' => $siteId,
                    'department_unit_id' => $r->id],
            ])->all(),
        ];
    }

    private function drillContractors(int $companyId, int $siteId): array
    {
        $period = Carbon::now()->format('Y-m');

        $rows = DB::table('sheq_contractors as c')
            ->join('sheq_contractor_site_approvals as a', function ($join) use ($siteId) {
                $join->on('a.contractor_id', '=', 'c.id')->where('a.site_id', $siteId);
            })
            ->leftJoin('sheq_contractor_scores as s', function ($join) use ($period) {
                $join->on('s.contractor_id', '=', 'c.id')->where('s.period', $period);
            })
            ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
            ->where('c.is_active', true)
            ->orderBy('s.score')
            ->get(['c.id', 'c.uuid', 'c.name', 'c.access_status', 's.score', 's.rating']);

        return [
            'level' => 'DEPARTMENT',
            'collapsed' => false,
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id, 'uuid' => $r->uuid, 'name' => $r->name,
                'access_status' => $r->access_status, 'score' => $r->score, 'rating' => $r->rating,
                'next' => ['level' => 'CONTRACTOR', 'contractor_id' => $r->id],
            ])->all(),
        ];
    }

    private function drillWorkers(int $companyId, int $contractorId): array
    {
        $rows = DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'trade', 'inducted_on', 'induction_expires']);

        return [
            'level' => 'CONTRACTOR',
            'collapsed' => false,
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id, 'uuid' => $r->uuid, 'name' => $r->name, 'trade' => $r->trade,
                'inducted_on' => $r->inducted_on, 'induction_expires' => $r->induction_expires,
                'next' => ['level' => 'WORKER', 'worker_id' => $r->id],
            ])->all(),
        ];
    }

    /**
     * The bottom of the drill-down (CMD-2's `Evidence`).
     *
     * Records, not aggregates: the compliance decision for this worker with its reasons, their
     * credentials, and the gate events behind them. A dashboard somebody cannot get from a tile
     * to a document in is a dashboard they will keep a spreadsheet alongside.
     */
    private function drillEvidence(int $companyId, array $filters): array
    {
        $workerId = (int) ($filters['worker_id'] ?? 0);

        if ($workerId === 0) {
            return ['level' => 'EVIDENCE', 'collapsed' => true, 'rows' => []];
        }

        $worker = DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('id', $workerId)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'name', 'contractor_id']);

        if ($worker === null) {
            return ['level' => 'EVIDENCE', 'collapsed' => true, 'rows' => []];
        }

        $decision = app(ComplianceDecisionService::class)
            ->forWorker($companyId, (int) $worker->id, $filters['site_id'] ?? null);

        $credentials = DB::table('sheq_contractor_worker_credentials')
            ->where('company_id', $companyId)->where('worker_id', $workerId)
            ->whereNull('deleted_at')
            ->orderByDesc('expires_on')
            ->get(['uuid', 'kind', 'code', 'title', 'status', 'issued_on', 'expires_on'])
            ->map(fn ($r) => (array) $r)->all();

        $events = DB::table('sheq_access_events')
            ->where('company_id', $companyId)
            ->where('subject_type', 'CONTRACTOR_WORKER')->where('subject_id', $workerId)
            ->orderByDesc('occurred_at')->limit(20)
            ->get(['uuid', 'site_id', 'allowed', 'reasons', 'occurred_at', 'method'])
            ->map(fn ($r) => (array) $r)->all();

        return [
            'level' => 'EVIDENCE',
            'collapsed' => false,
            'worker' => (array) $worker,
            'decision' => $decision,
            'credentials' => $credentials,
            'access_events' => $events,
            'rows' => $credentials,
        ];
    }

    private function nonCompliantWorkers(int $companyId, ?int $siteId): int
    {
        /*
         * Counted from the credentials rather than by running the compliance engine per worker.
         *
         * The engine is the authority on whether one named person may work today, and running it
         * across every worker on an estate is thirteen checks each — a tile that takes nine
         * seconds is a tile somebody turns off. So this counts workers with at least one lapsed
         * or unaccepted credential, which is the overwhelming majority of what the engine would
         * refuse, and the tile drills through to the engine for the authoritative answer on any
         * one of them.
         */
        return (int) DB::table('sheq_contractor_workers as w')
            ->where('w.company_id', $companyId)
            ->whereNull('w.deleted_at')->where('w.is_active', true)
            ->when($siteId !== null, fn ($q) => $q->where('w.site_id', $siteId))
            ->where(fn ($q) => $q
                ->whereExists(fn ($e) => $e->from('sheq_contractor_worker_credentials as k')
                    ->whereColumn('k.worker_id', 'w.id')
                    ->whereNull('k.deleted_at')
                    ->where(fn ($w2) => $w2
                        ->where('k.status', '!=', 'ACCEPTED')
                        ->orWhere(fn ($x) => $x->whereNotNull('k.expires_on')
                            ->whereDate('k.expires_on', '<', Carbon::today()))))
                ->orWhereNull('w.inducted_on')
                ->orWhere(fn ($i) => $i->whereNotNull('w.induction_expires')
                    ->whereDate('w.induction_expires', '<', Carbon::today())))
            ->count();
    }

    /** @return array<string,mixed> */
    private function cost(int $companyId, Carbon $from, Carbon $to): array
    {
        $incidents = (int) DB::table('sheq_incidents')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('cost_estimate_minor');

        $actions = (int) DB::table('sheq_actions')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->sum('cost_actual_minor');

        $rework = (int) DB::table('sheq_rework')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->sum('cost_minor');

        return [
            'incidents_minor' => $incidents,
            'corrective_actions_minor' => $actions,
            'rework_minor' => $rework,
            'total_minor' => $incidents + $actions + $rework,
            // Named, because a figure in minor units with no currency on it will be read as rands
            // by one person and cents by the next.
            'currency' => DB::table('sheq_incidents')
                ->where('company_id', $companyId)->whereNotNull('cost_currency')
                ->value('cost_currency') ?? 'ZAR',
        ];
    }

    /** @return array<string,mixed> */
    private function environmental(int $companyId, Carbon $from, Carbon $to): array
    {
        return [
            'threshold_breaches' => (int) DB::table('sheq_environmental_readings')
                ->where('company_id', $companyId)->where('breached_threshold', true)
                ->whereBetween('reading_date', [$from->toDateString(), $to->toDateString()])
                ->count(),
            'spills' => (int) DB::table('sheq_spills')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereBetween('occurred_at', [$from, $to])->count(),
            'reportable_spills' => (int) DB::table('sheq_spills')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('authority_notifiable', true)
                ->whereBetween('occurred_at', [$from, $to])->count(),
            'hazardous_waste_movements' => (int) DB::table('sheq_waste_movements')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_hazardous', true)
                ->whereBetween('removed_on', [$from->toDateString(), $to->toDateString()])
                ->count(),
            // A manifest that never came back is hazardous waste nobody can prove was disposed
            // of lawfully, which is the environmental equivalent of an unaccounted person.
            'manifests_outstanding' => (int) DB::table('sheq_waste_movements')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_hazardous', true)->where('manifest_returned', false)
                ->where('removed_on', '<', $from->copy()->subDays(30)->toDateString())
                ->count(),
            'co2e_kg' => (float) DB::table('sheq_environmental_readings')
                ->where('company_id', $companyId)
                ->whereBetween('reading_date', [$from->toDateString(), $to->toDateString()])
                ->sum('co2e_kg'),
        ];
    }

    /** @return array<string,mixed> */
    private function quality(int $companyId, Carbon $from, Carbon $to): array
    {
        $ncrs = DB::table('sheq_ncrs')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotIn('status', ['VOID'])
            ->whereBetween('raised_at', [$from, $to])
            ->get(['status', 'severity', 'closed_at', 'due_date']);

        return [
            'ncrs_raised' => $ncrs->count(),
            'ncrs_open' => $ncrs->whereNull('closed_at')->count(),
            'ncrs_high_severity' => $ncrs->whereIn('severity',
                QualityService::HIGH_SEVERITIES)->count(),
            'ncrs_overdue' => $ncrs->filter(fn ($n) => $n->closed_at === null
                && $n->due_date !== null
                && Carbon::parse($n->due_date)->lt(Carbon::today()))->count(),
            'rework_cost_minor' => (int) DB::table('sheq_rework')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereBetween('created_at', [$from, $to])->sum('cost_minor'),
        ];
    }
}
