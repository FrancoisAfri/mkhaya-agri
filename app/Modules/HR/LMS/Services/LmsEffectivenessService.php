<?php

namespace App\Modules\HR\LMS\Services;

use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEffectivenessReport;
use App\Modules\HR\LMS\Models\LmsEffectivenessSnapshot;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsQuestionnaireAttempt;
use App\Modules\HR\LMS\Models\LmsTrainingCost;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LmsEffectivenessService
{
    // ── Completion Rate ────────────────────────────────────────────────────────

    public function calculateTrainingCompletionRate(int $courseId, array $filters = []): array
    {
        $query = LmsEnrollment::where('course_id', $courseId);
        $query = $this->applyDateFilters($query, 'created_at', $filters);

        $total     = (clone $query)->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        $inProgress= (clone $query)->where('status', 'in_progress')->count();
        $notStarted= (clone $query)->where('status', 'not_started')->count();

        return [
            'total'          => $total,
            'completed'      => $completed,
            'in_progress'    => $inProgress,
            'not_started'    => $notStarted,
            'completion_rate'=> $total > 0 ? round($completed / $total * 100, 2) : 0,
        ];
    }

    // ── Pass Rate ──────────────────────────────────────────────────────────────

    public function calculateTrainingPassRate(int $courseId, array $filters = []): array
    {
        $enrollmentIds = LmsEnrollment::where('course_id', $courseId)->pluck('id');

        $attemptsQuery = LmsQuestionnaireAttempt::whereIn('enrollment_id', $enrollmentIds)
            ->whereNotNull('completed_at');
        $attemptsQuery = $this->applyDateFilters($attemptsQuery, 'completed_at', $filters);

        // One final attempt per enrollment (highest attempt_number)
        $finalAttempts = (clone $attemptsQuery)
            ->selectRaw('DISTINCT ON (enrollment_id) enrollment_id, score, status')
            ->orderByRaw('enrollment_id, attempt_number DESC')
            ->get();

        $total  = $finalAttempts->count();
        $passed = $finalAttempts->where('status', 'passed')->count();
        $failed = $finalAttempts->where('status', 'failed')->count();
        $avgScore = $total > 0 ? round($finalAttempts->avg('score'), 2) : null;

        // Repeated failure: users who failed more than once
        $repeatedFailures = (clone $attemptsQuery)
            ->where('status', 'failed')
            ->selectRaw('enrollment_id, COUNT(*) as fail_count')
            ->groupBy('enrollment_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        return [
            'total_attempts'       => $total,
            'passed'               => $passed,
            'failed'               => $failed,
            'pass_rate'            => $total > 0 ? round($passed / $total * 100, 2) : 0,
            'avg_score'            => $avgScore,
            'repeated_failure_rate'=> $total > 0 ? round($repeatedFailures / $total * 100, 2) : 0,
            'repeated_failures'    => $repeatedFailures,
        ];
    }

    // ── Understanding Score Improvement ───────────────────────────────────────

    public function calculateUnderstandingImprovement(int $courseId, array $filters = []): array
    {
        $enrollmentIds = LmsEnrollment::where('course_id', $courseId)->pluck('id');

        $scoresQuery = LmsUnderstandingScore::whereIn('enrollment_id', $enrollmentIds);
        $scoresQuery = $this->applyDateFilters($scoresQuery, 'created_at', $filters);

        // First and latest score per user
        $firstScores  = (clone $scoresQuery)
            ->selectRaw('DISTINCT ON (user_id) user_id, total_score as score, created_at')
            ->orderByRaw('user_id, created_at ASC')
            ->get()
            ->keyBy('user_id');

        $latestScores = (clone $scoresQuery)
            ->selectRaw('DISTINCT ON (user_id) user_id, total_score as score, created_at')
            ->orderByRaw('user_id, created_at DESC')
            ->get()
            ->keyBy('user_id');

        $improvements = [];
        foreach ($latestScores as $userId => $latest) {
            $first = $firstScores[$userId] ?? null;
            if ($first && $first->created_at != $latest->created_at) {
                $improvements[] = $latest->score - $first->score;
            }
        }

        $avgFirst  = $firstScores->avg('score');
        $avgLatest = $latestScores->avg('score');
        $avgImprovement = count($improvements) > 0 ? round(array_sum($improvements) / count($improvements), 2) : 0;

        return [
            'users_with_scores'  => $latestScores->count(),
            'avg_first_score'    => round($avgFirst ?? 0, 2),
            'avg_latest_score'   => round($avgLatest ?? 0, 2),
            'avg_improvement'    => $avgImprovement,
            'improved_count'     => count(array_filter($improvements, fn ($d) => $d > 0)),
            'declined_count'     => count(array_filter($improvements, fn ($d) => $d < 0)),
        ];
    }

    // ── Post-Training Performance Improvement ─────────────────────────────────

    public function measurePostTrainingImprovement(int $courseId, array $filters = []): array
    {
        $enrollments = LmsEnrollment::where('course_id', $courseId)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->get(['user_id', 'completed_at']);

        if ($enrollments->isEmpty()) {
            return ['users_compared' => 0, 'avg_before' => null, 'avg_after' => null, 'delta' => null];
        }

        $deltas = [];

        foreach ($enrollments as $enrollment) {
            $userId      = $enrollment->user_id;
            $completedAt = $enrollment->completed_at;

            $before = PaPerformanceSnapshot::where('employee_id', $userId)
                ->where('snapshot_date', '<', $completedAt->toDateString())
                ->orderByDesc('snapshot_date')
                ->value('final_score');

            $after = PaPerformanceSnapshot::where('employee_id', $userId)
                ->where('snapshot_date', '>=', $completedAt->toDateString())
                ->orderBy('snapshot_date')
                ->value('final_score');

            if ($before !== null && $after !== null) {
                $deltas[] = ['before' => (float) $before, 'after' => (float) $after, 'delta' => (float) $after - (float) $before];
            }
        }

        if (empty($deltas)) {
            return ['users_compared' => 0, 'avg_before' => null, 'avg_after' => null, 'delta' => null];
        }

        $avgBefore = round(collect($deltas)->avg('before'), 2);
        $avgAfter  = round(collect($deltas)->avg('after'), 2);

        return [
            'users_compared' => count($deltas),
            'avg_before'     => $avgBefore,
            'avg_after'      => $avgAfter,
            'delta'          => round($avgAfter - $avgBefore, 2),
            'improved_count' => collect($deltas)->filter(fn ($d) => $d['delta'] > 0)->count(),
            'declined_count' => collect($deltas)->filter(fn ($d) => $d['delta'] < 0)->count(),
        ];
    }

    // ── Compare Training to Appraisal Performance ─────────────────────────────

    public function compareTrainingToPerformance(int $courseId, ?int $cycleId = null): array
    {
        $enrollments = LmsEnrollment::where('course_id', $courseId)
            ->where('status', 'completed')
            ->pluck('user_id');

        if ($enrollments->isEmpty()) {
            return ['trained_avg' => null, 'untrained_avg' => null, 'gap' => null];
        }

        $snapshotQuery = PaPerformanceSnapshot::whereNotNull('final_score');
        if ($cycleId) {
            $snapshotQuery->where('cycle_id', $cycleId);
        }

        $trainedAvg = (clone $snapshotQuery)
            ->whereIn('employee_id', $enrollments)
            ->avg('final_score');

        $untrainedAvg = (clone $snapshotQuery)
            ->whereNotIn('employee_id', $enrollments)
            ->avg('final_score');

        return [
            'trained_count'    => $enrollments->count(),
            'trained_avg'      => $trainedAvg !== null ? round((float) $trainedAvg, 2) : null,
            'untrained_avg'    => $untrainedAvg !== null ? round((float) $untrainedAvg, 2) : null,
            'gap'              => ($trainedAvg !== null && $untrainedAvg !== null)
                ? round((float) $trainedAvg - (float) $untrainedAvg, 2)
                : null,
            'cycle_id'         => $cycleId,
        ];
    }

    // ── Incident Reduction (disciplinary_cases as proxy) ──────────────────────

    public function compareTrainingToIncidentReduction(int $courseId, array $filters = []): array
    {
        $enrollments = LmsEnrollment::where('course_id', $courseId)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->get(['user_id', 'completed_at']);

        if ($enrollments->isEmpty()) {
            return ['users_compared' => 0, 'avg_before' => null, 'avg_after' => null, 'incident_delta' => null];
        }

        $window = 180; // days each side of completion date
        $beforeCounts = [];
        $afterCounts  = [];

        foreach ($enrollments as $enrollment) {
            $userId  = $enrollment->user_id;
            $pivot   = $enrollment->completed_at;

            $before = DisciplinaryCase::where('employee_id', $userId)
                ->whereBetween('created_at', [$pivot->copy()->subDays($window), $pivot])
                ->count();

            $after = DisciplinaryCase::where('employee_id', $userId)
                ->whereBetween('created_at', [$pivot, $pivot->copy()->addDays($window)])
                ->count();

            $beforeCounts[] = $before;
            $afterCounts[]  = $after;
        }

        $avgBefore = count($beforeCounts) > 0 ? round(array_sum($beforeCounts) / count($beforeCounts), 2) : 0;
        $avgAfter  = count($afterCounts)  > 0 ? round(array_sum($afterCounts)  / count($afterCounts), 2)  : 0;

        return [
            'users_compared'  => $enrollments->count(),
            'avg_before'      => $avgBefore,
            'avg_after'       => $avgAfter,
            'incident_delta'  => round($avgAfter - $avgBefore, 2),
            'window_days'     => $window,
        ];
    }

    // ── Training ROI ──────────────────────────────────────────────────────────

    public function calculateTrainingROI(int $courseId, array $filters = []): array
    {
        $completionData   = $this->calculateTrainingCompletionRate($courseId, $filters);
        $performanceData  = $this->measurePostTrainingImprovement($courseId, $filters);
        $incidentData     = $this->compareTrainingToIncidentReduction($courseId, $filters);

        // Most recent active cost record for this course
        $costRecord = LmsTrainingCost::where('course_id', $courseId)
            ->orderByDesc('period_start')
            ->first();

        $costPerEmployee     = $costRecord ? (float) $costRecord->cost_per_employee : 0;
        $completedCount      = $completionData['completed'];
        $totalTrainingCost   = $costPerEmployee * $completedCount;

        // Performance gain value: each % improvement in final_score = ZAR 500 (configurable proxy)
        $performanceGainPct  = $performanceData['delta'] ?? 0;
        $performanceGainValue= $performanceGainPct * 500 * $completedCount;

        // Incident reduction value: each incident avoided = ZAR 10 000 proxy
        $incidentDelta       = $incidentData['incident_delta'] ?? 0;
        $incidentSavings     = max(0, -$incidentDelta) * 10000 * $completedCount;

        $totalBenefit = $performanceGainValue + $incidentSavings;
        $roiPct       = $totalTrainingCost > 0
            ? round(($totalBenefit - $totalTrainingCost) / $totalTrainingCost * 100, 2)
            : null;

        return [
            'completed_count'      => $completedCount,
            'cost_per_employee'    => $costPerEmployee,
            'total_training_cost'  => round($totalTrainingCost, 2),
            'performance_gain_pct' => $performanceGainPct,
            'performance_gain_value'=> round($performanceGainValue, 2),
            'incident_delta'       => $incidentDelta,
            'incident_savings'     => round($incidentSavings, 2),
            'total_benefit'        => round($totalBenefit, 2),
            'roi_pct'              => $roiPct,
            'currency'             => $costRecord?->currency ?? 'ZAR',
        ];
    }

    // ── Full Effectiveness Report ──────────────────────────────────────────────

    public function generateTrainingEffectivenessReport(array $params): LmsEffectivenessReport
    {
        $courseId    = $params['course_id'] ?? null;
        $periodStart = $params['period_start'] ?? null;
        $periodEnd   = $params['period_end']   ?? null;
        $actorId     = $params['generated_by'] ?? null;
        $scopeType   = $params['scope_type']   ?? 'course';
        $scopeId     = $params['scope_id']     ?? $courseId;

        $filters = array_filter(['period_start' => $periodStart, 'period_end' => $periodEnd]);

        $metrics = [];

        if ($courseId) {
            $metrics['completion']     = $this->calculateTrainingCompletionRate($courseId, $filters);
            $metrics['pass_rate']      = $this->calculateTrainingPassRate($courseId, $filters);
            $metrics['understanding']  = $this->calculateUnderstandingImprovement($courseId, $filters);
            $metrics['performance']    = $this->measurePostTrainingImprovement($courseId, $filters);
            $metrics['vs_appraisal']   = $this->compareTrainingToPerformance($courseId);
            $metrics['vs_incidents']   = $this->compareTrainingToIncidentReduction($courseId, $filters);
            $metrics['roi']            = $this->calculateTrainingROI($courseId, $filters);

            // Persist snapshot
            LmsEffectivenessSnapshot::updateOrCreate(
                ['course_id' => $courseId, 'period_start' => $periodStart, 'period_end' => $periodEnd],
                [
                    'completion_rate'                 => $metrics['completion']['completion_rate'],
                    'pass_rate'                       => $metrics['pass_rate']['pass_rate'],
                    'avg_score'                       => $metrics['pass_rate']['avg_score'],
                    'avg_understanding_score'         => $metrics['understanding']['avg_latest_score'],
                    'understanding_improvement'       => $metrics['understanding']['avg_improvement'],
                    'repeated_failure_rate'           => $metrics['pass_rate']['repeated_failure_rate'],
                    'post_training_performance_delta' => $metrics['performance']['delta'],
                    'appraisal_delta'                 => $metrics['vs_appraisal']['gap'],
                    'incident_delta'                  => $metrics['vs_incidents']['incident_delta'],
                    'roi_pct'                         => $metrics['roi']['roi_pct'],
                    'computed_at'                     => now(),
                    'snapshot_data'                   => $metrics,
                ]
            );
        }

        $course = $courseId ? LmsCourse::find($courseId) : null;
        $title  = ($course ? $course->title . ' — ' : '') . 'Effectiveness Report';
        if ($periodStart) {
            $title .= ' (' . $periodStart . ' to ' . ($periodEnd ?? 'present') . ')';
        }

        return LmsEffectivenessReport::create([
            'title'        => $title,
            'report_type'  => 'course',
            'scope_type'   => $scopeType,
            'scope_id'     => $scopeId,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'metrics'      => $metrics,
            'generated_by' => $actorId,
            'generated_at' => now(),
        ]);
    }

    // ── Dashboard aggregation ─────────────────────────────────────────────────

    public function getDashboard(array $filters = []): array
    {
        $courseId = $filters['course_id'] ?? null;

        // Latest snapshots per course (or filtered to one course)
        $snapshotsQuery = LmsEffectivenessSnapshot::with('course')
            ->orderByDesc('computed_at');

        if ($courseId) {
            $snapshotsQuery->where('course_id', $courseId);
        }

        $snapshots = $snapshotsQuery->limit(50)->get();

        // KPI averages across all courses with snapshots
        $kpi = [
            'avg_completion_rate'     => round($snapshots->avg('completion_rate') ?? 0, 1),
            'avg_pass_rate'           => round($snapshots->avg('pass_rate') ?? 0, 1),
            'avg_understanding_score' => round($snapshots->avg('avg_understanding_score') ?? 0, 1),
            'avg_roi_pct'             => round($snapshots->avg('roi_pct') ?? 0, 1),
            'courses_with_data'       => $snapshots->count(),
        ];

        // Per-course table rows
        $courseRows = $snapshots->map(fn ($s) => [
            'course_id'          => $s->course_id,
            'course_title'       => $s->course?->title ?? '—',
            'completion_rate'    => $s->completion_rate,
            'pass_rate'          => $s->pass_rate,
            'avg_score'          => $s->avg_score,
            'understanding_imp'  => $s->understanding_improvement,
            'performance_delta'  => $s->post_training_performance_delta,
            'roi_pct'            => $s->roi_pct,
            'computed_at'        => $s->computed_at?->toDateString(),
        ])->values();

        // Recent reports
        $recentReports = LmsEffectivenessReport::with('generatedBy')
            ->orderByDesc('generated_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'           => $r->id,
                'title'        => $r->title,
                'report_type'  => $r->report_type,
                'period_start' => $r->period_start?->toDateString(),
                'period_end'   => $r->period_end?->toDateString(),
                'generated_by' => $r->generatedBy?->person
                    ? $r->generatedBy->person->first_name . ' ' . $r->generatedBy->person->surname
                    : '—',
                'generated_at' => $r->generated_at?->toDateTimeString(),
                'metrics'      => $r->metrics,
            ]);

        return compact('kpi', 'courseRows', 'recentReports');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function applyDateFilters($query, string $column, array $filters)
    {
        if (!empty($filters['period_start'])) {
            $query->where($column, '>=', $filters['period_start']);
        }
        if (!empty($filters['period_end'])) {
            $query->where($column, '<=', $filters['period_end']);
        }
        return $query;
    }
}
