<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Core\AI\Registry\AgentRegistry;
use App\Core\User\Models\User;
use App\Modules\PerformanceAppraisal\Models\PaBehaviorSignal;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaCheckin;
use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use App\Modules\PerformanceAppraisal\Models\PaWellnessScore;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaWellnessService
{
    public function __construct(protected AgentRegistry $agents) {}

    /**
     * Generate a wellness score for one employee in a cycle.
     */
    public function generateScore(int $employeeId, ?int $cycleId, int $generatedBy): PaWellnessScore
    {
        $metrics = $this->collectMetrics($employeeId, $cycleId);
        $score   = $this->computeWellnessScore($metrics);
        $burnout = $this->burnoutLevel($metrics);
        $diseng  = $this->disengagementLevel($metrics);
        $signals = $this->detectedSignals($metrics, $burnout, $diseng);

        return PaWellnessScore::create([
            'employee_id'         => $employeeId,
            'cycle_id'            => $cycleId,
            'wellness_score'      => $score,
            'wellness_grade'      => PaWellnessScore::gradeFromScore($score),
            'kpi_score'           => isset($metrics['kpi_avg']) ? (int) $metrics['kpi_avg'] : null,
            'feedback_score'      => isset($metrics['feedback_avg']) ? (int) (($metrics['feedback_avg'] / 5) * 100) : null,
            'checkin_score'       => isset($metrics['checkin_rate']) ? (int) $metrics['checkin_rate'] : null,
            'engagement_score'    => $metrics['self_feedback'] ? 100 : 20,
            'burnout_risk'        => $burnout,
            'disengagement_level' => $diseng,
            'signals'             => $signals,
            'is_ai_generated'     => false,
            'generated_by'        => $generatedBy,
        ]);
    }

    /**
     * Run all signal detectors for every active employee in a cycle.
     */
    public function detectSignalsForCycle(PaCycle $cycle, int $triggeredBy): array
    {
        $employees = User::pluck('id');
        $created   = [];

        foreach ($employees as $empId) {
            $metrics = $this->collectMetrics($empId, $cycle->id);
            $score   = $this->computeWellnessScore($metrics);
            $burnout = $this->burnoutLevel($metrics);
            $diseng  = $this->disengagementLevel($metrics);

            // Burnout detection
            if (in_array($burnout, ['high', 'critical'])) {
                $created[] = $this->upsertSignal($empId, $cycle->id, 'burnout', $this->burnoutSeverity($burnout), [
                    'overdue_kpis'  => $metrics['overdue_kpis'],
                    'kpi_avg'       => $metrics['kpi_avg'],
                    'checkin_rate'  => $metrics['checkin_rate'],
                ]);
            }

            // Disengagement detection
            if ($diseng === 'high') {
                $created[] = $this->upsertSignal($empId, $cycle->id, 'disengagement', 'warning', [
                    'self_feedback' => $metrics['self_feedback'],
                    'checkin_rate'  => $metrics['checkin_rate'],
                ]);
            }

            // Productivity decline
            if (isset($metrics['kpi_avg']) && $metrics['kpi_avg'] < 40) {
                $created[] = $this->upsertSignal($empId, $cycle->id, 'productivity_decline', 'warning', [
                    'kpi_avg' => $metrics['kpi_avg'],
                ]);
            }

            // Workload imbalance (too many overdue KPIs vs total KPIs)
            if ($metrics['total_kpis'] >= 5 && $metrics['overdue_kpis'] / max(1, $metrics['total_kpis']) > 0.5) {
                $created[] = $this->upsertSignal($empId, $cycle->id, 'workload_imbalance', 'warning', [
                    'total_kpis'   => $metrics['total_kpis'],
                    'overdue_kpis' => $metrics['overdue_kpis'],
                ]);
            }
        }

        return array_filter($created);
    }

    /**
     * Generate AI wellness insights for a manager (covering their team).
     */
    public function generateManagerInsights(int $managerId, ?int $cycleId): string
    {
        /*
         * The manager's own personnel id, not their user id.
         *
         * `hr_people.manager_id` points at another row of `hr_people`, so matching it against a
         * user id compares two unrelated sequences and quietly returns somebody else's team — or,
         * as it did here, nobody's. The column it used to name, `reporting_to`, does not exist at
         * all, so this never ran: the screen was a 500 rather than a wrong answer, which is the
         * luckier of the two.
         */
        $managerPersonId = HRPerson::where('user_id', $managerId)->value('id');

        if ($managerPersonId === null) {
            return 'No team found for this manager.';
        }

        $teamSignals = PaBehaviorSignal::whereHas('employee', function ($q) use ($managerPersonId) {
            $q->whereHas('person', fn ($p) => $p->where('manager_id', $managerPersonId));
        })
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->where('acknowledged_at', null)
            ->with('employee')
            ->get();

        if ($teamSignals->isEmpty()) {
            return 'No active wellness signals detected for your team in this period.';
        }

        $grouped = $teamSignals->groupBy('signal_type')->map(fn($items) => $items->count())->toArray();

        try {
            $request = new AgentRequest(
                prompt       : 'You are an occupational health and HR consultant. Based on the team wellness signals below, write a concise 2–3 paragraph manager briefing that: (1) summarises the team wellness situation, (2) highlights the most critical risks, (3) suggests specific managerial actions. Be practical and empathetic. Plain text only.',
                module       : 'performance_appraisal',
                context      : ['signal_summary' => $grouped, 'total_signals' => $teamSignals->count()],
                systemPrompt : 'You are an expert in workplace wellness and occupational psychology. Provide actionable, evidence-based guidance.',
                temperature  : 0.3,
                maxTokens    : 1000,
            );

            $response = $this->agents->for('performance_appraisal')->ask($request);

            if ($response->failed()) throw new \RuntimeException($response->errorMessage);

            return trim($response->content);

        } catch (\Throwable $e) {
            Log::warning('PaWellness: AI manager insights failed', ['error' => $e->getMessage()]);

            $lines = ["Team Wellness Briefing — {$teamSignals->count()} active signal(s) detected."];
            foreach ($grouped as $type => $count) {
                $lines[] = ucfirst(str_replace('_', ' ', $type)) . ": {$count} employee(s) affected.";
            }
            return implode("\n", $lines);
        }
    }

    public function acknowledgeSignal(PaBehaviorSignal $signal, int $userId): void
    {
        $signal->update(['acknowledged_by' => $userId, 'acknowledged_at' => now()]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function collectMetrics(int $employeeId, ?int $cycleId): array
    {
        // KPI average score
        $kpiAvg = DB::table('pa_employee_kpis as k')
            ->join('pa_kpi_scores as s', 's.employee_kpi_id', '=', 'k.id')
            ->where('k.employee_id', $employeeId)
            ->when($cycleId, fn($q) => $q->where('k.cycle_id', $cycleId))
            ->avg('s.score_pct');

        // Overdue in-progress KPIs
        $overdueKpis = DB::table('pa_employee_kpis as k')
            ->join('pa_cycles as c', 'c.id', '=', 'k.cycle_id')
            ->where('k.employee_id', $employeeId)
            ->where('k.status', 'in_progress')
            ->where('c.end_date', '<', now()->toDateString())
            ->when($cycleId, fn($q) => $q->where('k.cycle_id', $cycleId))
            ->count();

        $totalKpis = DB::table('pa_employee_kpis')
            ->where('employee_id', $employeeId)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->count();

        // Feedback avg
        $feedbackAvg = PaFeedback::where('employee_id', $employeeId)
            ->where('is_draft', false)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->avg('rating');

        // Check-in completion
        $totalCi = PaCheckin::where('employee_id', $employeeId)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->count();
        $doneCi  = PaCheckin::where('employee_id', $employeeId)
            ->where('status', 'completed')
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->count();

        // Self-feedback submitted
        $selfFb = PaFeedback::where('employee_id', $employeeId)
            ->where('given_by', $employeeId)
            ->where('feedback_type', 'self')
            ->where('is_draft', false)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->exists();

        // ── Leave & attendance signals (last 90 days) — real burnout indicators ──
        $since = now()->subDays(90)->toDateString();

        $overtimeHours = (float) (DB::table('attendance_records')
            ->where('employee_id', $employeeId)
            ->where('date', '>=', $since)
            ->sum('overtime_hours') ?? 0);

        $lateDays = (int) DB::table('attendance_records')
            ->where('employee_id', $employeeId)
            ->where('date', '>=', $since)
            ->where('is_late', true)
            ->count();

        $approvedLeaveDays = 0.0;
        if (DB::getSchemaBuilder()->hasTable('leave_requests')) {
            $approvedLeaveDays = (float) (DB::table('leave_requests')
                ->where('employee_id', $employeeId)
                ->where('status', 'approved')
                ->where('start_date', '>=', $since)
                ->sum('working_days') ?? 0);
        }

        return [
            'kpi_avg'        => $kpiAvg !== null ? round($kpiAvg, 1) : null,
            'overdue_kpis'   => $overdueKpis,
            'total_kpis'     => $totalKpis,
            'feedback_avg'   => $feedbackAvg !== null ? round($feedbackAvg, 2) : null,
            'checkin_rate'   => $totalCi > 0 ? round(($doneCi / $totalCi) * 100, 1) : null,
            'self_feedback'  => $selfFb,
            // ── integration signals (leave + attendance) ──
            'overtime_hours' => round($overtimeHours, 1),
            'late_days'      => $lateDays,
            'leave_days'     => round($approvedLeaveDays, 1),
            // "all work, no rest": sustained overtime with almost no leave taken
            'no_rest_flag'   => $overtimeHours >= 20 && $approvedLeaveDays < 1,
        ];
    }

    private function computeWellnessScore(array $m): int
    {
        $kpi      = ($m['kpi_avg']      ?? 55) * 0.30;
        $feedback = (isset($m['feedback_avg']) ? ($m['feedback_avg'] / 5) * 100 : 55) * 0.25;
        $checkin  = ($m['checkin_rate'] ?? 55) * 0.20;
        $selfEng  = ($m['self_feedback'] ? 100 : 25) * 0.15;
        $overdue  = min(30, $m['overdue_kpis'] * 8) * 0.10 * -1 + 10;

        // Penalise the "all work, no rest" pattern detected from attendance/leave.
        $restPenalty = ($m['no_rest_flag'] ?? false) ? 8 : 0;

        return (int) max(0, min(100, round($kpi + $feedback + $checkin + $selfEng + $overdue - $restPenalty)));
    }

    private function burnoutLevel(array $m): string
    {
        $risk = 0;
        if ($m['overdue_kpis'] >= 4)   $risk += 3;
        elseif ($m['overdue_kpis'] >= 2) $risk += 1;
        if (isset($m['kpi_avg']) && $m['kpi_avg'] < 40)   $risk += 2;
        if (isset($m['checkin_rate']) && $m['checkin_rate'] < 30) $risk += 1;
        if (!$m['self_feedback']) $risk += 1;

        // ── leave/attendance-driven burnout risk ──
        if (($m['overtime_hours'] ?? 0) >= 40)      $risk += 2;
        elseif (($m['overtime_hours'] ?? 0) >= 20)  $risk += 1;
        if ($m['no_rest_flag'] ?? false)            $risk += 1;
        if (($m['late_days'] ?? 0) >= 8)            $risk += 1; // erratic attendance

        return match(true) {
            $risk >= 5 => 'critical',
            $risk >= 3 => 'high',
            $risk >= 1 => 'medium',
            default    => 'low',
        };
    }

    private function burnoutSeverity(string $level): string
    {
        return match($level) {
            'critical' => 'critical',
            'high'     => 'warning',
            default    => 'info',
        };
    }

    private function disengagementLevel(array $m): string
    {
        $score = 0;
        if (!$m['self_feedback'])   $score += 2;
        if (isset($m['checkin_rate']) && $m['checkin_rate'] < 50) $score += 2;
        if (isset($m['feedback_avg']) && $m['feedback_avg'] < 2.5) $score += 1;

        return match(true) {
            $score >= 4 => 'high',
            $score >= 2 => 'medium',
            default     => 'low',
        };
    }

    private function detectedSignals(array $m, string $burnout, string $diseng): array
    {
        $signals = [];
        if (in_array($burnout, ['high', 'critical'])) $signals[] = 'burnout';
        if ($diseng === 'high') $signals[] = 'disengagement';
        if (isset($m['kpi_avg']) && $m['kpi_avg'] < 40) $signals[] = 'productivity_decline';
        if ($m['overdue_kpis'] > 3) $signals[] = 'workload_imbalance';
        return $signals;
    }

    private function upsertSignal(int $empId, int $cycleId, string $type, string $severity, array $data): PaBehaviorSignal
    {
        $labels = [
            'burnout'               => ['Burnout Risk Detected', 'Employee shows signs of burnout based on overdue KPIs and low engagement.'],
            'disengagement'         => ['Disengagement Signal', 'Employee shows signs of disengagement — missing check-ins and no self-reflection.'],
            'productivity_decline'  => ['Productivity Decline', 'KPI achievement rate has fallen significantly below target.'],
            'workload_imbalance'    => ['Workload Imbalance', 'Employee has an unusually high number of overdue KPIs relative to total assignments.'],
            'attendance_correlation'=> ['Attendance-Performance Correlation', 'Attendance patterns may be impacting performance scores.'],
        ];

        [$title, $desc] = $labels[$type] ?? [$type, ''];

        return PaBehaviorSignal::updateOrCreate(
            ['employee_id' => $empId, 'cycle_id' => $cycleId, 'signal_type' => $type],
            ['severity' => $severity, 'title' => $title, 'description' => $desc, 'data' => $data, 'acknowledged_at' => null, 'acknowledged_by' => null]
        );
    }
}
