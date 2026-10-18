<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsAiRecommendation;
use App\Modules\HR\LMS\Models\LmsCertificate;
use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsQuizAttempt;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsEmployeeDashboardController extends Controller
{
    public function index(): Response
    {
        $userId = Auth::id();

        // ── My Courses ────────────────────────────────────────────────────────
        $enrollments = LmsEnrollment::with(['course.category', 'certificate'])
            ->where('user_id', $userId)
            ->latest('enrolled_at')
            ->get();

        $myCourses = $enrollments->map(fn ($e) => [
            'id'           => $e->id,
            'course_id'    => $e->course_id,
            'title'        => $e->course->title,
            'type'         => $e->course->type,
            'category'     => $e->course->category?->name,
            'category_color' => $e->course->category?->color,
            'is_mandatory' => $e->course->is_mandatory,
            'status'       => $e->status,
            'progress_pct' => $e->progress_pct,
            'score'        => $e->score,
            'enrolled_at'  => $e->enrolled_at?->toDateString(),
            'completed_at' => $e->completed_at?->toDateString(),
            'due_date'     => $e->due_date?->toDateString(),
            'has_cert'     => (bool) $e->certificate,
        ])->values()->all();

        // ── Mandatory Training ────────────────────────────────────────────────
        $mandatoryCourses = LmsCourse::where('is_mandatory', true)
            ->where('is_published', true)
            ->with('category')
            ->get();

        $mandatoryTraining = $mandatoryCourses->map(function ($course) use ($enrollments) {
            $enrollment = $enrollments->firstWhere('course_id', $course->id);
            return [
                'course_id'     => $course->id,
                'title'         => $course->title,
                'category'      => $course->category?->name,
                'duration_mins' => $course->duration_mins,
                'status'        => $enrollment?->status ?? 'not_started',
                'progress_pct'  => $enrollment?->progress_pct ?? 0,
                'due_date'      => $enrollment?->due_date?->toDateString(),
                'enrollment_id' => $enrollment?->id,
            ];
        })->sortBy(fn ($m) => match ($m['status']) {
            'not_started' => 0, 'enrolled' => 1, 'in_progress' => 2, default => 3,
        })->values()->all();

        // ── Overdue Training ──────────────────────────────────────────────────
        $overdueEnrollments = LmsEnrollment::with('course')
            ->where('user_id', $userId)
            ->whereNotIn('status', ['completed'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->get()
            ->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'course_id'     => $e->course_id,
                'title'         => $e->course->title,
                'due_date'      => $e->due_date?->toDateString(),
                'days_overdue'  => (int) now()->diffInDays($e->due_date),
                'status'        => $e->status,
                'source'        => 'enrollment',
            ])->values();

        $overdueCompliance = LmsComplianceAssignment::overdue()
            ->where('user_id', $userId)
            ->with('course')
            ->get()
            ->map(fn ($a) => [
                'enrollment_id' => $a->enrollment_id,
                'course_id'     => $a->course_id,
                'title'         => $a->course?->title ?? '—',
                'due_date'      => $a->due_date?->toDateString(),
                'days_overdue'  => $a->daysOverdue(),
                'status'        => $a->status,
                'source'        => 'compliance',
            ])->values();

        $overdueTraining = $overdueEnrollments
            ->merge($overdueCompliance)
            ->unique('course_id')
            ->sortByDesc('days_overdue')
            ->values()
            ->all();

        // ── My Tests ─────────────────────────────────────────────────────────
        $myTests = LmsQuizAttempt::join('lms_enrollments', 'lms_quiz_attempts.enrollment_id', '=', 'lms_enrollments.id')
            ->join('lms_courses', 'lms_enrollments.course_id', '=', 'lms_courses.id')
            ->where('lms_enrollments.user_id', $userId)
            ->select(
                'lms_quiz_attempts.id',
                'lms_quiz_attempts.enrollment_id',
                'lms_quiz_attempts.score',
                'lms_quiz_attempts.passed',
                'lms_quiz_attempts.attempted_at',
                'lms_courses.id as course_id',
                'lms_courses.title as course_title',
            )
            ->latest('lms_quiz_attempts.attempted_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id'           => $a->id,
                'enrollment_id'=> $a->enrollment_id,
                'course_id'    => $a->course_id,
                'course_title' => $a->course_title,
                'score'        => $a->score,
                'passed'       => (bool) $a->passed,
                'attempted_at' => $a->attempted_at?->toDateTimeString(),
            ])->values()->all();

        // ── My Understanding Score ────────────────────────────────────────────
        $myUnderstanding = LmsUnderstandingScore::where('user_id', $userId)
            ->with('course')
            ->latest('calculated_at')
            ->get()
            ->map(fn ($s) => [
                'course_id'   => $s->course_id,
                'course_title'=> $s->course?->title,
                'total_score' => $s->total_score,
                'grade'       => $s->grade,
                'grade_color' => LmsUnderstandingScore::GRADE_COLORS[$s->grade] ?? 'default',
                'flags'       => array_values(array_filter([
                    $s->guessing_detected       ? 'guessing'        : null,
                    $s->rushed_detected         ? 'rushed'          : null,
                    $s->low_engagement_detected ? 'low_engagement'  : null,
                ])),
                'refresher_recommended' => $s->refresher_recommended,
                'calculated_at'         => $s->calculated_at?->toDateString(),
            ])->values()->all();

        // ── My Certificates ───────────────────────────────────────────────────
        $myCertificates = LmsCertificate::join('lms_enrollments', 'lms_certificates.enrollment_id', '=', 'lms_enrollments.id')
            ->join('lms_courses', 'lms_enrollments.course_id', '=', 'lms_courses.id')
            ->where('lms_enrollments.user_id', $userId)
            ->select(
                'lms_certificates.id',
                'lms_certificates.certificate_number',
                'lms_certificates.issued_at',
                'lms_certificates.expires_at',
                'lms_courses.id as course_id',
                'lms_courses.title as course_title',
            )
            ->latest('lms_certificates.issued_at')
            ->get()
            ->map(fn ($c) => [
                'id'                 => $c->id,
                'certificate_number' => $c->certificate_number,
                'course_id'          => $c->course_id,
                'course_title'       => $c->course_title,
                'issued_at'          => $c->issued_at?->toDateString(),
                'expires_at'         => $c->expires_at?->toDateString(),
                'is_expired'         => $c->expires_at && now()->gt($c->expires_at),
                'days_until_expiry'  => $c->expires_at
                    ? (int) now()->diffInDays($c->expires_at, absolute: false)
                    : null,
            ])->values()->all();

        // ── Learning Progress (aggregated) ────────────────────────────────────
        $totalEnrolled   = $enrollments->count();
        $totalCompleted  = $enrollments->where('status', 'completed')->count();
        $totalInProgress = $enrollments->whereIn('status', ['enrolled', 'in_progress'])->count();
        $avgProgress     = $totalEnrolled > 0
            ? round($enrollments->avg('progress_pct'), 1)
            : 0;

        $learningProgress = [
            'total_enrolled'   => $totalEnrolled,
            'total_completed'  => $totalCompleted,
            'total_in_progress'=> $totalInProgress,
            'not_started'      => $totalEnrolled - $totalCompleted - $totalInProgress,
            'completion_rate'  => $totalEnrolled > 0
                ? round($totalCompleted / $totalEnrolled * 100, 1)
                : 0,
            'avg_progress'     => $avgProgress,
            'mandatory_total'  => count($mandatoryTraining),
            'mandatory_done'   => collect($mandatoryTraining)->where('status', 'completed')->count(),
            'certificates_earned' => count($myCertificates),
        ];

        // ── Recommended Courses ───────────────────────────────────────────────
        $recommendedCourses = LmsAiRecommendation::forUser($userId)
            ->pending()
            ->with('course.category')
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'id'           => $r->id,
                'course_id'    => $r->course_id,
                'title'        => $r->course?->title,
                'category'     => $r->course?->category?->name,
                'reason'       => $r->reason ?? null,
                'priority'     => $r->priority ?? null,
                'duration_mins'=> $r->course?->duration_mins,
            ])->values()->all();

        return Inertia::render('Modules/LMS/EmployeeDashboard', [
            'myCourses'        => $myCourses,
            'mandatoryTraining'=> $mandatoryTraining,
            'overdueTraining'  => $overdueTraining,
            'myTests'          => $myTests,
            'myUnderstanding'  => $myUnderstanding,
            'myCertificates'   => $myCertificates,
            'learningProgress' => $learningProgress,
            'recommendedCourses' => $recommendedCourses,
        ]);
    }
}
