<?php

namespace App\Modules\Procurement\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Workload & capacity management (§4).
 *
 * §4 asks for more than a report, and the difference is §4.3: the system should *notice* when an
 * officer is overloaded and propose redistribution, rather than waiting for a manager to read a
 * table and work it out. So `detect()` produces a narrative alert with concrete suggestions, and
 * those alerts are records with a status — a manager can see whether anything was done about the
 * last one.
 *
 * Capacity bands come from the tenant's own thresholds, never from a constant. What counts as
 * overloaded for a two-person procurement unit running framework agreements and for a
 * twenty-person unit running open tenders are not the same number, and hard-coding either makes
 * the feature useless for the other.
 *
 * Live figures are computed on demand; the daily snapshot exists to answer "was this officer
 * overloaded in March, when that tender slipped", which a live query permanently cannot.
 */
class WorkloadService
{
    /** Relative cost of each kind of work when computing the load index. */
    private const WEIGHTS = [
        'active_procurements' => 1.0,
        'tenders_managed' => 1.5,   // a live tender is the heaviest thing an officer carries
        'evaluations_managed' => 1.0,
        'deviations_pending' => 0.5,
        'overdue_actions' => 2.0,   // overdue work costs more attention than work in hand
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLedger $audit,
    ) {}

    /**
     * §4.1 — every figure for one officer, live.
     *
     * @return array<string,mixed>
     */
    public function forOfficer(int $companyId, int $officerId): array
    {
        $now = Carbon::now();

        $records = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('officer_id', $officerId)
            ->get(['id', 'stage', 'status', 'target_completion_at', 'opened_at', 'closed_at']);

        $active = $records->where('status', 'ACTIVE')->whereNotIn('stage', ['CLOSED']);
        $completed = $records->where('status', 'CLOSED');

        $tenders = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('officer_id', $officerId)
            ->get(['id', 'status', 'evaluation_deadline']);

        $evaluations = DB::table('procgov_review_evaluators as e')
            ->join('procgov_tender_reviews as r', 'r.id', '=', 'e.review_id')
            ->where('e.company_id', $companyId)
            ->where('e.user_id', $officerId)
            ->whereNotIn('e.status', ['REMOVED', 'RECUSED'])
            ->whereIn('r.status', ['EVALUATION', 'MODERATION'])
            ->count();

        $deviations = DB::table('procgov_deviations')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('requesting_officer_id', $officerId)
            ->get(['id', 'status', 'decided_at']);

        $pendingApprovals = DB::table('procgov_approval_steps as s')
            ->join('procgov_approval_requests as r', 'r.id', '=', 's.request_id')
            ->where('s.company_id', $companyId)
            ->where('s.approver_id', $officerId)
            ->where('s.status', 'ACTIVE')
            ->get(['s.due_at']);

        $overdueRecords = $active->filter(fn ($r) => $r->target_completion_at
            && Carbon::parse($r->target_completion_at)->lt($now))->count();

        $overdueEvaluations = $tenders->filter(fn ($t) => $t->evaluation_deadline
            && $t->status === 'EVALUATION'
            && Carbon::parse($t->evaluation_deadline)->lt($now))->count();

        $overdueApprovals = $pendingApprovals->filter(fn ($a) => $a->due_at
            && Carbon::parse($a->due_at)->lt($now))->count();

        $committees = DB::table('procgov_committee_members')
            ->where('company_id', $companyId)
            ->where('user_id', $officerId)
            ->whereNull('deleted_at')
            ->where('status', 'ACTIVE')
            ->count();

        $contracts = DB::table('procgov_record_links as l')
            ->join('procgov_records as r', 'r.id', '=', 'l.record_id')
            ->where('l.company_id', $companyId)
            ->where('l.link_role', 'contract')
            ->where('r.officer_id', $officerId)
            ->count();

        $figures = [
            'officer_id' => $officerId,
            'active_procurements' => $active->count(),
            'completed_procurements' => $completed->count(),
            'pending_procurements' => $active->where('stage', 'REQUEST')->count(),
            'tenders_managed' => $tenders->whereNotIn('status', ['AWARDED', 'CANCELLED'])->count(),
            'evaluations_managed' => $evaluations,
            'deviations_processed' => $deviations->whereNotNull('decided_at')->count(),
            'deviations_pending' => $deviations->whereIn('status', [
                'SUBMITTED', 'COMPLIANCE_REVIEW', 'PROCUREMENT_REVIEW', 'FINANCIAL_REVIEW', 'MANAGEMENT_APPROVAL',
            ])->count(),
            'contracts_managed' => $contracts,
            'committee_participation' => $committees,
            'pending_actions' => $pendingApprovals->count(),
            'overdue_actions' => $overdueRecords + $overdueEvaluations + $overdueApprovals,
            'avg_processing_hours' => $this->averageProcessingHours($companyId, $officerId),
        ];

        return array_merge($figures, $this->capacity($companyId, $figures));
    }

    /**
     * §4.2 workload dashboard: one row per officer who carries procurement work.
     *
     * @return array<string,mixed>
     */
    public function dashboard(int $companyId): array
    {
        $officerIds = $this->activeOfficerIds($companyId);

        $names = DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereIn('u.id', $officerIds ?: [0])
            ->get([
                'u.id', 'u.email',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as full_name"),
            ])
            ->mapWithKeys(fn ($u) => [
                (int) $u->id => trim((string) $u->full_name) ?: (string) $u->email,
            ]);

        $rows = collect($officerIds)
            ->map(fn (int $id) => array_merge(
                $this->forOfficer($companyId, $id),
                ['officer_name' => $names[$id] ?? "User #{$id}"],
            ))
            ->sortByDesc('capacity_index')
            ->values();

        return [
            'officers' => $rows,
            'summary' => [
                'officers' => $rows->count(),
                'overloaded' => $rows->where('capacity_status', 'OVERLOADED')->count(),
                'high' => $rows->where('capacity_status', 'HIGH')->count(),
                'normal' => $rows->where('capacity_status', 'NORMAL')->count(),
                'under_utilised' => $rows->where('capacity_status', 'UNDER_UTILISED')->count(),
                'total_active' => $rows->sum('active_procurements'),
                'total_overdue' => $rows->sum('overdue_actions'),
            ],
            'thresholds' => [
                'high' => (int) $this->settings->get($companyId)->workload_high_threshold,
                'overloaded' => (int) $this->settings->get($companyId)->workload_overload_threshold,
            ],
        ];
    }

    /**
     * §4.3 automatic overload detection, with the redistribution suggestion the brief asks for.
     *
     * Work is only ever suggested for reassignment, never reassigned: moving a live tender out from
     * under the officer running it, automatically, would be worse than the overload.
     *
     * @return int alerts raised
     */
    public function detect(int $companyId): int
    {
        $dashboard = $this->dashboard($companyId);
        $raised = 0;

        $capacity = collect($dashboard['officers']);
        $available = $capacity
            ->whereIn('capacity_status', ['UNDER_UTILISED', 'NORMAL'])
            ->sortBy('capacity_index')
            ->values();

        foreach ($capacity as $officer) {
            if (! in_array($officer['capacity_status'], ['HIGH', 'OVERLOADED'], true)) {
                continue;
            }

            // One open alert per officer at a time; re-raising daily is noise, and noise is how
            // people learn to ignore capacity warnings.
            $open = DB::table('procgov_capacity_alerts')
                ->where('company_id', $companyId)
                ->where('officer_id', $officer['officer_id'])
                ->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
                ->exists();

            if ($open) {
                continue;
            }

            $awaitingEvaluation = DB::table('procgov_tender_reviews')
                ->where('company_id', $companyId)
                ->where('officer_id', $officer['officer_id'])
                ->where('status', 'EVALUATION')
                ->count();

            $narrative = sprintf(
                '%s currently has %d active procurement process%s, %d overdue action%s and %d tender%s awaiting evaluation.',
                $officer['officer_name'],
                $officer['active_procurements'],
                $officer['active_procurements'] === 1 ? '' : 'es',
                $officer['overdue_actions'],
                $officer['overdue_actions'] === 1 ? '' : 's',
                $awaitingEvaluation,
                $awaitingEvaluation === 1 ? '' : 's',
            );

            DB::table('procgov_capacity_alerts')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'officer_id' => $officer['officer_id'],
                'severity' => $officer['capacity_status'],
                'active_procurements' => $officer['active_procurements'],
                'overdue_actions' => $officer['overdue_actions'],
                'awaiting_evaluation' => $awaitingEvaluation,
                'narrative' => $narrative,
                'suggested_reassignments' => json_encode(
                    $this->suggestReassignments($companyId, (int) $officer['officer_id'], $available)
                ),
                'status' => 'OPEN',
                'detected_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $this->audit->record($companyId, [
                'action' => 'capacity_alert_raised',
                'capability' => 'workload',
                'entity_type' => 'procgov_capacity_alerts',
                'entity_id' => (int) $officer['officer_id'],
                'actor_type' => 'system',
                'summary' => $narrative,
            ]);

            $raised++;
        }

        return $raised;
    }

    /** Write today's snapshot for every officer — the trend history §4 implies but cannot derive. */
    public function snapshot(int $companyId, ?Carbon $on = null): int
    {
        $on ??= Carbon::today();
        $written = 0;

        foreach ($this->activeOfficerIds($companyId) as $officerId) {
            $figures = $this->forOfficer($companyId, $officerId);

            DB::table('procgov_officer_workload')->updateOrInsert(
                ['company_id' => $companyId, 'officer_id' => $officerId, 'snapshot_date' => $on->toDateString()],
                [
                    'active_procurements' => $figures['active_procurements'],
                    'completed_procurements' => $figures['completed_procurements'],
                    'pending_procurements' => $figures['pending_procurements'],
                    'tenders_managed' => $figures['tenders_managed'],
                    'evaluations_managed' => $figures['evaluations_managed'],
                    'deviations_processed' => $figures['deviations_processed'],
                    'contracts_managed' => $figures['contracts_managed'],
                    'committee_participation' => $figures['committee_participation'],
                    'pending_actions' => $figures['pending_actions'],
                    'overdue_actions' => $figures['overdue_actions'],
                    'avg_processing_hours' => $figures['avg_processing_hours'],
                    'capacity_status' => $figures['capacity_status'],
                    'capacity_index' => $figures['capacity_index'],
                    'computed_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );

            $written++;
        }

        return $written;
    }

    public function alerts(int $companyId, bool $openOnly = true): Collection
    {
        return DB::table('procgov_capacity_alerts as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.officer_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('a.company_id', $companyId)
            ->when($openOnly, fn ($q) => $q->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED']))
            ->orderByDesc('a.detected_at')
            ->limit(100)
            ->get([
                'a.uuid', 'a.severity', 'a.active_procurements', 'a.overdue_actions',
                'a.awaiting_evaluation', 'a.narrative', 'a.suggested_reassignments',
                'a.status', 'a.detected_at', 'a.resolution_note',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as officer_name"),
                'u.email as officer_email',
            ])
            ->map(function ($a) {
                $a->officer = trim((string) $a->officer_name) ?: $a->officer_email;
                $a->suggested_reassignments = json_decode((string) $a->suggested_reassignments, true) ?: [];
                unset($a->officer_name, $a->officer_email);

                return $a;
            });
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{capacity_index:float, capacity_status:string} */
    private function capacity(int $companyId, array $figures): array
    {
        $settings = $this->settings->get($companyId);
        $high = max(1, (int) $settings->workload_high_threshold);
        $overloaded = max($high + 1, (int) $settings->workload_overload_threshold);

        $weighted = 0.0;

        foreach (self::WEIGHTS as $key => $weight) {
            $weighted += ((float) ($figures[$key] ?? 0)) * $weight;
        }

        $status = match (true) {
            $weighted >= $overloaded => 'OVERLOADED',
            $weighted >= $high => 'HIGH',
            $weighted <= ($high * 0.3) => 'UNDER_UTILISED',
            default => 'NORMAL',
        };

        return [
            'capacity_index' => round($weighted, 2),
            'capacity_status' => $status,
        ];
    }

    /** Officers who carry any procurement work at all — the population the dashboard covers. */
    private function activeOfficerIds(int $companyId): array
    {
        $sources = [
            DB::table('procgov_records')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereNotNull('officer_id')
                ->distinct()
                ->pluck('officer_id'),
            DB::table('procgov_tender_reviews')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereNotNull('officer_id')
                ->distinct()
                ->pluck('officer_id'),
            DB::table('procgov_plan_items')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereNotNull('responsible_officer_id')
                ->distinct()
                ->pluck('responsible_officer_id'),
        ];

        return collect($sources)
            ->flatten()
            ->filter()
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Concrete items that could move, and to whom. Newest and least-advanced work is offered first:
     * a procurement still at request stage transfers cleanly, one mid-evaluation does not.
     */
    private function suggestReassignments(int $companyId, int $officerId, Collection $available): array
    {
        if ($available->isEmpty()) {
            return [];
        }

        $movable = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('officer_id', $officerId)
            ->where('status', 'ACTIVE')
            ->whereIn('stage', ['REQUEST', 'PLANNING', 'SOURCING'])
            ->orderByDesc('opened_at')
            ->limit(5)
            ->get(['id', 'uuid', 'reference', 'title', 'stage', 'estimated_value']);

        $suggestions = [];
        $index = 0;

        foreach ($movable as $record) {
            $target = $available[$index % $available->count()];
            $index++;

            $suggestions[] = [
                'record_uuid' => $record->uuid,
                'reference' => $record->reference,
                'title' => $record->title,
                'stage' => $record->stage,
                'to_officer_id' => $target['officer_id'],
                'to_officer' => $target['officer_name'] ?? null,
            ];
        }

        return $suggestions;
    }

    private function averageProcessingHours(int $companyId, int $officerId): ?int
    {
        $average = DB::table('procgov_stage_events as e')
            ->join('procgov_records as r', 'r.id', '=', 'e.record_id')
            ->where('e.company_id', $companyId)
            ->where('r.officer_id', $officerId)
            ->whereNotNull('e.seconds_in_from_stage')
            ->avg('e.seconds_in_from_stage');

        return $average ? (int) round(((float) $average) / 3600) : null;
    }
}
