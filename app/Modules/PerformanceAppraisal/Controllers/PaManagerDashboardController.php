<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\Pa360Review;
use App\Modules\PerformanceAppraisal\Models\PaBehaviorSignal;
use App\Modules\PerformanceAppraisal\Models\PaCheckin;
use App\Modules\PerformanceAppraisal\Models\PaCoachingSession;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentPlan;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use App\Modules\PerformanceAppraisal\Models\PaPipPlan;
use App\Modules\PerformanceAppraisal\Models\PaProbationReview;
use App\Modules\PerformanceAppraisal\Models\PaWellnessScore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaManagerDashboardController extends Controller
{
    private const MANAGER_ROLES = [
        'team leader', 'supervisor', 'manager', 'compliance officer',
        'senior', 'senior manager', 'executive', 'HR',
        'super admin', 'administrator',
    ];

    public function index(): Response
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user->hasRole(self::MANAGER_ROLES), 403, 'Team dashboard is only available to managers and above.');

        $myPersonId = DB::table('hr_people')->where('user_id', $user->id)->value('id');

        // Direct reports (one level)
        $directReportUserIds = [];
        // Full subtree (BFS, all levels down)
        $allSubtreeUserIds   = [];

        if ($myPersonId) {
            // Direct report person IDs
            $directPersonIds = DB::table('hr_people')
                ->where('manager_id', $myPersonId)
                ->pluck('id')
                ->toArray();

            $directReportUserIds = DB::table('hr_people')
                ->whereIn('id', $directPersonIds)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->toArray();

            // BFS to collect entire subtree
            $visitedPersonIds = array_merge([$myPersonId], $directPersonIds);
            $queue            = $directPersonIds;

            while (!empty($queue)) {
                $children    = DB::table('hr_people')->whereIn('manager_id', $queue)->pluck('id')->toArray();
                $newChildren = array_values(array_diff($children, $visitedPersonIds));
                if (empty($newChildren)) break;
                $visitedPersonIds = array_merge($visitedPersonIds, $newChildren);
                $queue            = $newChildren;
            }

            // Exclude the manager themselves
            $allSubtreePersonIds = array_values(array_diff($visitedPersonIds, [$myPersonId]));
            $allSubtreeUserIds   = DB::table('hr_people')
                ->whereIn('id', $allSubtreePersonIds)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->toArray();
        }

        $directReportSet = array_flip($directReportUserIds);

        $cycles = PaCycle::orderByDesc('start_date')->get(['id', 'name', 'status']);

        $employees = User::with('person')
            ->whereIn('id', $allSubtreeUserIds)
            ->get();

        $reports = $employees->map(fn($emp) => $this->buildEmployeeSummary($emp, isset($directReportSet[$emp->id])))->values();

        // ── Team wellness & burnout ────────────────────────────────────────────
        $wellnessData = PaWellnessScore::whereIn('employee_id', $allSubtreeUserIds)
            ->with('employee.person')
            ->orderByDesc('created_at')
            ->get()
            ->unique('employee_id')  // latest per employee
            ->map(fn($w) => [
                'name'          => $this->resolveEmployeeName($w->employee),
                'wellness_score'=> $w->wellness_score,
                'wellness_grade'=> $w->wellness_grade,
                'burnout_risk'  => $w->burnout_risk,
                'kpi_score'     => $w->kpi_score,
                'feedback_score'=> $w->feedback_score,
                'checkin_score' => $w->checkin_score,
            ])
            ->values();

        // Unacknowledged high-severity behavior signals
        $signals = PaBehaviorSignal::whereIn('employee_id', $allSubtreeUserIds)
            ->whereNull('acknowledged_at')
            ->whereIn('severity', ['high', 'critical'])
            ->with('employee.person')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($s) => [
                'id'          => $s->id,
                'employee'    => $this->resolveEmployeeName($s->employee),
                'signal_type' => $s->signal_type,
                'severity'    => $s->severity,
                'title'       => $s->title,
                'description' => $s->description,
            ]);

        // ── Chart data ────────────────────────────────────────────────────────
        // KPI completion per employee (for bar chart)
        $kpiChart = $reports->map(fn($r) => [
            'name'  => $r['name'],
            'score' => $r['snapshot']['kpi_completion_rate'] ?? 0,
        ])->sortByDesc('score')->values();

        // Burnout risk distribution (for donut chart)
        $burnoutGroups = $wellnessData->groupBy('burnout_risk')->map->count();

        // Pending appraisal approvals for this manager's subtree
        $pendingApprovals = PaPerformanceSnapshot::with(['employee.person', 'cycle'])
            ->when(!empty($allSubtreeUserIds), fn($q) => $q->whereIn('employee_id', $allSubtreeUserIds))
            ->when(empty($allSubtreeUserIds), fn($q) => $q->whereRaw('1=0'))
            ->whereIn('appraisal_status', ['employee_submitted', 'manager_review'])
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn($s) => [
                'id'               => $s->id,
                'employee_name'    => $this->resolveEmployeeName($s->employee),
                'employee_id'      => $s->employee_id,
                'cycle_name'       => $s->cycle?->name,
                'snapshot_date'    => $s->snapshot_date?->format('d M Y'),
                'overall_score'    => $s->overall_score,
                'employee_score'   => $s->employee_score,
                'manager_score'    => $s->manager_score,
                'score_360'        => $s->score_360,
                'final_score'      => $s->final_score,
                'appraisal_status' => $s->appraisal_status,
                'employee_comments'=> $s->employee_comments,
                'manager_comments' => $s->manager_comments,
                'submitted_at'     => $s->submitted_at?->format('d M Y'),
            ]);

        return Inertia::render('Modules/PerformanceAppraisal/ManagerDashboard/Index', [
            'reports'           => $reports,
            'cycles'            => $cycles->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status]),
            'wellness_data'     => $wellnessData,
            'signals'           => $signals->values(),
            'kpi_chart'         => $kpiChart->values(),
            'burnout_chart'     => [
                'labels' => $burnoutGroups->keys()->map(fn($k) => ucfirst($k ?? 'Unknown'))->values(),
                'series' => $burnoutGroups->values(),
            ],
            'pending_approvals' => $pendingApprovals->values(),
        ]);
    }

    private function resolveEmployeeName(?User $user): string
    {
        if (!$user) return 'Unknown';
        $person = $user->person;
        if ($person) {
            $name = trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''));
            if ($name) return $name;
        }
        return $user->email;
    }

    private function buildEmployeeSummary(User $emp, bool $isDirectReport = true): array
    {
        $name = $this->resolveEmployeeName($emp);

        $snapshot = PaPerformanceSnapshot::where('employee_id', $emp->id)
            ->with('cycle:id,name')
            ->latest('snapshot_date')
            ->first();

        $kpis = PaEmployeeKpi::where('employee_id', $emp->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->with(['cycle:id,name', 'score', 'progressLogs' => fn($q) => $q->latest('log_date')])
            ->withCount('progressLogs')
            ->get()
            ->map(fn($k) => [
                'id'                     => $k->id,
                'title'                  => $k->title,
                'target_value'           => $k->target_value,
                'unit'                   => $k->unit,
                'weighting'              => $k->weighting,
                'status'                 => $k->status,
                'cycle'                  => $k->cycle?->name,
                'current'                => $k->latestValue(),
                'score_pct'              => $k->score?->score_pct,
                'manager_update_allowed' => (bool) $k->manager_update_allowed,
                'progress_logs_count'    => $k->progress_logs_count,
                'recent_logs'            => $k->progressLogs->take(3)->map(fn($l) => [
                    'value'    => $l->value,
                    'notes'    => $l->notes,
                    'log_date' => $l->log_date->format('d M Y'),
                ])->values(),
            ]);

        $probation = PaProbationReview::where('employee_id', $emp->id)
            ->whereIn('status', ['active', 'extended', 'on_probation'])
            ->latest()
            ->first();

        $pip = PaPipPlan::where('employee_id', $emp->id)
            ->whereIn('status', ['active', 'extended'])
            ->with('objectives')
            ->latest()
            ->first();

        $checkins = PaCheckin::where('employee_id', $emp->id)
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get()
            ->map(fn($c) => [
                'id'           => $c->id,
                'title'        => $c->title,
                'scheduled_at' => $c->scheduled_at?->format('d M Y H:i'),
                'location'     => $c->location,
            ]);

        $coaching = PaCoachingSession::where('employee_id', $emp->id)
            ->orderByDesc('session_date')
            ->limit(3)
            ->get()
            ->map(fn($s) => [
                'id'           => $s->id,
                'title'        => $s->title,
                'session_date' => $s->session_date?->format('d M Y'),
                'status'       => $s->status,
            ]);

        $feedback = PaFeedback::where('employee_id', $emp->id)
            ->where('is_draft', false)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn($f) => [
                'id'            => $f->id,
                'feedback_type' => $f->feedback_type,
                'content'       => $f->content,
                'rating'        => $f->rating,
                'author'        => $f->is_anonymous ? null : $f->author_name,
                'created_at'    => $f->created_at?->format('d M Y'),
            ]);

        $reviews360 = Pa360Review::where('employee_id', $emp->id)
            ->orderByDesc('created_at')
            ->limit(3)
            ->get()
            ->map(fn($r) => [
                'id'              => $r->id,
                'title'           => $r->title,
                'status'          => $r->status,
                'due_date'        => $r->due_date?->format('d M Y'),
                'completion_rate' => $r->completionRate(),
            ]);

        $devPlans = PaDevelopmentPlan::where('employee_id', $emp->id)
            ->latest()
            ->limit(3)
            ->get()
            ->map(fn($d) => [
                'id'         => $d->id,
                'title'      => $d->title,
                'created_at' => $d->created_at?->format('d M Y'),
            ]);

        return [
            'user_id'          => $emp->id,
            'name'             => $name,
            'email'            => $emp->email,
            'is_direct_report' => $isDirectReport,
            'snapshot'    => $snapshot ? [
                'overall_score'       => $snapshot->overall_score,
                'kpi_completion_rate' => $snapshot->kpi_completion_rate,
                'snapshot_date'       => $snapshot->snapshot_date?->format('d M Y'),
                'cycle'               => $snapshot->cycle?->name,
            ] : null,
            'kpis'        => $kpis->values(),
            'probation'   => $probation ? [
                'id'             => $probation->id,
                'probation_type' => $probation->probation_type,
                'status'         => $probation->status,
                'start_date'     => $probation->start_date?->format('d M Y'),
                'end_date'       => $probation->end_date?->format('d M Y'),
                'days_remaining' => $probation->daysRemaining(),
            ] : null,
            'pip'         => $pip ? [
                'id'               => $pip->id,
                'title'            => $pip->title ?? 'PIP',
                'status'           => $pip->status,
                'overall_progress' => $pip->overallProgress(),
                'days_remaining'   => $pip->daysRemaining(),
                'objectives_met'   => $pip->objectives->where('status', 'met')->count(),
                'objectives_total' => $pip->objectives->count(),
                'start_date'       => $pip->start_date?->format('d M Y'),
                'target_end_date'  => $pip->target_end_date?->format('d M Y'),
            ] : null,
            'checkins'    => $checkins,
            'coaching'    => $coaching,
            'feedback'    => $feedback,
            'reviews_360' => $reviews360,
            'dev_plans'   => $devPlans,
        ];
    }
}
