<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCertificate;
use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use App\Modules\HR\LMS\Models\LmsEmployeeSkillGap;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsQuizAttempt;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment;
use App\Modules\HR\Hr\Models\HRPerson;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LmsAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $period   = $request->query('period', 'year');
        $courseId = $request->query('course_id');
        $deptId   = $request->query('department_id');

        [$from, $to] = $this->periodDates($period,
            $request->query('date_from'),
            $request->query('date_to')
        );

        return Inertia::render('Modules/LMS/Admin/Analytics', [
            'period'   => $period,
            'dateFrom' => $request->query('date_from'),
            'dateTo'   => $request->query('date_to'),
            'filters'  => [
                'course_id'     => $courseId ? (int) $courseId : null,
                'department_id' => $deptId,
            ],
            'filterOptions' => [
                'courses'     => \App\Modules\HR\LMS\Models\LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']),
                'departments' => HRPerson::select('division_level_1 as id', 'division_level_1 as name')
                    ->whereNotNull('division_level_1')->distinct()->orderBy('division_level_1')->get(),
            ],
            'kpis'              => $this->kpis($from, $to, $courseId, $deptId),
            'enrollmentTrend'   => $this->enrollmentTrend($from, $to, $courseId),
            'completionByCourse'=> $this->completionByCourse($from, $to, $deptId),
            'complianceRate'    => $this->complianceRate($from, $to),
            'quizPassFail'      => $this->quizPassFail($from, $to, $courseId),
            'departmentProgress'=> $this->departmentProgress($from, $to),
            'skillsGapTop'      => $this->skillsGapTop(),
            'topLearners'       => $this->topLearners($from, $to, $deptId),
            'expiringCerts'     => $this->expiringCerts(),
            'learnershipStatus' => $this->learnershipStatus(),
        ]);
    }

    // ── Period helpers ────────────────────────────────────────────────────────

    private function periodDates(string $period, ?string $from, ?string $to): array
    {
        if ($from && $to) {
            return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        }
        return match ($period) {
            'month'    => [now()->startOfMonth(), now()->endOfDay()],
            'quarter'  => [now()->subMonths(3)->startOfDay(), now()->endOfDay()],
            'halfyear' => [now()->subMonths(6)->startOfDay(), now()->endOfDay()],
            'lastyear' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            default    => [now()->startOfYear(), now()->endOfDay()],
        };
    }

    // ── KPIs ──────────────────────────────────────────────────────────────────

    private function kpis(Carbon $from, Carbon $to, ?string $courseId, ?string $deptId): array
    {
        $q = LmsEnrollment::whereBetween('created_at', [$from, $to]);
        if ($courseId) $q->where('course_id', $courseId);
        if ($deptId)   $q->whereHas('user', fn($u) => $u->whereHas('person', fn($p) => $p->where('division_level_1', $deptId)));

        $total      = $q->count();
        $completed  = (clone $q)->where('status', 'completed')->count();
        $inProgress = (clone $q)->where('status', 'in_progress')->count();
        $overdue    = LmsComplianceAssignment::where('status', 'overdue')
            ->whereBetween('due_date', [$from, $to])->count();
        $avgScore   = round((float) (clone $q)->whereNotNull('score')->avg('score'), 1);
        $certIssued = LmsCertificate::whereBetween('issued_at', [$from, $to])->count();
        $rate       = $total > 0 ? round($completed / $total * 100, 1) : 0.0;

        return compact('total', 'completed', 'inProgress', 'overdue', 'avgScore', 'certIssued', 'rate');
    }

    // ── Enrollment trend (monthly) ────────────────────────────────────────────

    private function enrollmentTrend(Carbon $from, Carbon $to, ?string $courseId): array
    {
        $q = LmsEnrollment::select(
                DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status='completed' then 1 else 0 end) as completed"),
                DB::raw("sum(case when status='in_progress' then 1 else 0 end) as in_progress")
            )
            ->whereBetween('created_at', [$from, $to]);
        if ($courseId) $q->where('course_id', $courseId);

        return $q->groupBy('month')->orderBy('month')->get()->map(fn($r) => [
            'month'       => $r->month,
            'total'       => (int) $r->total,
            'completed'   => (int) $r->completed,
            'in_progress' => (int) $r->in_progress,
        ])->all();
    }

    // ── Completion by course (top 10) ─────────────────────────────────────────

    private function completionByCourse(Carbon $from, Carbon $to, ?string $deptId): array
    {
        $q = LmsEnrollment::select(
                'course_id',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status='completed' then 1 else 0 end) as completed")
            )
            ->whereBetween('created_at', [$from, $to])
            ->with('course:id,title');
        if ($deptId) $q->whereHas('user', fn($u) => $u->whereHas('person', fn($p) => $p->where('division_level_1', $deptId)));

        return $q->groupBy('course_id')->orderByDesc('total')->limit(10)->get()
            ->map(fn($r) => [
                'course'          => $r->course?->title ?? "Course #{$r->course_id}",
                'total'           => (int) $r->total,
                'completed'       => (int) $r->completed,
                'completion_rate' => $r->total > 0 ? round($r->completed / $r->total * 100, 1) : 0.0,
            ])->all();
    }

    // ── Compliance rate over time ─────────────────────────────────────────────

    private function complianceRate(Carbon $from, Carbon $to): array
    {
        return LmsComplianceAssignment::select(
                DB::raw("TO_CHAR(assigned_at, 'YYYY-MM') as month"),
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status='completed' then 1 else 0 end) as completed"),
                DB::raw("sum(case when status='overdue' then 1 else 0 end) as overdue")
            )
            ->whereBetween('assigned_at', [$from, $to])
            ->groupBy('month')->orderBy('month')->get()
            ->map(fn($r) => [
                'month'           => $r->month,
                'total'           => (int) $r->total,
                'completed'       => (int) $r->completed,
                'overdue'         => (int) $r->overdue,
                'compliance_rate' => $r->total > 0 ? round($r->completed / $r->total * 100, 1) : 0.0,
            ])->all();
    }

    // ── Quiz pass/fail by course (top 10) ─────────────────────────────────────

    private function quizPassFail(Carbon $from, Carbon $to, ?string $courseId): array
    {
        $q = LmsQuizAttempt::select(
                'lms_enrollments.course_id',
                DB::raw('count(*) as attempts'),
                DB::raw('sum(case when lms_quiz_attempts.passed then 1 else 0 end) as passed'),
                DB::raw('avg(lms_quiz_attempts.score) as avg_score')
            )
            ->join('lms_enrollments', 'lms_enrollments.id', '=', 'lms_quiz_attempts.enrollment_id')
            ->whereBetween('lms_quiz_attempts.created_at', [$from, $to]);
        if ($courseId) $q->where('lms_enrollments.course_id', $courseId);

        return $q->groupBy('lms_enrollments.course_id')->orderByDesc('attempts')->limit(10)->get()
            ->map(fn($r) => [
                'course_id' => $r->course_id,
                'course'    => \App\Modules\HR\LMS\Models\LmsCourse::find($r->course_id)?->title ?? "Course #{$r->course_id}",
                'attempts'  => (int) $r->attempts,
                'passed'    => (int) $r->passed,
                'failed'    => (int) $r->attempts - (int) $r->passed,
                'pass_rate' => $r->attempts > 0 ? round($r->passed / $r->attempts * 100, 1) : 0.0,
                'avg_score' => round((float) $r->avg_score, 1),
            ])->all();
    }

    // ── Department training progress ──────────────────────────────────────────

    private function departmentProgress(Carbon $from, Carbon $to): array
    {
        return LmsEnrollment::select(
                'hr_people.division_level_1 as department',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when lms_enrollments.status='completed' then 1 else 0 end) as completed"),
                DB::raw('avg(lms_enrollments.score) as avg_score')
            )
            ->join('users', 'users.id', '=', 'lms_enrollments.user_id')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->whereBetween('lms_enrollments.created_at', [$from, $to])
            ->whereNotNull('hr_people.division_level_1')
            ->groupBy('hr_people.division_level_1')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn($r) => [
                'department'      => $r->department,
                'total'           => (int) $r->total,
                'completed'       => (int) $r->completed,
                'completion_rate' => $r->total > 0 ? round($r->completed / $r->total * 100, 1) : 0.0,
                'avg_score'       => $r->avg_score ? round((float) $r->avg_score, 1) : null,
            ])->all();
    }

    // ── Top skills gaps ───────────────────────────────────────────────────────

    private function skillsGapTop(): array
    {
        return LmsEmployeeSkillGap::select('skill_id',
                DB::raw('count(*) as affected'),
                DB::raw('avg(gap) as avg_gap'))
            ->open()
            ->with('skill:id,name')
            ->groupBy('skill_id')
            ->orderByDesc('affected')
            ->limit(8)
            ->get()
            ->map(fn($r) => [
                'skill'    => $r->skill?->name ?? "Skill #{$r->skill_id}",
                'affected' => (int) $r->affected,
                'avg_gap'  => round((float) $r->avg_gap, 1),
            ])->all();
    }

    // ── Top learners (by completions in period) ───────────────────────────────

    private function topLearners(Carbon $from, Carbon $to, ?string $deptId): array
    {
        $q = LmsEnrollment::select('user_id', DB::raw('count(*) as completions'),
                DB::raw('avg(score) as avg_score'))
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'completed');
        if ($deptId) $q->whereHas('user', fn($u) => $u->whereHas('person', fn($p) => $p->where('division_level_1', $deptId)));

        return $q->groupBy('user_id')->orderByDesc('completions')->limit(10)->get()
            ->map(function($r) {
                $person = HRPerson::where('user_id', $r->user_id)->first(['first_name', 'surname', 'division_level_1']);
                return [
                    'name'        => $person ? trim("{$person->first_name} {$person->surname}") : "User #{$r->user_id}",
                    'department'  => $person?->division_level_1 ?? '—',
                    'completions' => (int) $r->completions,
                    'avg_score'   => $r->avg_score ? round((float) $r->avg_score, 1) : null,
                ];
            })->all();
    }

    // ── Expiring certifications (next 90 days) ────────────────────────────────

    private function expiringCerts(): array
    {
        return LmsCertificate::with(['enrollment.course:id,title', 'enrollment.user.person'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(90))
            ->orderBy('expires_at')
            ->limit(20)
            ->get()
            ->map(fn($c) => [
                'employee'    => $c->enrollment?->user?->person
                    ? $c->enrollment->user->person->first_name . ' ' . $c->enrollment->user->person->surname
                    : '—',
                'course'      => $c->enrollment?->course?->title ?? '—',
                'expires_at'  => $c->expires_at?->toDateString(),
                'days_left'   => (int) now()->diffInDays($c->expires_at),
            ])->all();
    }

    // ── Learnership status summary ────────────────────────────────────────────

    private function learnershipStatus(): array
    {
        return LmsLearnershipEnrolment::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn($r) => ['status' => $r->status, 'count' => (int) $r->count])
            ->all();
    }
}
