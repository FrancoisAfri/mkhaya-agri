<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Ai\SafetyAnalytics;
use App\Modules\Operations\Sheq\Services\ContractorPerformanceService;
use App\Modules\Operations\Sheq\Services\EnvironmentalService;
use App\Modules\Operations\Sheq\Services\InjuryCaseService;
use App\Modules\Operations\Sheq\Services\KpiService;
use App\Modules\Operations\Sheq\Services\TrainingTrackingService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The SHEQ landing page (SHEQ-DASH-1, SHEQ-DASH-2).
 *
 * This page used to report build progress — packs installed, features "planned" — which was the
 * honest thing to show while nothing could be captured. That reasoning has expired: incidents,
 * hazards, actions, permits, inspections, competency and the legal register all carry real data
 * now, and a roadmap chip saying PLANNED next to a working feature is simply wrong.
 *
 * What replaced it is ordered by what would actually hurt: statutory deadlines first (a missed
 * section 24 notification is a criminal matter), then overdue corrective actions, then the leading
 * indicators. Lagging KPIs sit lower down, because by the time LTIFR moves the injury has happened.
 *
 * Every count is explicitly company-scoped. RLS covers this when a tenant is active, but a platform
 * admin has no session variable set, and an unscoped count would total every tenant's rows and
 * present them as this one's — the leak pattern this platform has had to fix repeatedly.
 */
class SheqDashboardController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly KpiService $kpis,
        private readonly TrainingTrackingService $training,
        private readonly EnvironmentalService $environmental,
        private readonly ContractorPerformanceService $contractors,
        private readonly SafetyAnalytics $analytics,
        private readonly InjuryCaseService $injuries,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return Inertia::render('Modules/Sheq/Dashboard', [
                'noCompany' => true,
                'attention' => [], 'leading' => [], 'lagging' => null,
                'recentIncidents' => [], 'compliance' => null, 'period' => null,
                'executive' => null, 'heatmap' => [], 'departments' => [],
                'contractorPerformance' => [], 'insights' => [],
            ]);
        }

        $today = Carbon::today();
        $now = Carbon::now();
        // Read once: two of the attention figures are counted from the same list, and a dashboard
        // that queries the same thing twice is a dashboard that can disagree with itself.
        $away = $this->injuries->stillAway($companyId);

        return Inertia::render('Modules/Sheq/Dashboard', [
            'noCompany' => false,
            'period' => $now->format('F Y'),

            /**
             * The executive block: the numbers a director asks for, in one place.
             *
             * Cost of incidents is included because it is the figure that moves budget. Safety
             * arguments made in injuries lose to arguments made in rands, and the cost is already
             * on the record — it was simply never surfaced.
             */
            'executive' => $this->executive($companyId, $now),

            /** Risk heat map: likelihood × severity, counted from the live risk lines. */
            'heatmap' => $this->heatmap($companyId),

            /** Which departments are producing the incidents. */
            'departments' => $this->departments($companyId, $now),

            'contractorPerformance' => $this->contractors->leaderboard($companyId),

            /** What the analytics found, worst first. */
            'insights' => $this->analytics->open($companyId, 6),

            /**
             * The "deal with this today" block. Each entry is a count that represents somebody
             * exposed or a deadline running, and each links to the queue that clears it.
             */
            'attention' => [
                /*
                 * No rulebook adopted, which blocks incident reporting entirely.
                 *
                 * First in the list because it is not a backlog item — until it is done the
                 * classifier has nothing to classify against and every incident report is
                 * refused. A tenant hit exactly this in production and only found out at the
                 * moment of submitting, so it is said here too.
                 */
                [
                    'key' => 'no_regulatory_pack',
                    'count' => DB::table('sheq_regulatory_pack_activations')
                        ->where('company_id', $companyId)
                        ->where('status', 'ACCEPTED')
                        ->whereNull('deactivated_at')
                        ->whereNull('site_id')
                        ->exists() ? 0 : 1,
                    'href' => '/sheq/regulatory-packs', 'severity' => 'error',
                ],
                [
                    'key' => 'obligations_overdue',
                    'count' => DB::table('sheq_statutory_obligations')
                        ->where('company_id', $companyId)
                        ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                        ->where('due_at', '<', $now)->count(),
                    'href' => '/sheq/obligations', 'severity' => 'error',
                ],
                [
                    'key' => 'obligations_due_soon',
                    'count' => DB::table('sheq_statutory_obligations')
                        ->where('company_id', $companyId)
                        ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                        ->whereBetween('due_at', [$now, $now->copy()->addHours(48)])->count(),
                    'href' => '/sheq/obligations', 'severity' => 'warning',
                ],
                [
                    'key' => 'actions_overdue',
                    'count' => DB::table('sheq_actions')
                        ->where('company_id', $companyId)->whereNull('deleted_at')
                        ->whereNotIn('status', ['VERIFIED', 'CLOSED'])
                        ->whereNotNull('due_date')->where('due_date', '<', $today)->count(),
                    'href' => '/sheq/actions', 'severity' => 'error',
                ],
                [
                    'key' => 'hazards_untriaged',
                    'count' => DB::table('sheq_hazards')
                        ->where('company_id', $companyId)->whereNull('deleted_at')
                        ->where('status', 'OPEN')->count(),
                    'href' => '/sheq/hazards', 'severity' => 'warning',
                ],
                /*
                 * People who are off work injured, and the ones who should have been back.
                 *
                 * `InjuryCaseService::stillAway()` is the list; nothing read it, so the one figure
                 * on this page that is a person rather than a record was missing. A case whose
                 * expected return date has passed with nobody recording a return is the actionable
                 * half: either they are still off and the days lost are understated — which
                 * understates every severity rate computed from them — or they came back and
                 * nobody wrote it down.
                 */
                [
                    'key' => 'injury_cases_away',
                    'count' => count($away),
                    'href' => '/sheq/injury-cases', 'severity' => 'warning',
                ],
                [
                    'key' => 'injury_returns_overdue',
                    'count' => count(array_filter($away, fn (array $case) => $case['expected_return_on'] !== null
                        && Carbon::parse($case['expected_return_on'])->lt($today))),
                    'href' => '/sheq/injury-cases', 'severity' => 'error',
                ],
            ],

            // Leading indicators: what tells you about next month rather than last one.
            'leading' => [
                [
                    'key' => 'inspections_overdue',
                    'count' => DB::table('sheq_inspection_schedules')
                        ->where('company_id', $companyId)->whereNull('deleted_at')
                        ->where('is_active', true)
                        ->whereNotNull('next_due')->where('next_due', '<', $today)->count(),
                ],
                [
                    'key' => 'competencies_expiring',
                    'count' => DB::table('sheq_competency_records')
                        ->where('company_id', $companyId)->whereNull('deleted_at')
                        ->whereNull('revoked_at')->whereNotNull('expires_at')
                        ->whereBetween('expires_at', [$today, $today->copy()->addDays(30)])->count(),
                ],
                [
                    'key' => 'permits_open',
                    'count' => DB::table('sheq_permits')
                        ->where('company_id', $companyId)->whereNull('deleted_at')
                        ->whereIn('status', ['ISSUED', 'ACTIVE'])->count(),
                ],
                [
                    'key' => 'contractors_blocked',
                    'count' => DB::table('sheq_contractors')
                        ->where('company_id', $companyId)->whereNull('deleted_at')
                        ->where('is_active', true)
                        // Suspended counts too: those are people refused at the boom, and the
                        // tile exists to say how many that is. Counting only BLOCKED understated
                        // it by every contractor somebody had deliberately stopped.
                        ->whereIn('access_status', ['BLOCKED', 'SUSPENDED'])->count(),
                ],
                [
                    'key' => 'documents_unacknowledged',
                    'count' => DB::table('sheq_documents')
                        ->where('company_id', $companyId)->whereNull('deleted_at')
                        ->where('status', 'PUBLISHED')->where('requires_acknowledgement', true)
                        ->whereNotNull('review_due')->where('review_due', '<', $today)->count(),
                ],
            ],

            'lagging' => $this->lagging($companyId, $now),
            'compliance' => $this->compliance($companyId),

            'recentIncidents' => DB::table('sheq_incidents')
                ->leftJoin('sheq_sites', 'sheq_sites.id', '=', 'sheq_incidents.site_id')
                ->where('sheq_incidents.company_id', $companyId)
                ->whereNull('sheq_incidents.deleted_at')
                ->orderByDesc('sheq_incidents.occurred_at')->limit(6)
                ->get([
                    'sheq_incidents.uuid', 'sheq_incidents.reference', 'sheq_incidents.incident_type',
                    'sheq_incidents.occurred_at', 'sheq_incidents.status',
                    'sheq_incidents.statutory_category', 'sheq_incidents.is_reportable',
                    'sheq_sites.name as site_name',
                ])->map(fn ($row) => [
                    'uuid' => $row->uuid, 'reference' => $row->reference,
                    'incident_type' => $row->incident_type, 'occurred_at' => $row->occurred_at,
                    'status' => $row->status, 'statutory_category' => $row->statutory_category,
                    'is_reportable' => (bool) $row->is_reportable, 'site_name' => $row->site_name,
                ])->values(),
        ]);
    }

    /**
     * Lagging KPIs for the current period, read from the stored projection (SHEQ-ARC-4).
     *
     * Reads `snapshot()`, never `computePeriod()`. Computing here would write a KPI snapshot row on
     * every page view — a write on a GET, and a different figure for whoever happened to load the
     * page last. The projection is produced by `sheq:kpis:compute` on a schedule; this page only
     * reports it, along with how stale it is so nobody mistakes an old figure for a live one.
     *
     * Null when no snapshot exists yet, which the page states plainly rather than rendering zeros.
     *
     * @return array<string,mixed>|null
     */
    private function lagging(int $companyId, Carbon $now): ?array
    {
        $snapshot = $this->kpis->snapshot($companyId, (int) $now->year, (int) $now->month);

        if ($snapshot === null) {
            return null;
        }

        $hours = (float) ($snapshot['inputs']['exposure']['total_hours'] ?? 0);

        return [
            'rates' => $snapshot['metrics']['rates'] ?? [],
            'lagging' => $snapshot['metrics']['lagging'] ?? [],
            'hours_worked' => $hours,
            'exposure_estimated' => (bool) $snapshot['exposure_estimated'],
            'has_exposure' => $hours > 0,
            'computed_at' => $snapshot['computed_at'],
            'lag_seconds' => $snapshot['lag_seconds'],
        ];
    }

    /** @return array<string,mixed>|null  null when the register has not been seeded yet */
    private function compliance(int $companyId): ?array
    {
        $counts = DB::table('sheq_legal_register_lines')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status')->all();

        if ($counts === []) {
            return null;
        }

        $counts = array_map('intval', $counts);
        $assessable = array_sum($counts) - ($counts['NOT_APPLICABLE'] ?? 0);

        return [
            'by_status' => $counts,
            'lines' => array_sum($counts),
            'percent' => $assessable > 0
                ? round(($counts['COMPLIANT'] ?? 0) / $assessable * 100, 1)
                : 0.0,
            'needs_attention' => ($counts['NON_COMPLIANT'] ?? 0) + ($counts['REVIEW_REQUIRED'] ?? 0),
        ];
    }

    /**
     * The executive summary figures.
     *
     * @return array<string,mixed>
     */
    private function executive(int $companyId, Carbon $now): array
    {
        $monthStart = $now->copy()->startOfMonth();
        $yearStart = $now->copy()->startOfYear();

        $incidents = DB::table('sheq_incidents')->where('company_id', $companyId)
            ->whereNull('deleted_at')->where('occurred_at', '>=', $yearStart);

        $auditScores = DB::table('sheq_audits')->where('company_id', $companyId)
            ->whereNull('deleted_at')->whereNotNull('score')
            ->where('created_at', '>=', $yearStart)->avg('score');

        $environmental = $this->environmental->summary($companyId,
            $yearStart->toDateString(), $now->toDateString());

        return [
            'total_incidents' => (clone $incidents)->count(),
            'near_misses' => (clone $incidents)->where('incident_type', 'NEAR_MISS')->count(),
            'lost_time' => (clone $incidents)->where('outcome', 'LOST_TIME')->count(),

            'open_capas' => DB::table('sheq_actions')->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['VERIFIED', 'VERIFIED_CLOSED', 'CLOSED'])->count(),
            'overdue_capas' => DB::table('sheq_actions')->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['VERIFIED', 'VERIFIED_CLOSED', 'CLOSED'])
                ->whereNotNull('due_date')->where('due_date', '<', $now->toDateString())->count(),

            'audit_score' => $auditScores === null ? null : round((float) $auditScores, 1),
            'open_findings' => DB::table('sheq_audit_findings')->where('company_id', $companyId)
                ->whereNull('deleted_at')->whereIn('grade', ['MAJOR_NC', 'MINOR_NC'])
                ->whereNotIn('status', ['CLOSED'])->count(),

            'training_compliance' => $this->training->compliance($companyId)['compliance_pct'],

            'carbon_kg' => $environmental['total_co2e_kg'],
            'environmental_aspects' => count($environmental['aspects']),

            /**
             * Cost of incidents, in major units.
             *
             * Stored in minor units to avoid float drift on money; divided only for display, which
             * is the one place rounding is harmless.
             */
            'incident_cost' => round(((int) (clone $incidents)->sum('cost_estimate_minor')) / 100, 2),

            'contractor_compliance_pct' => $this->contractorCompliance($companyId),
        ];
    }

    /**
     * The risk heat map — likelihood against severity.
     *
     * Counted from open risk lines rather than from incidents: a heat map of what has already
     * happened is a history, and the point of the matrix is to show what has not happened yet.
     *
     * @return list<array{likelihood:int, severity:int, count:int}>
     */
    private function heatmap(int $companyId): array
    {
        if (! Schema::hasTable('sheq_risk_lines')) {
            return [];
        }

        $likelihood = collect(['likelihood', 'likelihood_rating', 'residual_likelihood'])
            ->first(fn ($c) => Schema::hasColumn('sheq_risk_lines', $c));
        $severity = collect(['severity', 'severity_rating', 'consequence', 'residual_severity'])
            ->first(fn ($c) => Schema::hasColumn('sheq_risk_lines', $c));

        if ($likelihood === null || $severity === null) {
            return [];
        }

        return DB::table('sheq_risk_lines')
            ->where('company_id', $companyId)
            ->whereNotNull($likelihood)->whereNotNull($severity)
            ->groupBy($likelihood, $severity)
            ->selectRaw("{$likelihood} as likelihood, {$severity} as severity, count(*) as count")
            ->get()
            ->map(fn ($r) => ['likelihood' => (int) $r->likelihood,
                'severity' => (int) $r->severity, 'count' => (int) $r->count])
            ->values()->all();
    }

    /**
     * Incidents by department, read from HR rather than duplicated here.
     *
     * @return list<array<string,mixed>>
     */
    private function departments(int $companyId, Carbon $now): array
    {
        if (! Schema::hasTable('sheq_incident_people')) {
            return [];
        }

        return DB::table('sheq_incident_people as ip')
            ->join('sheq_incidents as i', 'i.id', '=', 'ip.incident_id')
            ->join('hr_people as p', 'p.id', '=', 'ip.hr_person_id')
            // The pivot's `company_id` column holds the company_unit id, not a company id —
            // misleading, but it is the platform's established join and a second interpretation
            // here would quietly report the wrong departments.
            ->leftJoin('company_unit_user_pivots as pv', 'pv.user_id', '=', 'p.user_id')
            ->leftJoin('company_units as cu', function ($join) {
                $join->on('cu.id', '=', 'pv.company_id')->where('cu.type', '=', 'department');
            })
            ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
            ->where('i.occurred_at', '>=', $now->copy()->startOfYear())
            ->groupBy('cu.name')
            ->selectRaw('coalesce(cu.name, \'Unassigned\') as department, count(distinct i.id) as incidents')
            ->orderByDesc('incidents')->limit(10)
            ->get()
            ->map(fn ($r) => ['department' => $r->department, 'incidents' => (int) $r->incidents])
            ->values()->all();
    }

    /** Share of contractors whose SHEQ approval is current. */
    private function contractorCompliance(int $companyId): float
    {
        $total = DB::table('sheq_contractors')->where('company_id', $companyId)
            ->whereNull('deleted_at')->count();

        if ($total === 0) {
            return 0.0;
        }

        $compliant = DB::table('sheq_contractors')->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotIn(DB::raw('upper(coalesce(access_status, \'\'))'),
                ['BLOCKED', 'SUSPENDED', 'NON_COMPLIANT'])
            ->count();

        return round(($compliant / $total) * 100, 1);
    }
}
