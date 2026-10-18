<?php

namespace App\Modules\HR\LMS\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class LmsScheduledReport extends Model
{
    protected $table = 'lms_scheduled_reports';

    protected $fillable = [
        'name', 'report_key', 'cadence', 'run_time',
        'day_of_week', 'day_of_month', 'format',
        'recipients', 'active', 'next_run_at', 'created_by',
    ];

    protected $casts = [
        'active'       => 'boolean',
        'last_run_at'  => 'datetime',
        'next_run_at'  => 'datetime',
        'day_of_week'  => 'integer',
        'day_of_month' => 'integer',
    ];

    public static function availableReports(): array
    {
        return [
            'learner_completion'    => 'Learner Course Completion',
            'training_compliance'   => 'Training Compliance Overview',
            'overdue_assignments'   => 'Overdue Assignments',
            'certification_expiry'  => 'Certification Expiry Risk',
            'skills_gap'            => 'Skills Gap Summary',
            'quiz_performance'      => 'Quiz Pass/Fail Performance',
            'effectiveness_roi'     => 'Training Effectiveness & ROI',
            'understanding_scores'  => 'Understanding Scores',
            'qcto_learnership'      => 'QCTO — Learnership Status',
            'qcto_assessor_expiry'  => 'QCTO — Assessor & Moderator Expiry',
            'registration_expiry'   => 'Registration Expiry — Assessors, Moderators & SDFs (6 months)',
            'qcto_wsp_atr'          => 'QCTO — WSP / ATR Status',
            'qcto_nlrd'             => 'QCTO — NLRD Submissions',
            'dept_capability'       => 'Department Training Capability',
            'policy_violations'     => 'Policy Violations',
            'development_plans'     => 'Development & Training Plans',
        ];
    }

    public function computeNextRun(): Carbon
    {
        [$h, $m] = explode(':', $this->run_time ?? '07:00');
        $now = Carbon::now();

        return match ($this->cadence) {
            'weekly'  => $this->nextWeeklyRun((int) $h, (int) $m, $now),
            'monthly' => $this->nextMonthlyRun((int) $h, (int) $m, $now),
            default   => $now->copy()->addDay()->setTime((int) $h, (int) $m, 0),
        };
    }

    private function nextWeeklyRun(int $h, int $m, Carbon $now): Carbon
    {
        $target    = $this->day_of_week ?? 1;
        $candidate = $now->copy()->setTime($h, $m, 0);
        while ($candidate->isoWeekday() !== $target || $candidate->lte($now)) {
            $candidate->addDay();
        }
        return $candidate;
    }

    private function nextMonthlyRun(int $h, int $m, Carbon $now): Carbon
    {
        $day       = max(1, min($this->day_of_month ?? 1, 28));
        $candidate = $now->copy()->day($day)->setTime($h, $m, 0);
        if ($candidate->lte($now)) {
            $candidate->addMonthNoOverflow();
        }
        return $candidate;
    }
}
