<?php

namespace App\Modules\HR\LMS\Services;

use App\Core\User\Models\User;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsDevelopmentTrainingPlan;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsPaTrainingRecommendation;
use App\Modules\HR\LMS\Models\LmsPromotionReadiness;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentActivity;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentPlan;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeSkill;
use App\Modules\PerformanceAppraisal\Models\PaKpiScore;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LmsPaTrainingIntegrationService
{
    private const LOW_SCORE_THRESHOLD = 60.0;
    private const READINESS_THRESHOLDS = [
        'completion_rate'            => 70.0,
        'pass_rate'                  => 70.0,
        'understanding_avg'          => 65.0,
        'development_completion_pct' => 60.0,
    ];

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Recommend LMS courses for an employee based on low KPI scores and skill gaps.
     */
    public function recommendTrainingFromAppraisal(int $employeeId, int $cycleId, int $actorId): Collection
    {
        $user      = User::with('person')->findOrFail($employeeId);
        $courses   = LmsCourse::where('is_published', true)->get(['id', 'title', 'description']);
        $created   = collect();

        // KPI-based gaps: scores below threshold
        $kpiScores = PaKpiScore::join('pa_employee_kpis', 'pa_kpi_scores.employee_kpi_id', '=', 'pa_employee_kpis.id')
            ->where('pa_employee_kpis.employee_id', $employeeId)
            ->where('pa_employee_kpis.cycle_id', $cycleId)
            ->where('pa_kpi_scores.score_pct', '<', self::LOW_SCORE_THRESHOLD)
            ->select('pa_kpi_scores.*', 'pa_employee_kpis.title as kpi_title')
            ->get();

        foreach ($kpiScores as $score) {
            $matchedCourse = $this->matchCourseByKeywords($courses, $score->kpi_title);
            $priority      = $score->score_pct < 40 ? 'critical' : ($score->score_pct < 50 ? 'high' : 'medium');
            $reason        = "KPI '{$score->kpi_title}' scored {$score->score_pct}% (below " . self::LOW_SCORE_THRESHOLD . "% threshold).";

            $created->push(LmsPaTrainingRecommendation::firstOrCreate(
                ['employee_id' => $employeeId, 'cycle_id' => $cycleId, 'gap_area' => $score->kpi_title, 'status' => 'pending'],
                [
                    'kpi_title'              => $score->kpi_title,
                    'score_pct'              => $score->score_pct,
                    'course_id'              => $matchedCourse?->id,
                    'recommendation_reason'  => $reason,
                    'priority'               => $priority,
                    'created_by'             => $actorId,
                ]
            ));
        }

        // Skill gap–based recommendations
        $skillGaps = PaEmployeeSkill::with('skill')
            ->where('employee_id', $employeeId)
            ->whereColumn('current_level', '<', 'target_level')
            ->get();

        foreach ($skillGaps as $gap) {
            $skillName     = $gap->skill?->name ?? 'Skill';
            $matchedCourse = $this->matchCourseByKeywords($courses, $skillName);
            $diff          = $gap->target_level - $gap->current_level;
            $priority      = $diff >= 3 ? 'high' : ($diff >= 2 ? 'medium' : 'low');
            $reason        = "Skill '{$skillName}' is at level {$gap->current_level} (target: {$gap->target_level}).";

            $created->push(LmsPaTrainingRecommendation::firstOrCreate(
                ['employee_id' => $employeeId, 'cycle_id' => $cycleId, 'gap_area' => $skillName, 'status' => 'pending'],
                [
                    'kpi_title'              => null,
                    'score_pct'              => null,
                    'course_id'              => $matchedCourse?->id,
                    'recommendation_reason'  => $reason,
                    'priority'               => $priority,
                    'created_by'             => $actorId,
                ]
            ));
        }

        return $created;
    }

    /**
     * Create an LMS development training plan linked to a PA development plan.
     * Enrolls the employee in each course and creates PaDevelopmentActivity entries.
     */
    public function createDevelopmentTrainingPlan(int $paDevelopmentPlanId, array $courseIds, int $actorId): LmsDevelopmentTrainingPlan
    {
        $paPlan    = PaDevelopmentPlan::findOrFail($paDevelopmentPlanId);
        $dueDate   = $paPlan->target_completion_date
            ? Carbon::parse($paPlan->target_completion_date)
            : Carbon::now()->addDays(90);

        $activities = [];
        foreach ($courseIds as $courseId) {
            $course     = LmsCourse::find($courseId);
            if (! $course) continue;

            $enrollment = LmsEnrollment::firstOrCreate(
                ['user_id' => $paPlan->employee_id, 'course_id' => $courseId],
                [
                    'status'      => 'not_started',
                    'progress_pct'=> 0,
                    'enrolled_at' => now(),
                    'due_date'    => $dueDate->toDateString(),
                ]
            );

            $activities[] = [
                'course_id'         => $courseId,
                'enrollment_id'     => $enrollment->id,
                'title'             => $course->title,
                'status'            => $enrollment->status,
                'progress_pct'      => $enrollment->progress_pct,
                'understanding_score'=> null,
            ];

            // Create matching PaDevelopmentActivity if not already present
            PaDevelopmentActivity::firstOrCreate(
                [
                    'plan_id'       => $paDevelopmentPlanId,
                    'title'         => $course->title,
                    'activity_type' => 'training',
                ],
                [
                    'description'  => "LMS course: {$course->title}",
                    'target_date'  => $dueDate->toDateString(),
                    'status'       => 'pending',
                    'provider'     => 'LMS',
                ]
            );
        }

        return LmsDevelopmentTrainingPlan::updateOrCreate(
            ['pa_plan_id' => $paDevelopmentPlanId, 'employee_id' => $paPlan->employee_id],
            [
                'manager_id'             => $paPlan->manager_id,
                'cycle_id'               => $paPlan->cycle_id,
                'title'                  => "Training Plan: {$paPlan->title}",
                'status'                 => 'active',
                'lms_activities'         => $activities,
                'overall_progress'       => 0,
                'target_completion_date' => $dueDate->toDateString(),
                'created_by'             => $actorId,
            ]
        );
    }

    /**
     * Sync enrollment statuses back into the LMS development plan.
     */
    public function trackDevelopmentTraining(int $lmsDevelopmentPlanId): LmsDevelopmentTrainingPlan
    {
        $plan       = LmsDevelopmentTrainingPlan::findOrFail($lmsDevelopmentPlanId);
        $activities = $plan->lms_activities ?? [];

        foreach ($activities as &$activity) {
            $enrollment = LmsEnrollment::find($activity['enrollment_id'] ?? null);
            if ($enrollment) {
                $activity['status']       = $enrollment->status;
                $activity['progress_pct'] = $enrollment->progress_pct;
            }
        }
        unset($activity);

        $totalProgress = count($activities) > 0
            ? (int) round(collect($activities)->avg('progress_pct'))
            : 0;

        $allDone = count($activities) > 0 && collect($activities)->every(fn ($a) => $a['status'] === 'completed');

        $plan->update([
            'lms_activities'  => $activities,
            'overall_progress'=> $totalProgress,
            'status'          => $allDone ? 'completed' : $plan->status,
        ]);

        return $plan->fresh();
    }

    /**
     * Sync understanding scores into the LMS development plan activities.
     */
    public function trackDevelopmentUnderstanding(int $lmsDevelopmentPlanId): LmsDevelopmentTrainingPlan
    {
        $plan       = LmsDevelopmentTrainingPlan::findOrFail($lmsDevelopmentPlanId);
        $activities = $plan->lms_activities ?? [];

        foreach ($activities as &$activity) {
            $score = LmsUnderstandingScore::where('user_id', $plan->employee_id)
                ->where('course_id', $activity['course_id'] ?? null)
                ->latest()
                ->value('total_score');

            $activity['understanding_score'] = $score;
        }
        unset($activity);

        $plan->update(['lms_activities' => $activities]);

        return $plan->fresh();
    }

    /**
     * Build a structured training progress report for a manager.
     */
    public function reportTrainingProgressToManager(int $employeeId, int $cycleId): array
    {
        $user = User::with('person')->findOrFail($employeeId);
        $name = $user->person ? trim("{$user->person->first_name} {$user->person->surname}") : $user->email;

        $devPlan = LmsDevelopmentTrainingPlan::where('employee_id', $employeeId)
            ->where('cycle_id', $cycleId)
            ->first();

        $recommendations = LmsPaTrainingRecommendation::where('employee_id', $employeeId)
            ->where('cycle_id', $cycleId)
            ->with('course')
            ->get();

        $enrollments = LmsEnrollment::where('user_id', $employeeId)
            ->with('course')
            ->get();

        $completedCount = $enrollments->where('status', 'completed')->count();
        $totalCount     = $enrollments->count();
        $completionRate = $totalCount > 0 ? round($completedCount / $totalCount * 100, 1) : 0;

        $passed    = $enrollments->where('status', 'completed')->filter(fn ($e) => ($e->score ?? 0) >= 60)->count();
        $passRate  = $completedCount > 0 ? round($passed / $completedCount * 100, 1) : 0;

        $understandingScores = LmsUnderstandingScore::where('user_id', $employeeId)->pluck('total_score');
        $understandingAvg    = $understandingScores->isNotEmpty() ? round($understandingScores->avg(), 1) : null;

        return [
            'employee_name'      => $name,
            'cycle_id'           => $cycleId,
            'completion_rate'    => $completionRate,
            'pass_rate'          => $passRate,
            'understanding_avg'  => $understandingAvg,
            'total_enrollments'  => $totalCount,
            'completed_count'    => $completedCount,
            'recommendations'    => $recommendations->map(fn ($r) => [
                'gap_area'  => $r->gap_area,
                'course'    => $r->course?->title,
                'status'    => $r->status,
                'priority'  => $r->priority,
            ])->values()->all(),
            'development_plan'   => $devPlan ? [
                'title'            => $devPlan->title,
                'status'           => $devPlan->status,
                'overall_progress' => $devPlan->overall_progress,
                'activities'       => $devPlan->lms_activities,
            ] : null,
        ];
    }

    /**
     * Compute and persist promotion readiness based on LMS training data.
     */
    public function linkTrainingToPromotionReadiness(int $employeeId, int $cycleId): LmsPromotionReadiness
    {
        $enrollments = LmsEnrollment::where('user_id', $employeeId)->get();
        $total       = $enrollments->count();

        $completionRate = $total > 0
            ? round($enrollments->where('status', 'completed')->count() / $total * 100, 2)
            : 0.0;

        $completed      = $enrollments->where('status', 'completed');
        $passRate       = $completed->count() > 0
            ? round($completed->filter(fn ($e) => ($e->score ?? 0) >= 60)->count() / $completed->count() * 100, 2)
            : 0.0;

        $understandingAvg = LmsUnderstandingScore::where('user_id', $employeeId)->avg('total_score');
        $understandingAvg = $understandingAvg ? round((float) $understandingAvg, 2) : 0.0;

        $devPlan = LmsDevelopmentTrainingPlan::where('employee_id', $employeeId)
            ->where('cycle_id', $cycleId)
            ->first();
        $devCompletionPct = $devPlan ? (float) $devPlan->overall_progress : 0.0;

        // Weighted composite: completion 30%, pass 30%, understanding 25%, dev plan 15%
        $overallScore = round(
            $completionRate * 0.30 +
            $passRate       * 0.30 +
            $understandingAvg * 0.25 +
            $devCompletionPct * 0.15,
            2
        );

        $isReady = $completionRate >= self::READINESS_THRESHOLDS['completion_rate']
            && $passRate          >= self::READINESS_THRESHOLDS['pass_rate']
            && $understandingAvg  >= self::READINESS_THRESHOLDS['understanding_avg']
            && $devCompletionPct  >= self::READINESS_THRESHOLDS['development_completion_pct'];

        $notes = $this->buildReadinessNotes($completionRate, $passRate, $understandingAvg, $devCompletionPct);

        return LmsPromotionReadiness::updateOrCreate(
            ['user_id' => $employeeId, 'cycle_id' => $cycleId],
            [
                'overall_training_score'    => $overallScore,
                'completion_rate'           => $completionRate,
                'pass_rate'                 => $passRate,
                'understanding_avg'         => $understandingAvg,
                'development_completion_pct'=> $devCompletionPct,
                'is_promotion_ready'        => $isReady,
                'readiness_notes'           => $notes,
                'computed_at'               => now(),
            ]
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function matchCourseByKeywords(Collection $courses, string $keywords): ?LmsCourse
    {
        $words = array_filter(preg_split('/\s+/', strtolower($keywords)));
        if (empty($words)) return null;

        return $courses->first(function (LmsCourse $course) use ($words) {
            $haystack = strtolower($course->title . ' ' . ($course->description ?? ''));
            foreach ($words as $word) {
                if (strlen($word) >= 3 && str_contains($haystack, $word)) {
                    return true;
                }
            }
            return false;
        });
    }

    private function buildReadinessNotes(
        float $completionRate,
        float $passRate,
        float $understandingAvg,
        float $devPct
    ): string {
        $issues = [];
        if ($completionRate < self::READINESS_THRESHOLDS['completion_rate'])
            $issues[] = "completion rate {$completionRate}% (need " . self::READINESS_THRESHOLDS['completion_rate'] . "%)";
        if ($passRate < self::READINESS_THRESHOLDS['pass_rate'])
            $issues[] = "pass rate {$passRate}% (need " . self::READINESS_THRESHOLDS['pass_rate'] . "%)";
        if ($understandingAvg < self::READINESS_THRESHOLDS['understanding_avg'])
            $issues[] = "understanding avg {$understandingAvg}% (need " . self::READINESS_THRESHOLDS['understanding_avg'] . "%)";
        if ($devPct < self::READINESS_THRESHOLDS['development_completion_pct'])
            $issues[] = "development plan {$devPct}% (need " . self::READINESS_THRESHOLDS['development_completion_pct'] . "%)";

        return empty($issues)
            ? 'All training thresholds met. Candidate is training-ready for promotion consideration.'
            : 'Training gaps: ' . implode('; ', $issues) . '.';
    }
}
