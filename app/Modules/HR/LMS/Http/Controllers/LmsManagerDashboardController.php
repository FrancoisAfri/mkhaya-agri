<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\LMS\Models\LmsCertificate;
use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use App\Modules\HR\LMS\Models\LmsDevelopmentTrainingPlan;
use App\Modules\HR\LMS\Models\LmsEmployeeSkillGap;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsQuizAttempt;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsManagerDashboardController extends Controller
{
    public function index(): Response
    {
        $manager    = Auth::user()->load('person');
        $teamUserIds = $this->resolveTeamUserIds($manager);

        if ($teamUserIds->isEmpty()) {
            return Inertia::render('Modules/LMS/ManagerDashboard', [
                'teamCompletion'    => [],
                'teamUnderstanding' => [],
                'teamFailedTests'   => [],
                'teamSkillGaps'     => [],
                'overdueTraining'   => [],
                'certificationExpiry' => [],
                'assessmentResults' => [],
                'developmentPlans'  => [],
                'teamSummary'       => ['team_size' => 0],
                'teamLearnerships'  => [],
            ]);
        }

        // Shared: name lookup
        $nameMap = HRPerson::whereIn('user_id', $teamUserIds)
            ->get(['user_id', 'first_name', 'surname'])
            ->mapWithKeys(fn ($p) => [$p->user_id => trim("{$p->first_name} {$p->surname}")]);

        $nameOf = fn (int $uid) => $nameMap[$uid] ?? "User #{$uid}";

        // ── Team Training Completion ──────────────────────────────────────────
        $allEnrollments = LmsEnrollment::with('course')
            ->whereIn('user_id', $teamUserIds)
            ->get();

        $teamCompletion = $teamUserIds->map(function (int $uid) use ($allEnrollments, $nameOf) {
            $userEnrollments = $allEnrollments->where('user_id', $uid);
            $total     = $userEnrollments->count();
            $completed = $userEnrollments->where('status', 'completed')->count();
            return [
                'user_id'         => $uid,
                'name'            => $nameOf($uid),
                'total'           => $total,
                'completed'       => $completed,
                'in_progress'     => $userEnrollments->whereIn('status', ['enrolled', 'in_progress'])->count(),
                'not_started'     => $userEnrollments->where('status', 'not_started')->count(),
                'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0,
                'mandatory_done'  => $userEnrollments->filter(fn ($e) => $e->course?->is_mandatory && $e->status === 'completed')->count(),
                'mandatory_total' => $userEnrollments->filter(fn ($e) => $e->course?->is_mandatory)->count(),
            ];
        })->sortBy('completion_rate')->values()->all();

        // ── Team Understanding Scores ─────────────────────────────────────────
        $understandingRows = LmsUnderstandingScore::with('course')
            ->whereIn('user_id', $teamUserIds)
            ->get();

        $teamUnderstanding = $teamUserIds->map(function (int $uid) use ($understandingRows, $nameOf) {
            $rows    = $understandingRows->where('user_id', $uid);
            $avg     = $rows->isNotEmpty() ? round($rows->avg('total_score'), 1) : null;
            $grades  = $rows->pluck('grade')->countBy()->sortByDesc(fn ($v) => $v)->keys()->first();
            return [
                'user_id'      => $uid,
                'name'         => $nameOf($uid),
                'avg_score'    => $avg,
                'top_grade'    => $grades,
                'course_count' => $rows->count(),
                'refresher_needed' => $rows->where('refresher_recommended', true)->count(),
                'flag_count'   => $rows->filter(fn ($s) =>
                    $s->guessing_detected || $s->rushed_detected || $s->low_engagement_detected
                )->count(),
                'scores'       => $rows->map(fn ($s) => [
                    'course'      => $s->course?->title,
                    'total_score' => $s->total_score,
                    'grade'       => $s->grade,
                ])->values()->all(),
            ];
        })->sortBy('avg_score')->values()->all();

        // ── Team Failed Tests ─────────────────────────────────────────────────
        $failedAttempts = LmsQuizAttempt::join('lms_enrollments', 'lms_quiz_attempts.enrollment_id', '=', 'lms_enrollments.id')
            ->join('lms_courses', 'lms_enrollments.course_id', '=', 'lms_courses.id')
            ->whereIn('lms_enrollments.user_id', $teamUserIds)
            ->where('lms_quiz_attempts.passed', false)
            ->select(
                'lms_enrollments.user_id',
                'lms_courses.id as course_id',
                'lms_courses.title as course_title',
                'lms_quiz_attempts.score',
                'lms_quiz_attempts.attempted_at',
            )
            ->latest('lms_quiz_attempts.attempted_at')
            ->get();

        $teamFailedTests = $failedAttempts
            ->groupBy('user_id')
            ->map(function ($rows, int $uid) use ($nameOf) {
                return [
                    'user_id'     => $uid,
                    'name'        => $nameOf($uid),
                    'fail_count'  => $rows->count(),
                    'attempts'    => $rows->map(fn ($r) => [
                        'course_id'    => $r->course_id,
                        'course_title' => $r->course_title,
                        'score'        => $r->score,
                        'attempted_at' => $r->attempted_at?->toDateString(),
                    ])->values()->all(),
                ];
            })
            ->sortByDesc('fail_count')
            ->values()
            ->all();

        // ── Team Skills Gaps ──────────────────────────────────────────────────
        $skillGapRows = LmsEmployeeSkillGap::open()
            ->with(['skill', 'recommendedCourse'])
            ->whereIn('user_id', $teamUserIds)
            ->get();

        $teamSkillGaps = $teamUserIds
            ->map(function (int $uid) use ($skillGapRows, $nameOf) {
                $gaps = $skillGapRows->where('user_id', $uid);
                return [
                    'user_id'   => $uid,
                    'name'      => $nameOf($uid),
                    'gap_count' => $gaps->count(),
                    'gaps'      => $gaps->map(fn ($g) => [
                        'skill'            => $g->skill?->name,
                        'current_level'    => $g->current_level,
                        'required_level'   => $g->required_level,
                        'gap'              => $g->gap,
                        'recommended_course' => $g->recommendedCourse?->title,
                    ])->values()->all(),
                ];
            })
            ->filter(fn ($r) => $r['gap_count'] > 0)
            ->sortByDesc('gap_count')
            ->values()
            ->all();

        // ── Overdue Training ──────────────────────────────────────────────────
        $overdueEnrollments = LmsEnrollment::with('course')
            ->whereIn('user_id', $teamUserIds)
            ->whereNotIn('status', ['completed'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->get()
            ->map(fn ($e) => [
                'user_id'       => $e->user_id,
                'name'          => $nameOf($e->user_id),
                'course_title'  => $e->course?->title,
                'due_date'      => $e->due_date?->toDateString(),
                'days_overdue'  => (int) now()->diffInDays($e->due_date),
                'is_mandatory'  => (bool) $e->course?->is_mandatory,
                'source'        => 'enrollment',
            ]);

        $overdueCompliance = LmsComplianceAssignment::overdue()
            ->with('course')
            ->whereIn('user_id', $teamUserIds)
            ->get()
            ->map(fn ($a) => [
                'user_id'      => $a->user_id,
                'name'         => $nameOf($a->user_id),
                'course_title' => $a->course?->title ?? '—',
                'due_date'     => $a->due_date?->toDateString(),
                'days_overdue' => $a->daysOverdue(),
                'is_mandatory' => true,
                'source'       => 'compliance',
            ]);

        $overdueTraining = $overdueEnrollments
            ->merge($overdueCompliance)
            ->sortByDesc('days_overdue')
            ->values()
            ->all();

        // ── Certification Expiry ──────────────────────────────────────────────
        $certificationExpiry = LmsCertificate::join('lms_enrollments', 'lms_certificates.enrollment_id', '=', 'lms_enrollments.id')
            ->join('lms_courses', 'lms_enrollments.course_id', '=', 'lms_courses.id')
            ->whereIn('lms_enrollments.user_id', $teamUserIds)
            ->whereNotNull('lms_certificates.expires_at')
            ->where('lms_certificates.expires_at', '<=', now()->addDays(60))
            ->select(
                'lms_enrollments.user_id',
                'lms_certificates.id',
                'lms_certificates.certificate_number',
                'lms_certificates.issued_at',
                'lms_certificates.expires_at',
                'lms_courses.title as course_title',
            )
            ->orderBy('lms_certificates.expires_at')
            ->get()
            ->map(fn ($c) => [
                'user_id'            => $c->user_id,
                'name'               => $nameOf($c->user_id),
                'course_title'       => $c->course_title,
                'certificate_number' => $c->certificate_number,
                'expires_at'         => $c->expires_at?->toDateString(),
                'days_until_expiry'  => (int) now()->diffInDays($c->expires_at, absolute: false),
                'is_expired'         => now()->gt($c->expires_at),
            ])->values()->all();

        // ── Assessment Results ────────────────────────────────────────────────
        $assessmentResults = LmsQuizAttempt::join('lms_enrollments', 'lms_quiz_attempts.enrollment_id', '=', 'lms_enrollments.id')
            ->join('lms_courses', 'lms_enrollments.course_id', '=', 'lms_courses.id')
            ->whereIn('lms_enrollments.user_id', $teamUserIds)
            ->select(
                'lms_enrollments.user_id',
                'lms_courses.id as course_id',
                'lms_courses.title as course_title',
                'lms_quiz_attempts.score',
                'lms_quiz_attempts.passed',
                'lms_quiz_attempts.attempted_at',
            )
            ->latest('lms_quiz_attempts.attempted_at')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'user_id'      => $a->user_id,
                'name'         => $nameOf($a->user_id),
                'course_id'    => $a->course_id,
                'course_title' => $a->course_title,
                'score'        => $a->score,
                'passed'       => (bool) $a->passed,
                'attempted_at' => $a->attempted_at?->toDateString(),
            ])->values()->all();

        // ── Development Plans ─────────────────────────────────────────────────
        $developmentPlans = LmsDevelopmentTrainingPlan::with('employee.person')
            ->whereIn('employee_id', $teamUserIds)
            ->latest()
            ->get()
            ->map(fn ($p) => [
                'id'               => $p->id,
                'user_id'          => $p->employee_id,
                'name'             => $nameOf($p->employee_id),
                'title'            => $p->title,
                'status'           => $p->status,
                'status_color'     => $p->statusColor(),
                'overall_progress' => $p->overall_progress,
                'activities_count' => count($p->lms_activities ?? []),
                'target_date'      => $p->target_completion_date?->toDateString(),
            ])->values()->all();

        // ── Team Summary KPIs ─────────────────────────────────────────────────
        $teamSummary = [
            'team_size'          => $teamUserIds->count(),
            'avg_completion_rate'=> count($teamCompletion) > 0
                ? round(collect($teamCompletion)->avg('completion_rate'), 1)
                : 0,
            'total_overdue'      => count($overdueTraining),
            'total_skill_gaps'   => $skillGapRows->count(),
            'certs_expiring_30'  => collect($certificationExpiry)->where('days_until_expiry', '<=', 30)->count(),
            'failed_tests_total' => $failedAttempts->count(),
        ];

        return Inertia::render('Modules/LMS/ManagerDashboard', [
            'teamCompletion'     => $teamCompletion,
            'teamUnderstanding'  => $teamUnderstanding,
            'teamFailedTests'    => $teamFailedTests,
            'teamSkillGaps'      => $teamSkillGaps,
            'overdueTraining'    => $overdueTraining,
            'certificationExpiry'=> $certificationExpiry,
            'assessmentResults'  => $assessmentResults,
            'developmentPlans'   => $developmentPlans,
            'teamSummary'        => $teamSummary,
            'teamLearnerships'   => $this->teamLearnerships($teamUserIds),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function teamLearnerships(Collection $teamUserIds): array
    {
        if ($teamUserIds->isEmpty()) return [];

        return LmsLearnershipEnrolment::with(['learnership', 'user.person'])
            ->whereIn('user_id', $teamUserIds)
            ->orderByDesc('enrolled_at')
            ->get()
            ->map(fn ($e) => [
                'id'               => $e->id,
                'learnership_name' => $e->learnership?->name ?? '—',
                'employee_name'    => $e->user?->person
                    ? $e->user->person->first_name . ' ' . $e->user->person->surname
                    : "User #{$e->user_id}",
                'status'           => $e->status,
                'enrolled_at'      => $e->enrolled_at?->toDateString(),
                'expected_end_date'=> $e->expected_end_date?->toDateString(),
            ])
            ->all();
    }

    private function resolveTeamUserIds($manager): Collection
    {
        $managerPerson = $manager->person;
        if (! $managerPerson) return collect();

        return HRPerson::where('manager_id', $managerPerson->id)
            ->whereNotNull('user_id')
            ->pluck('user_id');
    }
}
