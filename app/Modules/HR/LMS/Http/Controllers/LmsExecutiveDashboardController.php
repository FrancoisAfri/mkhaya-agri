<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCertificate;
use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use App\Modules\HR\LMS\Models\LmsEffectivenessSnapshot;
use App\Modules\HR\LMS\Models\LmsEmployeeSkillGap;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsPolicyViolation;
use App\Modules\HR\LMS\Models\LmsQuizAttempt;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\HR\LMS\Models\Qcto\LmsAtrReport;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnership;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment;
use App\Modules\HR\LMS\Models\Qcto\LmsNlrdSubmission;
use App\Modules\HR\LMS\Models\Qcto\LmsRegisteredPerson;
use App\Modules\HR\LMS\Models\Qcto\LmsSdf;
use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use App\Modules\HR\LMS\Models\Qcto\LmsWspPlan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LmsExecutiveDashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/LMS/ExecutiveDashboard', [
            'trainingCompliance'       => $this->trainingCompliance(),
            'avgUnderstandingScore'    => $this->avgUnderstandingScore(),
            'highestFailureRates'      => $this->highestFailureRates(),
            'refresherNeeded'          => $this->refresherNeeded(),
            'skillsReadiness'          => $this->skillsReadiness(),
            'mandatoryOverdue'         => $this->mandatoryOverdue(),
            'certificationRisk'        => $this->certificationRisk(),
            'trainingRoi'              => $this->trainingRoi(),
            'departmentCapability'     => $this->departmentCapability(),
            'policyViolations'         => $this->policyViolations(),
            'workforceSkillGaps'       => $this->workforceSkillGaps(),
            'qctoCompliance'           => $this->qctoCompliance(),
        ]);
    }

    private function trainingCompliance(): array
    {
        $total     = LmsComplianceAssignment::count();
        $completed = LmsComplianceAssignment::where('status', 'completed')->count();
        $overdue   = LmsComplianceAssignment::where('status', 'overdue')->count();
        $rate      = $total > 0 ? round($completed / $total * 100, 1) : 0.0;

        $byRule = LmsComplianceAssignment::select('rule_id', DB::raw('count(*) as total'),
                DB::raw("sum(case when status='completed' then 1 else 0 end) as completed"),
                DB::raw("sum(case when status='overdue'   then 1 else 0 end) as overdue"))
            ->with('rule:id,name')
            ->groupBy('rule_id')
            ->orderByDesc('overdue')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'rule_id'        => $r->rule_id,
                'rule_name'      => $r->rule?->name ?? 'Unknown',
                'total'          => (int) $r->total,
                'completed'      => (int) $r->completed,
                'overdue'        => (int) $r->overdue,
                'compliance_rate' => $r->total > 0 ? round($r->completed / $r->total * 100, 1) : 0.0,
            ]);

        return compact('total', 'completed', 'overdue', 'rate', 'byRule');
    }

    private function avgUnderstandingScore(): array
    {
        $overall = (float) LmsUnderstandingScore::avg('total_score') ?? 0.0;

        $perCourse = LmsUnderstandingScore::select('course_id',
                DB::raw('avg(total_score) as avg_score'),
                DB::raw('count(*) as employee_count'),
                DB::raw("sum(case when refresher_recommended then 1 else 0 end) as refresher_count"))
            ->with('course:id,title')
            ->groupBy('course_id')
            ->orderBy('avg_score')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'course_id'       => $r->course_id,
                'course_title'    => $r->course?->title ?? 'Unknown',
                'avg_score'       => round((float) $r->avg_score, 1),
                'employee_count'  => (int) $r->employee_count,
                'refresher_count' => (int) $r->refresher_count,
            ]);

        return ['overall' => round($overall, 1), 'perCourse' => $perCourse];
    }

    private function highestFailureRates(): array
    {
        $rows = LmsQuizAttempt::select(
                'lms_enrollments.course_id',
                DB::raw('count(*) as total_attempts'),
                DB::raw('sum(case when lms_quiz_attempts.passed = false then 1 else 0 end) as failed_attempts')
            )
            ->join('lms_enrollments', 'lms_enrollments.id', '=', 'lms_quiz_attempts.enrollment_id')
            ->groupBy('lms_enrollments.course_id')
            ->havingRaw('count(*) >= 3')
            ->orderByDesc(DB::raw('sum(case when lms_quiz_attempts.passed = false then 1 else 0 end)::float / count(*)'))
            ->limit(10)
            ->get();

        $courseIds = $rows->pluck('course_id');
        $courses   = \App\Modules\HR\LMS\Models\LmsCourse::whereIn('id', $courseIds)
            ->pluck('title', 'id');

        return $rows->map(fn ($r) => [
            'course_id'      => $r->course_id,
            'course_title'   => $courses[$r->course_id] ?? 'Unknown',
            'total_attempts' => (int) $r->total_attempts,
            'failed_attempts'=> (int) $r->failed_attempts,
            'failure_rate'   => $r->total_attempts > 0
                ? round($r->failed_attempts / $r->total_attempts * 100, 1)
                : 0.0,
        ])->values()->all();
    }

    private function refresherNeeded(): array
    {
        $total = LmsUnderstandingScore::where('refresher_recommended', true)
            ->distinct('user_id')
            ->count('user_id');

        $byDept = LmsUnderstandingScore::select(
                'hr_people.division_level_1 as dept_id',
                DB::raw('count(distinct lms_understanding_scores.user_id) as employee_count')
            )
            ->join('users', 'users.id', '=', 'lms_understanding_scores.user_id')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->where('refresher_recommended', true)
            ->whereNotNull('hr_people.division_level_1')
            ->groupBy('hr_people.division_level_1')
            ->orderByDesc('employee_count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'dept_id'        => $r->dept_id,
                'employee_count' => (int) $r->employee_count,
            ]);

        $topCourses = LmsUnderstandingScore::select('course_id',
                DB::raw('count(*) as refresher_count'))
            ->where('refresher_recommended', true)
            ->with('course:id,title')
            ->groupBy('course_id')
            ->orderByDesc('refresher_count')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'course_id'      => $r->course_id,
                'course_title'   => $r->course?->title ?? 'Unknown',
                'refresher_count'=> (int) $r->refresher_count,
            ]);

        return compact('total', 'byDept', 'topCourses');
    }

    private function skillsReadiness(): array
    {
        $total      = LmsEmployeeSkillGap::count();
        $open       = LmsEmployeeSkillGap::open()->count();
        $closed     = LmsEmployeeSkillGap::where('status', 'closed')->count();
        $readiness  = $total > 0 ? round($closed / $total * 100, 1) : 100.0;

        $avgGapPerSkill = LmsEmployeeSkillGap::open()
            ->select('skill_id', DB::raw('avg(gap) as avg_gap'), DB::raw('count(*) as affected_employees'))
            ->with('skill:id,name')
            ->groupBy('skill_id')
            ->orderByDesc('avg_gap')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'skill_id'            => $r->skill_id,
                'skill_name'          => $r->skill?->name ?? 'Unknown',
                'avg_gap'             => round((float) $r->avg_gap, 1),
                'affected_employees'  => (int) $r->affected_employees,
            ]);

        return compact('total', 'open', 'closed', 'readiness', 'avgGapPerSkill');
    }

    private function mandatoryOverdue(): array
    {
        $mandatory = LmsEnrollment::whereHas('course', fn ($q) => $q->where('is_mandatory', true))->count();

        $overdue = LmsEnrollment::whereHas('course', fn ($q) => $q->where('is_mandatory', true))
            ->where('status', '!=', 'completed')
            ->where(function ($q) {
                $q->where('due_date', '<', now())
                  ->orWhereIn('id', LmsComplianceAssignment::where('status', 'overdue')->pluck('enrollment_id')->filter());
            })
            ->count();

        $rate = $mandatory > 0 ? round($overdue / $mandatory * 100, 1) : 0.0;

        $byDept = LmsEnrollment::select(
                'hr_people.division_level_1 as dept_id',
                DB::raw('count(*) as overdue_count')
            )
            ->join('users', 'users.id', '=', 'lms_enrollments.user_id')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->whereHas('course', fn ($q) => $q->where('is_mandatory', true))
            ->where('lms_enrollments.status', '!=', 'completed')
            ->where('lms_enrollments.due_date', '<', now())
            ->whereNotNull('hr_people.division_level_1')
            ->groupBy('hr_people.division_level_1')
            ->orderByDesc('overdue_count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'dept_id'      => $r->dept_id,
                'overdue_count'=> (int) $r->overdue_count,
            ]);

        return compact('mandatory', 'overdue', 'rate', 'byDept');
    }

    private function certificationRisk(): array
    {
        $expired  = LmsCertificate::whereNotNull('expires_at')->where('expires_at', '<', now())->count();
        $expiring30  = LmsCertificate::whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(30)])->count();
        $expiring60  = LmsCertificate::whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->addDays(30), now()->addDays(60)])->count();
        $expiring90  = LmsCertificate::whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->addDays(60), now()->addDays(90)])->count();

        $atRiskCerts = LmsCertificate::select(
                'lms_certificates.id', 'lms_certificates.certificate_number',
                'lms_certificates.expires_at', 'lms_enrollments.course_id',
                'lms_enrollments.user_id'
            )
            ->join('lms_enrollments', 'lms_enrollments.id', '=', 'lms_certificates.enrollment_id')
            ->whereNotNull('lms_certificates.expires_at')
            ->where('lms_certificates.expires_at', '<', now()->addDays(90))
            ->where('lms_certificates.expires_at', '>=', now())
            ->with(['enrollment.course:id,title', 'enrollment.user.person:user_id,first_name,surname'])
            ->orderBy('lms_certificates.expires_at')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id'                 => $c->id,
                'certificate_number' => $c->certificate_number,
                'expires_at'         => $c->expires_at?->toDateString(),
                'days_left'          => (int) now()->diffInDays($c->expires_at, false),
                'course_title'       => $c->enrollment?->course?->title ?? 'Unknown',
                'employee_name'      => $c->enrollment?->user?->person
                    ? $c->enrollment->user->person->first_name . ' ' . $c->enrollment->user->person->surname
                    : 'Unknown',
            ]);

        return compact('expired', 'expiring30', 'expiring60', 'expiring90', 'atRiskCerts');
    }

    private function trainingRoi(): array
    {
        $avgRoi  = (float) LmsEffectivenessSnapshot::avg('roi_pct') ?? 0.0;
        $bestRoi = (float) LmsEffectivenessSnapshot::max('roi_pct') ?? 0.0;

        $topCourses = LmsEffectivenessSnapshot::select('course_id',
                DB::raw('avg(roi_pct) as avg_roi'),
                DB::raw('avg(completion_rate) as avg_completion'),
                DB::raw('avg(pass_rate) as avg_pass'),
                DB::raw('avg(avg_understanding_score) as avg_understanding'))
            ->whereNotNull('roi_pct')
            ->with('course:id,title')
            ->groupBy('course_id')
            ->orderByDesc('avg_roi')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'course_id'        => $r->course_id,
                'course_title'     => $r->course?->title ?? 'Unknown',
                'avg_roi'          => round((float) $r->avg_roi, 1),
                'avg_completion'   => round((float) $r->avg_completion, 1),
                'avg_pass'         => round((float) $r->avg_pass, 1),
                'avg_understanding'=> round((float) $r->avg_understanding, 1),
            ]);

        return ['avgRoi' => round($avgRoi, 1), 'bestRoi' => round($bestRoi, 1), 'topCourses' => $topCourses];
    }

    private function departmentCapability(): array
    {
        $deptScores = LmsUnderstandingScore::select(
                'hr_people.division_level_1 as dept_id',
                DB::raw('avg(lms_understanding_scores.total_score) as avg_understanding'),
                DB::raw('count(distinct lms_understanding_scores.user_id) as assessed_count')
            )
            ->join('users', 'users.id', '=', 'lms_understanding_scores.user_id')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->whereNotNull('hr_people.division_level_1')
            ->groupBy('hr_people.division_level_1')
            ->get()
            ->keyBy('dept_id');

        $deptCompletion = LmsEnrollment::select(
                'hr_people.division_level_1 as dept_id',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when lms_enrollments.status='completed' then 1 else 0 end) as completed")
            )
            ->join('users', 'users.id', '=', 'lms_enrollments.user_id')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->whereNotNull('hr_people.division_level_1')
            ->groupBy('hr_people.division_level_1')
            ->get()
            ->keyBy('dept_id');

        $deptIds = $deptScores->keys()->merge($deptCompletion->keys())->unique();

        $departments = $deptIds->map(function ($id) use ($deptScores, $deptCompletion) {
            $s = $deptScores->get($id);
            $c = $deptCompletion->get($id);
            $understanding  = $s  ? round((float) $s->avg_understanding, 1)  : 0.0;
            $completionRate = ($c && $c->total > 0)
                ? round($c->completed / $c->total * 100, 1)
                : 0.0;
            $capability = round(($understanding * 0.6 + $completionRate * 0.4), 1);

            return [
                'dept_id'           => $id,
                'understanding_avg' => $understanding,
                'completion_rate'   => $completionRate,
                'capability_score'  => $capability,
                'assessed_count'    => $s ? (int) $s->assessed_count : 0,
            ];
        })->sortByDesc('capability_score')->values()->all();

        return ['departments' => $departments];
    }

    private function policyViolations(): array
    {
        $total      = LmsPolicyViolation::count();
        $unresolved = LmsPolicyViolation::where('is_resolved', false)->count();

        $byType = LmsPolicyViolation::select('violation_type',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when is_resolved = false then 1 else 0 end) as unresolved"))
            ->groupBy('violation_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'violation_type'  => $r->violation_type,
                'label'           => LmsPolicyViolation::VIOLATION_TYPES[$r->violation_type] ?? $r->violation_type,
                'total'           => (int) $r->total,
                'unresolved'      => (int) $r->unresolved,
            ]);

        $bySeverity = LmsPolicyViolation::select('severity',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when is_resolved = false then 1 else 0 end) as unresolved"))
            ->groupBy('severity')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'severity'   => $r->severity,
                'total'      => (int) $r->total,
                'unresolved' => (int) $r->unresolved,
            ]);

        $recent = LmsPolicyViolation::with([
                'enrollment.user.person:user_id,first_name,surname',
                'enrollment.course:id,title',
            ])
            ->where('is_resolved', false)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($v) => [
                'id'             => $v->id,
                'violation_type' => $v->violation_type,
                'label'          => LmsPolicyViolation::VIOLATION_TYPES[$v->violation_type] ?? $v->violation_type,
                'severity'       => $v->severity,
                'severity_color' => $v->severityColor(),
                'action_taken'   => $v->action_taken,
                'action_color'   => $v->actionColor(),
                'rule_name'      => $v->rule_name,
                'course_title'   => $v->enrollment?->course?->title ?? '—',
                'employee_name'  => $v->enrollment?->user?->person
                    ? $v->enrollment->user->person->first_name . ' ' . $v->enrollment->user->person->surname
                    : '—',
                'created_at'     => $v->created_at?->toDateString(),
            ]);

        return compact('total', 'unresolved', 'byType', 'bySeverity', 'recent');
    }

    private function qctoCompliance(): array
    {
        $year = now()->year;
        return [
            'active_learnerships'  => LmsLearnership::where('is_active', true)->count(),
            'active_enrolments'    => LmsLearnershipEnrolment::whereIn('status', ['registered', 'active'])->count(),
            'completed_enrolments' => LmsLearnershipEnrolment::where('status', 'completed')->count(),
            'pending_wsp'          => LmsWspPlan::where('status', 'draft')->where('plan_year', $year)->count(),
            'submitted_wsp'        => LmsWspPlan::where('status', 'submitted')->where('plan_year', $year)->count(),
            'pending_atr'          => LmsAtrReport::where('status', 'draft')->where('report_year', $year - 1)->count(),
            'pending_nlrd'         => LmsNlrdSubmission::where('status', 'draft')->count(),
            'expiring_assessors'   => LmsRegisteredPerson::where('expires_at', '<=', now()->addDays(90))
                                        ->where('status', 'active')->count(),
            'expiring_sdfs'        => LmsSdf::where('expires_at', '<=', now()->addDays(90))
                                        ->where('status', 'active')->count(),
            'registered_setas'     => LmsSeta::where('is_active', true)->count(),
        ];
    }

    private function workforceSkillGaps(): array
    {
        $totalOpen      = LmsEmployeeSkillGap::open()->count();
        $affectedEmployees = LmsEmployeeSkillGap::open()->distinct('user_id')->count('user_id');

        $topGaps = LmsEmployeeSkillGap::open()
            ->select('skill_id',
                DB::raw('count(*) as gap_count'),
                DB::raw('avg(gap) as avg_gap'),
                DB::raw('max(gap) as max_gap'))
            ->with('skill:id,name')
            ->groupBy('skill_id')
            ->orderByDesc('gap_count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'skill_id'  => $r->skill_id,
                'skill_name'=> $r->skill?->name ?? 'Unknown',
                'gap_count' => (int) $r->gap_count,
                'avg_gap'   => round((float) $r->avg_gap, 1),
                'max_gap'   => (int) $r->max_gap,
            ]);

        $byDept = LmsEmployeeSkillGap::query()
            ->select(
                'hr_people.division_level_1 as dept_id',
                DB::raw('count(*) as gap_count'),
                DB::raw('count(distinct employee_skill_gaps.user_id) as affected_employees')
            )
            ->join('users', 'users.id', '=', 'employee_skill_gaps.user_id')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->where('employee_skill_gaps.status', 'open')
            ->whereNotNull('hr_people.division_level_1')
            ->groupBy('hr_people.division_level_1')
            ->orderByDesc('gap_count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'dept_id'             => $r->dept_id,
                'gap_count'           => (int) $r->gap_count,
                'affected_employees'  => (int) $r->affected_employees,
            ]);

        return compact('totalOpen', 'affectedEmployees', 'topGaps', 'byDept');
    }
}
