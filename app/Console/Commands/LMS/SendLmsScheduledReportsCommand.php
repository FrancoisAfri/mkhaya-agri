<?php

namespace App\Console\Commands\LMS;

use App\Modules\HR\LMS\Models\LmsScheduledReport;
use Carbon\Carbon;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendLmsScheduledReportsCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'lms:send-scheduled-reports';
    protected $description = 'Generate and email due LMS scheduled reports';

    public function handle(): int
    {
        $this->eachTenant(app(TenantContext::class), function () {
            $due = LmsScheduledReport::where('active', true)
                ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now()))
                ->get();

            if ($due->isEmpty()) {
                $this->info('No LMS reports due.');
                return;
            }

            foreach ($due as $scheduled) {
                try {
                    $this->processReport($scheduled);
                    $scheduled->update([
                        'last_run_at' => now(),
                        'next_run_at' => $scheduled->computeNextRun(),
                    ]);
                    $this->info("Sent: {$scheduled->name}");
                } catch (\Throwable $e) {
                    $this->error("Failed [{$scheduled->name}]: {$e->getMessage()}");
                }
            }
        });

        return self::SUCCESS;
    }

    private function processReport(LmsScheduledReport $scheduled): void
    {
        $data      = $this->buildReportData($scheduled->report_key);
        $title     = LmsScheduledReport::availableReports()[$scheduled->report_key] ?? $scheduled->report_key;
        $recipients = array_filter(array_map('trim', explode(',', $scheduled->recipients)));

        if (empty($recipients)) return;

        $csv     = $this->buildCsv($data, $title);
        $tmpPath = sys_get_temp_dir() . '/' . 'lms-' . $scheduled->report_key . '-' . now()->format('Y-m-d') . '.csv';
        file_put_contents($tmpPath, $csv);

        foreach ($recipients as $email) {
            Mail::raw("Please find attached the LMS report: {$title}\n\nGenerated: " . now()->toDateTimeString(), function ($msg) use ($email, $title, $tmpPath) {
                $msg->to($email)
                    ->subject("LMS Report — {$title} — " . now()->format('d M Y'))
                    ->attach($tmpPath, ['as' => basename($tmpPath), 'mime' => 'text/csv']);
            });
        }

        @unlink($tmpPath);
    }

    private function buildReportData(string $key): array
    {
        return match ($key) {
            'learner_completion'    => $this->learnerCompletionData(),
            'training_compliance'   => $this->trainingComplianceData(),
            'overdue_assignments'   => $this->overdueAssignmentsData(),
            'certification_expiry'  => $this->certificationExpiryData(),
            'skills_gap'            => $this->skillsGapData(),
            'quiz_performance'      => $this->quizPerformanceData(),
            'effectiveness_roi'     => $this->effectivenessRoiData(),
            'understanding_scores'  => $this->understandingScoresData(),
            'qcto_learnership'      => $this->learnershipStatusData(),
            'qcto_assessor_expiry'  => $this->assessorExpiryData(),
            'qcto_wsp_atr'          => $this->wspAtrStatusData(),
            'qcto_nlrd'             => $this->nlrdPendingData(),
            'dept_capability'       => $this->deptCapabilityData(),
            'policy_violations'     => $this->policyViolationsData(),
            'development_plans'     => $this->developmentPlansData(),
            default                 => [],
        };
    }

    private function learnerCompletionData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsEnrollment::with(['course', 'user.person'])
            ->limit(500)->get()
            ->map(fn ($e) => [
                'Employee'    => $e->user?->person ? $e->user->person->first_name . ' ' . $e->user->person->surname : "User #{$e->user_id}",
                'Course'      => $e->course?->title ?? '—',
                'Status'      => $e->status ?? '—',
                'Progress'    => $e->progress ?? 0,
                'Score'       => $e->score ?? '',
                'Enrolled'    => $e->created_at?->toDateString(),
                'Completed'   => $e->completed_at?->toDateString(),
            ])->all();
    }

    private function overdueAssignmentsData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsComplianceAssignment::with(['rule', 'course', 'user.person'])
            ->where('status', 'overdue')->limit(500)->get()
            ->map(fn ($a) => [
                'Employee'     => $a->user?->person ? $a->user->person->first_name . ' ' . $a->user->person->surname : "User #{$a->user_id}",
                'Rule'         => $a->rule?->name ?? '—',
                'Course'       => $a->course?->title ?? '—',
                'Due Date'     => $a->due_date?->toDateString(),
                'Days Overdue' => $a->due_date ? (int) now()->diffInDays($a->due_date, false) * -1 : 0,
            ])->all();
    }

    private function certificationExpiryData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsCertificate::with(['enrollment.course', 'enrollment.user.person'])
            ->orderBy('expires_at')->limit(500)->get()
            ->map(fn ($c) => [
                'Employee'      => $c->enrollment?->user?->person ? $c->enrollment->user->person->first_name . ' ' . $c->enrollment->user->person->surname : '—',
                'Course'        => $c->enrollment?->course?->title ?? '—',
                'Certificate #' => $c->certificate_number ?? '—',
                'Issued'        => $c->issued_at?->toDateString(),
                'Expires'       => $c->expires_at?->toDateString(),
                'Days Left'     => $c->expires_at ? (int) now()->diffInDays($c->expires_at, false) : '',
            ])->all();
    }

    private function skillsGapData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsEmployeeSkillGap::open()
            ->with(['skill', 'user.person'])->limit(500)->get()
            ->map(fn ($g) => [
                'Employee'       => $g->user?->person ? $g->user->person->first_name . ' ' . $g->user->person->surname : '—',
                'Skill'          => $g->skill?->name ?? '—',
                'Current Level'  => $g->current_level ?? 0,
                'Required Level' => $g->required_level ?? 0,
                'Gap'            => $g->gap ?? 0,
                'Identified'     => $g->created_at?->toDateString(),
            ])->all();
    }

    private function quizPerformanceData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsQuizAttempt::with(['enrollment.course', 'enrollment.user.person'])
            ->limit(500)->get()
            ->map(fn ($a) => [
                'Employee' => $a->enrollment?->user?->person ? $a->enrollment->user->person->first_name . ' ' . $a->enrollment->user->person->surname : '—',
                'Course'   => $a->enrollment?->course?->title ?? '—',
                'Score'    => $a->score ?? '',
                'Passed'   => $a->passed ? 'Yes' : 'No',
                'Attempt'  => $a->attempt_number ?? 1,
                'Date'     => $a->created_at?->toDateString(),
            ])->all();
    }

    private function effectivenessRoiData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsEffectivenessSnapshot::with('course')
            ->limit(500)->get()
            ->map(fn ($s) => [
                'Course'             => $s->course?->title ?? '—',
                'Completion %'       => $s->completion_rate ?? 0,
                'Pass %'             => $s->pass_rate ?? 0,
                'Understanding'      => $s->avg_understanding_score ?? 0,
                'ROI %'              => $s->roi_pct ?? 0,
                'Snapshot Date'      => $s->created_at?->toDateString(),
            ])->all();
    }

    private function understandingScoresData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsUnderstandingScore::with(['course', 'user.person'])
            ->limit(500)->get()
            ->map(fn ($s) => [
                'Employee'           => $s->user?->person ? $s->user->person->first_name . ' ' . $s->user->person->surname : '—',
                'Course'             => $s->course?->title ?? '—',
                'Total Score'        => $s->total_score ?? 0,
                'Quiz Score'         => $s->quiz_score ?? 0,
                'Time Score'         => $s->time_score ?? 0,
                'Needs Refresher'    => $s->refresher_recommended ? 'Yes' : 'No',
                'Date'               => $s->created_at?->toDateString(),
            ])->all();
    }

    private function learnershipStatusData(): array
    {
        return \App\Modules\HR\LMS\Models\Qcto\LmsLearnershipEnrolment::with(['learnership', 'user.person'])
            ->get()
            ->map(fn ($e) => [
                'Learner'        => $e->user?->person ? $e->user->person->first_name . ' ' . $e->user->person->surname : "User #{$e->user_id}",
                'Learnership'    => $e->learnership?->name ?? '—',
                'Status'         => $e->status,
                'Enrolled'       => $e->enrolled_at?->toDateString(),
                'Expected End'   => $e->expected_end_date?->toDateString(),
            ])
            ->all();
    }

    private function assessorExpiryData(): array
    {
        return \App\Modules\HR\LMS\Models\Qcto\LmsRegisteredPerson::with('seta')
            ->where('expires_at', '<=', now()->addDays(90))
            ->where('status', 'active')
            ->orderBy('expires_at')
            ->get()
            ->map(fn ($p) => [
                'Name'              => $p->full_name,
                'Type'              => $p->person_type,
                'SETA'              => $p->seta?->acronym ?? '—',
                'Reg Number'        => $p->registration_number ?? '—',
                'Expires'           => $p->expires_at?->toDateString(),
                'Days Until Expiry' => (int) now()->diffInDays($p->expires_at, false),
            ])
            ->all();
    }

    private function wspAtrStatusData(): array
    {
        return \App\Modules\HR\LMS\Models\Qcto\LmsWspPlan::with('seta')
            ->orderByDesc('plan_year')
            ->get()
            ->map(fn ($w) => [
                'Year'        => $w->plan_year,
                'SETA'        => $w->seta?->acronym ?? '—',
                'WSP Status'  => $w->status,
                'ATR Reports' => $w->atrReports()->count(),
            ])
            ->all();
    }

    private function nlrdPendingData(): array
    {
        return \App\Modules\HR\LMS\Models\Qcto\LmsNlrdSubmission::limit(500)->get()
            ->map(fn ($s) => [
                'Reference'      => $s->reference_number ?? '—',
                'Type'           => $s->submission_type ?? '—',
                'Status'         => $s->status ?? '—',
                'Period Start'   => $s->period_start?->toDateString(),
                'Period End'     => $s->period_end?->toDateString(),
                'Submitted'      => $s->submission_date?->toDateString(),
            ])->all();
    }

    private function deptCapabilityData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsUnderstandingScore::select(
                'hr_people.division_level_1 as dept_id',
                \Illuminate\Support\Facades\DB::raw('avg(lms_understanding_scores.total_score) as avg_understanding'),
                \Illuminate\Support\Facades\DB::raw('count(distinct lms_understanding_scores.user_id) as assessed_count')
            )
            ->join('users', 'users.id', '=', 'lms_understanding_scores.user_id')
            ->join('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->whereNotNull('hr_people.division_level_1')
            ->groupBy('hr_people.division_level_1')
            ->get()
            ->map(fn ($r) => [
                'Department'        => $r->dept_id,
                'Understanding Avg' => round((float) $r->avg_understanding, 1),
                'Assessed'          => (int) $r->assessed_count,
            ])->all();
    }

    private function policyViolationsData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsPolicyViolation::with(['enrollment.course', 'enrollment.user.person'])
            ->limit(500)->get()
            ->map(fn ($v) => [
                'Employee'       => $v->enrollment?->user?->person ? $v->enrollment->user->person->first_name . ' ' . $v->enrollment->user->person->surname : '—',
                'Course'         => $v->enrollment?->course?->title ?? '—',
                'Type'           => $v->violation_type ?? '—',
                'Severity'       => $v->severity ?? '—',
                'Action'         => $v->action_taken ?? '—',
                'Rule'           => $v->rule_name ?? '—',
                'Resolved'       => $v->is_resolved ? 'Yes' : 'No',
                'Date'           => $v->created_at?->toDateString(),
            ])->all();
    }

    private function developmentPlansData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsDevelopmentTrainingPlan::with('employee.person')
            ->limit(500)->get()
            ->map(fn ($p) => [
                'Employee'       => $p->employee?->person ? $p->employee->person->first_name . ' ' . $p->employee->person->surname : '—',
                'Plan'           => $p->title ?? '—',
                'Status'         => $p->status ?? '—',
                'Planned Date'   => $p->target_completion_date?->toDateString(),
                'Notes'          => $p->notes ?? '—',
            ])->all();
    }

    private function trainingComplianceData(): array
    {
        return \App\Modules\HR\LMS\Models\LmsComplianceAssignment::with(['user.person', 'course', 'rule'])
            ->where('status', 'overdue')
            ->orderByDesc('due_date')
            ->limit(500)
            ->get()
            ->map(fn ($a) => [
                'Employee'  => $a->user?->person ? $a->user->person->first_name . ' ' . $a->user->person->surname : "User #{$a->user_id}",
                'Course'    => $a->course?->title ?? '—',
                'Rule'      => $a->rule?->name ?? '—',
                'Due Date'  => $a->due_date?->toDateString(),
                'Status'    => $a->status,
            ])
            ->all();
    }

    private function buildCsv(array $rows, string $title): string
    {
        if (empty($rows)) return "No data available for: {$title}\n";
        $headers = array_keys($rows[0]);
        $lines   = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"', $row));
        }
        return implode("\n", $lines);
    }
}
