<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCertificate;
use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use App\Modules\HR\LMS\Models\LmsDevelopmentTrainingPlan;
use App\Modules\HR\LMS\Models\LmsEffectivenessSnapshot;
use App\Modules\HR\LMS\Models\LmsEmployeeSkillGap;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsPolicyViolation;
use App\Modules\HR\LMS\Models\LmsQuizAttempt;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment;
use App\Modules\HR\LMS\Models\Qcto\LmsNlrdSubmission;
use App\Modules\HR\LMS\Models\Qcto\LmsRegisteredPerson;
use App\Modules\HR\LMS\Models\Qcto\LmsSdf;
use App\Modules\HR\LMS\Models\Qcto\LmsWspPlan;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class LmsReportController extends Controller
{
    // ── Page ──────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        return Inertia::render('Modules/LMS/Admin/Reports', [
            'learnerCompletion'      => $this->learnerCompletion(),
            'trainingCompliance'     => $this->trainingCompliance(),
            'overdueAssignments'     => $this->overdueAssignments(),
            'certificationExpiry'    => $this->certificationExpiry(),
            'skillsGap'              => $this->skillsGap(),
            'quizPerformance'        => $this->quizPerformance(),
            'effectivenessRoi'       => $this->effectivenessRoi(),
            'understandingScores'    => $this->understandingScores(),
            'qctoLearnershipStatus'  => $this->qctoLearnershipStatus(),
            'qctoAssessorExpiry'     => $this->qctoAssessorExpiry(),
            'registrationExpiry'     => $this->registrationExpiryCombined(),
            'qctoWspAtrStatus'       => $this->qctoWspAtrStatus(),
            'qctoNlrdPending'        => $this->qctoNlrdPending(),
            'departmentCapability'   => $this->departmentCapability(),
            'policyViolations'       => $this->policyViolations(),
            'developmentPlans'       => $this->developmentPlans(),
            'courses'                => LmsCourse::orderBy('title')->get(['id', 'title']),
            'departments'            => HRPerson::select('division_level_1 as id', 'division_level_1 as name')
                ->whereNotNull('division_level_1')
                ->distinct()
                ->orderBy('division_level_1')
                ->get(),
        ]);
    }

    // ── JSON API ──────────────────────────────────────────────────────────────

    public function generate(string $key): \Illuminate\Http\JsonResponse
    {
        $rows = match ($key) {
            'learner_completion'    => $this->learnerCompletion(),
            'training_compliance'   => $this->trainingCompliance(),
            'overdue_assignments'   => $this->overdueAssignments(),
            'certification_expiry'  => $this->certificationExpiry(),
            'skills_gap'            => $this->skillsGap(),
            'quiz_performance'      => $this->quizPerformance(),
            'effectiveness_roi'     => $this->effectivenessRoi(),
            'understanding_scores'  => $this->understandingScores(),
            'qcto_learnership'      => $this->qctoLearnershipStatus(),
            'qcto_assessor_expiry'  => $this->qctoAssessorExpiry(),
            'registration_expiry'   => $this->registrationExpiryCombined(),
            'qcto_wsp_atr'          => $this->qctoWspAtrStatus(),
            'qcto_nlrd'             => $this->qctoNlrdPending(),
            'dept_capability'       => $this->departmentCapability(),
            'policy_violations'     => $this->policyViolations(),
            'development_plans'     => $this->developmentPlans(),
            default                 => [],
        };

        return response()->json(['rows' => $rows, 'count' => count($rows)]);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(string $key, string $format): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows     = $this->generate($key)->getData(true)['rows'];
        $filename = 'lms-' . $key . '-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows, $key) {
            $out = fopen('php://output', 'w');
            if (empty($rows)) {
                fputcsv($out, ['No data available']);
                fclose($out);
                return;
            }
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $v ?? '', $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    public function queue(string $key): \Illuminate\Http\JsonResponse
    {
        $user  = Auth::user();
        $email = $user?->email;

        if (! $email) {
            return response()->json(['message' => 'No authenticated user email.'], 422);
        }

        $rows     = $this->generate($key)->getData(true)['rows'];
        $title    = \App\Modules\HR\LMS\Models\LmsScheduledReport::availableReports()[$key] ?? $key;
        $csv      = $this->buildCsv($rows, $title);
        $tmpPath  = sys_get_temp_dir() . '/lms-' . $key . '-' . now()->format('Y-m-d') . '.csv';

        file_put_contents($tmpPath, $csv);

        Mail::raw(
            "Please find attached the LMS report: {$title}\n\nGenerated: " . now()->toDateTimeString(),
            function ($msg) use ($email, $title, $tmpPath) {
                $msg->to($email)
                    ->subject("LMS Report — {$title} — " . now()->format('d M Y'))
                    ->attach($tmpPath, ['as' => basename($tmpPath), 'mime' => 'text/csv']);
            }
        );

        @unlink($tmpPath);

        return response()->json(['message' => "Report queued — you will receive it at {$email}."]);
    }

    // ── Private report methods ────────────────────────────────────────────────

    private function learnerCompletion(): array
    {
        return LmsEnrollment::with(['course:id,title', 'user.person:user_id,first_name,surname'])
            ->limit(500)
            ->get()
            ->map(fn ($e) => [
                'employee_name' => $e->user?->person
                    ? $e->user->person->first_name . ' ' . $e->user->person->surname
                    : '—',
                'course_title'  => $e->course?->title ?? '—',
                'status'        => $e->status ?? '—',
                'progress'      => $e->progress ?? 0,
                'score'         => $e->score ?? null,
                'enrolled_at'   => $e->created_at?->toDateString(),
                'completed_at'  => $e->completed_at?->toDateString(),
            ])
            ->all();
    }

    private function trainingCompliance(): array
    {
        return LmsComplianceAssignment::with([
                'rule:id,name',
                'course:id,title',
                'user.person:user_id,first_name,surname',
            ])
            ->limit(500)
            ->get()
            ->map(fn ($a) => [
                'employee_name' => $a->user?->person
                    ? $a->user->person->first_name . ' ' . $a->user->person->surname
                    : '—',
                'rule_name'     => $a->rule?->name ?? '—',
                'course_title'  => $a->course?->title ?? '—',
                'status'        => $a->status ?? '—',
                'due_date'      => $a->due_date?->toDateString(),
                'assigned_at'   => $a->created_at?->toDateString(),
            ])
            ->all();
    }

    private function overdueAssignments(): array
    {
        return LmsComplianceAssignment::with([
                'rule:id,name',
                'course:id,title',
                'user.person:user_id,first_name,surname',
            ])
            ->where('status', 'overdue')
            ->limit(500)
            ->get()
            ->map(fn ($a) => [
                'employee_name' => $a->user?->person
                    ? $a->user->person->first_name . ' ' . $a->user->person->surname
                    : '—',
                'rule_name'     => $a->rule?->name ?? '—',
                'course_title'  => $a->course?->title ?? '—',
                'due_date'      => $a->due_date?->toDateString(),
                'days_overdue'  => $a->due_date
                    ? (int) now()->diffInDays($a->due_date, false) * -1
                    : 0,
            ])
            ->all();
    }

    private function certificationExpiry(): array
    {
        return LmsCertificate::with([
                'enrollment.course:id,title',
                'enrollment.user.person:user_id,first_name,surname',
            ])
            ->orderBy('expires_at')
            ->limit(500)
            ->get()
            ->map(fn ($c) => [
                'employee_name'      => $c->enrollment?->user?->person
                    ? $c->enrollment->user->person->first_name . ' ' . $c->enrollment->user->person->surname
                    : '—',
                'course_title'       => $c->enrollment?->course?->title ?? '—',
                'certificate_number' => $c->certificate_number ?? '—',
                'issued_at'          => $c->issued_at?->toDateString(),
                'expires_at'         => $c->expires_at?->toDateString(),
                'days_until_expiry'  => $c->expires_at
                    ? (int) now()->diffInDays($c->expires_at, false)
                    : null,
            ])
            ->all();
    }

    private function skillsGap(): array
    {
        return LmsEmployeeSkillGap::open()
            ->with([
                'skill:id,name',
                'user.person:user_id,first_name,surname',
            ])
            ->limit(500)
            ->get()
            ->map(fn ($g) => [
                'employee_name'  => $g->user?->person
                    ? $g->user->person->first_name . ' ' . $g->user->person->surname
                    : '—',
                'skill_name'     => $g->skill?->name ?? '—',
                'current_level'  => $g->current_level ?? 0,
                'required_level' => $g->required_level ?? 0,
                'gap'            => $g->gap ?? 0,
                'identified_at'  => $g->created_at?->toDateString(),
            ])
            ->all();
    }

    private function quizPerformance(): array
    {
        return LmsQuizAttempt::with([
                'enrollment.course:id,title',
                'enrollment.user.person:user_id,first_name,surname',
            ])
            ->limit(500)
            ->get()
            ->map(fn ($a) => [
                'employee_name'  => $a->enrollment?->user?->person
                    ? $a->enrollment->user->person->first_name . ' ' . $a->enrollment->user->person->surname
                    : '—',
                'course_title'   => $a->enrollment?->course?->title ?? '—',
                'score'          => $a->score ?? null,
                'passed'         => (bool) $a->passed,
                'attempt_number' => $a->attempt_number ?? 1,
                'attempted_at'   => $a->created_at?->toDateString(),
            ])
            ->all();
    }

    private function effectivenessRoi(): array
    {
        return LmsEffectivenessSnapshot::with('course:id,title')
            ->limit(500)
            ->get()
            ->map(fn ($s) => [
                'course_title'              => $s->course?->title ?? '—',
                'completion_rate'           => $s->completion_rate ?? 0,
                'pass_rate'                 => $s->pass_rate ?? 0,
                'avg_understanding_score'   => $s->avg_understanding_score ?? 0,
                'roi_pct'                   => $s->roi_pct ?? 0,
                'snapshot_date'             => $s->created_at?->toDateString(),
            ])
            ->all();
    }

    private function understandingScores(): array
    {
        return LmsUnderstandingScore::with([
                'course:id,title',
                'user.person:user_id,first_name,surname',
            ])
            ->limit(500)
            ->get()
            ->map(fn ($s) => [
                'employee_name'         => $s->user?->person
                    ? $s->user->person->first_name . ' ' . $s->user->person->surname
                    : '—',
                'course_title'          => $s->course?->title ?? '—',
                'total_score'           => $s->total_score ?? 0,
                'quiz_score'            => $s->quiz_score ?? 0,
                'time_score'            => $s->time_score ?? 0,
                'refresher_recommended' => (bool) $s->refresher_recommended,
                'assessed_at'           => $s->created_at?->toDateString(),
            ])
            ->all();
    }

    private function qctoLearnershipStatus(): array
    {
        return LmsLearnershipEnrolment::with(['learnership:id,name', 'hrPerson:id,first_name,surname'])
            ->limit(500)
            ->get()
            ->map(fn ($e) => [
                'employee_name'       => $e->hrPerson
                    ? $e->hrPerson->first_name . ' ' . $e->hrPerson->surname
                    : '—',
                'learnership_name'    => $e->learnership?->name ?? '—',
                'status'              => $e->status ?? '—',
                'enrolled_at'         => $e->start_date?->toDateString(),
                'expected_end_date'   => $e->end_date?->toDateString(),
            ])
            ->all();
    }

    private function qctoAssessorExpiry(): array
    {
        return LmsRegisteredPerson::with('seta:id,acronym')
            ->orderBy('expires_at')
            ->limit(500)
            ->get()
            ->map(fn ($p) => [
                'full_name'           => $p->full_name ?? '—',
                'person_type'         => $p->person_type ?? '—',
                'seta_acronym'        => $p->seta?->acronym ?? '—',
                'registration_number' => $p->registration_number ?? '—',
                'registered_at'       => $p->registered_at?->toDateString() ?? $p->created_at?->toDateString(),
                'expires_at'          => $p->expires_at?->toDateString(),
                'status'              => $p->status ?? '—',
                'days_until_expiry'   => $p->expires_at
                    ? (int) now()->diffInDays($p->expires_at, false)
                    : null,
            ])
            ->all();
    }

    private function registrationExpiryCombined(): array
    {
        $cutoff = now()->addMonths(6);
        $rows   = [];

        // Assessors & Moderators (include already expired + expiring within 6 months)
        LmsRegisteredPerson::with('seta')
            ->where(fn ($q) => $q->where('expires_at', '<=', $cutoff)->orWhereNull('expires_at'))
            ->orderBy('expires_at')
            ->limit(300)
            ->get()
            ->each(function ($p) use (&$rows) {
                $daysLeft = $p->expires_at ? (int) now()->diffInDays($p->expires_at, false) : null;
                $rows[]   = [
                    'role'                => ucfirst(str_replace('_', ' & ', $p->person_type)),
                    'name'                => $p->full_name,
                    'seta'                => $p->seta?->acronym ?? '—',
                    'registration_number' => $p->registration_number ?? '—',
                    'registered_at'       => $p->registered_at?->toDateString(),
                    'expires_at'          => $p->expires_at?->toDateString(),
                    'days_until_expiry'   => $daysLeft,
                    'alert_status'        => $daysLeft === null ? 'no_expiry'
                        : ($daysLeft < 0   ? 'expired'
                        : ($daysLeft <= 30  ? 'critical'
                        : ($daysLeft <= 90  ? 'urgent'
                        : 'warning'))),
                    'status'              => $p->status,
                ];
            });

        // SDFs
        LmsSdf::with(['user.person', 'seta'])
            ->where(fn ($q) => $q->where('expires_at', '<=', $cutoff)->orWhereNull('expires_at'))
            ->orderBy('expires_at')
            ->limit(100)
            ->get()
            ->each(function ($s) use (&$rows) {
                $name     = $s->user?->person
                    ? $s->user->person->first_name . ' ' . $s->user->person->surname
                    : "User #{$s->user_id}";
                $daysLeft = $s->expires_at ? (int) now()->diffInDays($s->expires_at, false) : null;
                $rows[]   = [
                    'role'                => 'SDF',
                    'name'                => $name,
                    'seta'                => $s->seta?->acronym ?? '—',
                    'registration_number' => $s->registration_number ?? '—',
                    'registered_at'       => $s->registered_at?->toDateString(),
                    'expires_at'          => $s->expires_at?->toDateString(),
                    'days_until_expiry'   => $daysLeft,
                    'alert_status'        => $daysLeft === null ? 'no_expiry'
                        : ($daysLeft < 0   ? 'expired'
                        : ($daysLeft <= 30  ? 'critical'
                        : ($daysLeft <= 90  ? 'urgent'
                        : 'warning'))),
                    'status'              => $s->status,
                ];
            });

        usort($rows, fn ($a, $b) => ($a['days_until_expiry'] ?? PHP_INT_MAX) <=> ($b['days_until_expiry'] ?? PHP_INT_MAX));

        return $rows;
    }

    private function qctoWspAtrStatus(): array
    {
        return LmsWspPlan::with('seta:id,acronym')
            ->orderByDesc('plan_year')
            ->limit(500)
            ->get()
            ->map(fn ($w) => [
                'plan_year'    => $w->plan_year,
                'seta_acronym' => $w->seta?->acronym ?? '—',
                'status'       => $w->status ?? '—',
                'submitted_at' => $w->submission_date?->toDateString(),
                'atr_count'    => $w->atrReport ? 1 : 0,
            ])
            ->all();
    }

    private function qctoNlrdPending(): array
    {
        return LmsNlrdSubmission::limit(500)
            ->get()
            ->map(fn ($s) => [
                'reference_number' => $s->reference_number ?? '—',
                'submission_type'  => $s->submission_type ?? '—',
                'status'           => $s->status ?? '—',
                'period_start'     => $s->period_start?->toDateString(),
                'period_end'       => $s->period_end?->toDateString(),
                'submitted_at'     => $s->submission_date?->toDateString(),
            ])
            ->all();
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

        return $deptIds->map(function ($id) use ($deptScores, $deptCompletion) {
            $s = $deptScores->get($id);
            $c = $deptCompletion->get($id);
            $understanding  = $s  ? round((float) $s->avg_understanding, 1)  : 0.0;
            $completionRate = ($c && $c->total > 0)
                ? round($c->completed / $c->total * 100, 1)
                : 0.0;
            $capability = round(($understanding * 0.6 + $completionRate * 0.4), 1);

            return [
                'department'        => $id,
                'completion_rate'   => $completionRate,
                'understanding_avg' => $understanding,
                'capability_score'  => $capability,
                'assessed_count'    => $s ? (int) $s->assessed_count : 0,
            ];
        })->sortByDesc('capability_score')->values()->all();
    }

    private function policyViolations(): array
    {
        return LmsPolicyViolation::with([
                'enrollment.course:id,title',
                'enrollment.user.person:user_id,first_name,surname',
            ])
            ->limit(500)
            ->get()
            ->map(fn ($v) => [
                'employee_name' => $v->enrollment?->user?->person
                    ? $v->enrollment->user->person->first_name . ' ' . $v->enrollment->user->person->surname
                    : '—',
                'course_title'  => $v->enrollment?->course?->title ?? '—',
                'violation_type'=> $v->violation_type ?? '—',
                'severity'      => $v->severity ?? '—',
                'action_taken'  => $v->action_taken ?? '—',
                'rule_name'     => $v->rule_name ?? '—',
                'is_resolved'   => (bool) $v->is_resolved,
                'created_at'    => $v->created_at?->toDateString(),
            ])
            ->all();
    }

    private function developmentPlans(): array
    {
        return LmsDevelopmentTrainingPlan::with([
                'employee.person:user_id,first_name,surname',
            ])
            ->limit(500)
            ->get()
            ->map(fn ($p) => [
                'employee_name'  => $p->employee?->person
                    ? $p->employee->person->first_name . ' ' . $p->employee->person->surname
                    : '—',
                'course_title'   => $p->title ?? '—',
                'plan_type'      => 'development',
                'status'         => $p->status ?? '—',
                'planned_date'   => $p->target_completion_date?->toDateString(),
                'completed_date' => $p->status === 'completed' ? $p->updated_at?->toDateString() : null,
                'notes'          => $p->notes ?? '—',
            ])
            ->all();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildCsv(array $rows, string $title): string
    {
        if (empty($rows)) {
            return "No data available for: {$title}\n";
        }
        $headers = array_keys($rows[0]);
        $lines   = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"',
                $row
            ));
        }
        return implode("\n", $lines);
    }
}
