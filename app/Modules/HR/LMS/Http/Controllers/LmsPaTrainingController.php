<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsDevelopmentTrainingPlan;
use App\Modules\HR\LMS\Models\LmsPaTrainingRecommendation;
use App\Modules\HR\LMS\Models\LmsPromotionReadiness;
use App\Modules\HR\LMS\Services\LmsPaTrainingIntegrationService;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsPaTrainingController extends Controller
{
    private const LOG = 'LMS_PA_Training';

    public function __construct(private readonly LmsPaTrainingIntegrationService $service) {}

    // ── Dashboard ─────────────────────────────────────────────────────────

    public function dashboard(Request $request): Response
    {
        $cycleId = $request->integer('cycle_id') ?: null;

        $readiness = LmsPromotionReadiness::with('user.person')
            ->when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))
            ->latest('computed_at')
            ->paginate(25)
            ->withQueryString();

        $readinessData = $readiness->getCollection()->map(function (LmsPromotionReadiness $r) {
            return [
                'id'                         => $r->id,
                'employee_name'              => $r->user?->person
                    ? trim("{$r->user->person->first_name} {$r->user->person->surname}")
                    : ($r->user?->email ?? '—'),
                'cycle_id'                   => $r->cycle_id,
                'overall_training_score'     => $r->overall_training_score,
                'completion_rate'            => $r->completion_rate,
                'pass_rate'                  => $r->pass_rate,
                'understanding_avg'          => $r->understanding_avg,
                'development_completion_pct' => $r->development_completion_pct,
                'is_promotion_ready'         => $r->is_promotion_ready,
                'readiness_level'            => $r->readinessLevel(),
                'readiness_color'            => $r->readinessColor(),
                'readiness_notes'            => $r->readiness_notes,
                'computed_at'                => $r->computed_at?->toDateTimeString(),
            ];
        })->all();

        $readiness->setCollection(collect($readinessData));

        $summary = [
            'total'        => LmsPromotionReadiness::when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))->count(),
            'ready'        => LmsPromotionReadiness::when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))->where('is_promotion_ready', true)->count(),
            'avg_score'    => round((float) LmsPromotionReadiness::when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))->avg('overall_training_score'), 1),
            'pending_recs' => LmsPaTrainingRecommendation::when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))->where('status', 'pending')->count(),
        ];

        return Inertia::render('Modules/LMS/Admin/PaTrainingIntegration', [
            'readiness' => $readiness,
            'summary'   => $summary,
            'filters'   => $request->only(['cycle_id']),
            'activeTab' => 'readiness',
        ]);
    }

    // ── Recommendations ───────────────────────────────────────────────────

    public function recommendations(Request $request): Response
    {
        $recs = LmsPaTrainingRecommendation::with(['employee.person', 'course'])
            ->when($request->filled('cycle_id'), fn ($q) => $q->where('cycle_id', $request->integer('cycle_id')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->paginate(25)
            ->withQueryString();

        $recData = $recs->getCollection()->map(fn (LmsPaTrainingRecommendation $r) => [
            'id'                    => $r->id,
            'employee_id'           => $r->employee_id,
            'employee_name'         => $r->employee?->person
                ? trim("{$r->employee->person->first_name} {$r->employee->person->surname}")
                : ($r->employee?->email ?? '—'),
            'cycle_id'              => $r->cycle_id,
            'gap_area'              => $r->gap_area,
            'kpi_title'             => $r->kpi_title,
            'score_pct'             => $r->score_pct,
            'course_id'             => $r->course_id,
            'course_title'          => $r->course?->title,
            'recommendation_reason' => $r->recommendation_reason,
            'status'                => $r->status,
            'status_color'          => $r->statusColor(),
            'priority'              => $r->priority,
            'priority_color'        => $r->priorityColor(),
            'enrolled_at'           => $r->enrolled_at?->toDateString(),
            'completed_at'          => $r->completed_at?->toDateString(),
        ])->all();

        $recs->setCollection(collect($recData));

        return Inertia::render('Modules/LMS/Admin/PaTrainingIntegration', [
            'recommendations' => $recs,
            'filters'         => $request->only(['cycle_id', 'employee_id', 'status']),
            'activeTab'       => 'recommendations',
        ]);
    }

    public function recommend(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:users,id',
            'cycle_id'    => 'required|integer',
        ]);

        $recs = $this->service->recommendTrainingFromAppraisal(
            $data['employee_id'],
            $data['cycle_id'],
            Auth::id()
        );

        activity()
            ->useLog(self::LOG)
            ->causedBy(Auth::user())
            ->withProperties([
                'employee_id' => $data['employee_id'],
                'cycle_id'    => $data['cycle_id'],
                'count'       => $recs->count(),
            ])
            ->log("Generated {$recs->count()} PA training recommendation(s) from appraisal gaps for employee #{$data['employee_id']} (cycle {$data['cycle_id']}).");

        return redirect()->back()->with('success',
            "Generated {$recs->count()} training recommendation(s) from appraisal gaps."
        );
    }

    public function updateRecommendation(Request $request, LmsPaTrainingRecommendation $recommendation): RedirectResponse
    {
        $data = $request->validate([
            'status'    => 'required|in:pending,accepted,enrolled,completed,dismissed',
            'course_id' => 'nullable|exists:lms_courses,id',
        ]);

        $previousStatus = $recommendation->status;
        $recommendation->update($data);

        activity()
            ->useLog(self::LOG)
            ->causedBy(Auth::user())
            ->performedOn($recommendation)
            ->withProperties([
                'recommendation_id' => $recommendation->id,
                'employee_id'       => $recommendation->employee_id,
                'gap_area'          => $recommendation->gap_area,
                'previous_status'   => $previousStatus,
                'new_status'        => $data['status'],
                'course_id'         => $data['course_id'] ?? $recommendation->course_id,
            ])
            ->log("PA training recommendation #{$recommendation->id} status changed from '{$previousStatus}' to '{$data['status']}' (gap: {$recommendation->gap_area}).");

        return redirect()->back()->with('success', 'Recommendation updated.');
    }

    // ── Development Plans ─────────────────────────────────────────────────

    public function devPlans(Request $request): Response
    {
        $plans = LmsDevelopmentTrainingPlan::with(['employee.person', 'manager.person'])
            ->when($request->filled('cycle_id'), fn ($q) => $q->where('cycle_id', $request->integer('cycle_id')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $planData = $plans->getCollection()->map(fn (LmsDevelopmentTrainingPlan $p) => [
            'id'               => $p->id,
            'pa_plan_id'       => $p->pa_plan_id,
            'employee_name'    => $p->employee?->person
                ? trim("{$p->employee->person->first_name} {$p->employee->person->surname}")
                : ($p->employee?->email ?? '—'),
            'manager_name'     => $p->manager?->person
                ? trim("{$p->manager->person->first_name} {$p->manager->person->surname}")
                : ($p->manager?->email ?? '—'),
            'title'            => $p->title,
            'status'           => $p->status,
            'status_color'     => $p->statusColor(),
            'overall_progress' => $p->overall_progress,
            'activities_count' => count($p->lms_activities ?? []),
            'target_date'      => $p->target_completion_date?->toDateString(),
        ])->all();

        $plans->setCollection(collect($planData));

        $paPlans = PaDevelopmentPlan::with(['employee.person'])
            ->whereIn('status', ['draft', 'active'])
            ->get(['id', 'title', 'employee_id', 'cycle_id', 'target_completion_date'])
            ->map(fn ($p) => [
                'id'    => $p->id,
                'label' => $p->title . ($p->employee?->person ? ' — ' . trim("{$p->employee->person->first_name} {$p->employee->person->surname}") : ''),
            ]);

        return Inertia::render('Modules/LMS/Admin/PaTrainingIntegration', [
            'devPlans'  => $plans,
            'paPlans'   => $paPlans,
            'filters'   => $request->only(['cycle_id', 'employee_id']),
            'activeTab' => 'dev_plans',
        ]);
    }

    public function createPlan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pa_plan_id'   => 'required|exists:pa_development_plans,id',
            'course_ids'   => 'required|array|min:1',
            'course_ids.*' => 'exists:lms_courses,id',
        ]);

        $plan = $this->service->createDevelopmentTrainingPlan(
            $data['pa_plan_id'],
            $data['course_ids'],
            Auth::id()
        );

        activity()
            ->useLog(self::LOG)
            ->causedBy(Auth::user())
            ->performedOn($plan)
            ->withProperties([
                'lms_plan_id'  => $plan->id,
                'pa_plan_id'   => $data['pa_plan_id'],
                'employee_id'  => $plan->employee_id,
                'course_ids'   => $data['course_ids'],
                'course_count' => count($data['course_ids']),
                'title'        => $plan->title,
            ])
            ->log("LMS development training plan #{$plan->id} created from PA plan #{$data['pa_plan_id']} with " . count($data['course_ids']) . " course(s) for employee #{$plan->employee_id}.");

        return redirect()->back()->with('success',
            "Development training plan created with " . count($data['course_ids']) . " course(s)."
        );
    }

    public function planDetail(int $planId): Response
    {
        $plan = LmsDevelopmentTrainingPlan::with(['employee.person', 'manager.person'])->findOrFail($planId);

        return Inertia::render('Modules/LMS/Admin/PaTrainingIntegration', [
            'devPlanDetail' => [
                'id'               => $plan->id,
                'title'            => $plan->title,
                'status'           => $plan->status,
                'overall_progress' => $plan->overall_progress,
                'lms_activities'   => $plan->lms_activities,
                'notes'            => $plan->notes,
                'target_date'      => $plan->target_completion_date?->toDateString(),
                'employee_name'    => $plan->employee?->person
                    ? trim("{$plan->employee->person->first_name} {$plan->employee->person->surname}")
                    : ($plan->employee?->email ?? '—'),
            ],
            'activeTab' => 'dev_plans',
        ]);
    }

    public function trackPlan(int $planId): RedirectResponse
    {
        $plan = $this->service->trackDevelopmentTraining($planId);
        $this->service->trackDevelopmentUnderstanding($planId);

        activity()
            ->useLog(self::LOG)
            ->causedBy(Auth::user())
            ->performedOn($plan)
            ->withProperties([
                'lms_plan_id'      => $planId,
                'employee_id'      => $plan->employee_id,
                'overall_progress' => $plan->overall_progress,
                'status'           => $plan->status,
            ])
            ->log("LMS development training plan #{$planId} progress synced — {$plan->overall_progress}% complete (employee #{$plan->employee_id}).");

        return redirect()->back()->with('success', 'Development plan progress synced.');
    }

    // ── Progress Report ───────────────────────────────────────────────────

    public function progressReport(Request $request): Response
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:users,id',
            'cycle_id'    => 'required|integer',
        ]);

        $report = $this->service->reportTrainingProgressToManager(
            $data['employee_id'],
            $data['cycle_id']
        );

        activity()
            ->useLog(self::LOG)
            ->causedBy(Auth::user())
            ->withProperties([
                'employee_id'     => $data['employee_id'],
                'cycle_id'        => $data['cycle_id'],
                'completion_rate' => $report['completion_rate'],
                'pass_rate'       => $report['pass_rate'],
            ])
            ->log("Training progress report viewed for employee #{$data['employee_id']} (cycle {$data['cycle_id']}) — completion {$report['completion_rate']}%, pass rate {$report['pass_rate']}%.");

        return Inertia::render('Modules/LMS/Admin/PaTrainingIntegration', [
            'progressReport' => $report,
            'filters'        => $data,
            'activeTab'      => 'progress_report',
        ]);
    }

    // ── Promotion Readiness ───────────────────────────────────────────────

    public function computeReadiness(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:users,id',
            'cycle_id'    => 'required|integer',
        ]);

        $readiness = $this->service->linkTrainingToPromotionReadiness(
            $data['employee_id'],
            $data['cycle_id']
        );

        $level = $readiness->readinessLevel();

        activity()
            ->useLog(self::LOG)
            ->causedBy(Auth::user())
            ->performedOn($readiness)
            ->withProperties([
                'employee_id'           => $data['employee_id'],
                'cycle_id'              => $data['cycle_id'],
                'overall_training_score'=> $readiness->overall_training_score,
                'completion_rate'       => $readiness->completion_rate,
                'pass_rate'             => $readiness->pass_rate,
                'understanding_avg'     => $readiness->understanding_avg,
                'development_pct'       => $readiness->development_completion_pct,
                'is_promotion_ready'    => $readiness->is_promotion_ready,
                'readiness_level'       => $level,
            ])
            ->log("Promotion readiness computed for employee #{$data['employee_id']} (cycle {$data['cycle_id']}): {$readiness->overall_training_score}% — {$level}." . ($readiness->is_promotion_ready ? ' READY.' : ' NOT READY.'));

        return redirect()->back()->with(
            $readiness->is_promotion_ready ? 'success' : 'info',
            "Readiness computed: {$readiness->overall_training_score}% — {$level}."
        );
    }
}
