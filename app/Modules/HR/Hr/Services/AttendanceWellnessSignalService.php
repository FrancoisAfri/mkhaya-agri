<?php

namespace App\Modules\HR\Hr\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDepartmentWellnessScore;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceWellnessAlert;
use App\Modules\HR\Hr\Models\Attendance\AttendanceWellnessScore;
use App\Shared\DTOs\AgentRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class AttendanceWellnessSignalService
{
    private const OT_WEEKLY_LIMIT        = 10;
    private const STRESS_LATE_THRESHOLD  = 4;   // 4+ lates in period = stress signal
    private const WITHDRAWAL_ABSENT_PCT  = 25;  // 25%+ absence = withdrawal signal
    private const TREND_WINDOW_DAYS      = 14;  // compare last 14 days vs prior 14 days

    public function __construct(
        private readonly AgentRegistry $agentRegistry
    ) {}

    // ── Run full wellness signal scan ─────────────────────────────────────────

    public function runWellnessScan(string $periodStart, string $periodEnd): array
    {
        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->select('id', 'first_name', 'surname')
            ->get();

        $alertCount = 0;

        foreach ($employees as $emp) {
            $alerts  = $this->detectStressIndicators($emp->id, $periodStart, $periodEnd);
            $alerts  = array_merge($alerts, $this->detectBurnoutSignals($emp->id, $periodStart, $periodEnd));
            $alerts  = array_merge($alerts, $this->detectAttendanceWithdrawal($emp->id, $periodStart, $periodEnd));

            foreach ($alerts as $alert) {
                $this->generateWellnessAlert($emp->id, $alert['type'], $alert['severity'], $alert['description'], $alert['evidence']);
                $alertCount++;
            }
        }

        // Score each active department
        $departments = DB::table('company_units')
            ->where('type', 'department')
            ->where('active', true)
            ->pluck('name');

        if ($departments->isEmpty()) {
            $departments = DB::table('hr_people')
                ->where('status', 1)
                ->whereNotNull('division_level_1')
                ->distinct()
                ->pluck('division_level_1');
        }

        $deptCount = 0;
        foreach ($departments as $dept) {
            $this->generateDepartmentWellnessScore($dept, $periodStart, $periodEnd);
            $deptCount++;
        }

        return ['alerts' => $alertCount, 'departments' => $deptCount];
    }

    // ── detectStressIndicators ────────────────────────────────────────────────

    public function detectStressIndicators(int $employeeId, string $start, string $end): array
    {
        $records     = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$start, $end])
            ->get();

        $alerts      = [];
        $lateCount   = $records->where('is_late', true)->count();
        $earlyCount  = $records->where('is_early_departure', true)->count();
        $totalOt     = $records->sum('overtime_hours');
        $workingDays = $this->workingDays($start, $end);

        // Repeated lateness = stress signal
        if ($lateCount >= self::STRESS_LATE_THRESHOLD) {
            $severity = $lateCount >= 8 ? 'high' : 'medium';
            $alerts[] = [
                'type'        => 'stress_indicator',
                'severity'    => $severity,
                'description' => "Employee arrived late {$lateCount} times in the period — a possible stress or commute-related signal.",
                'evidence'    => ['late_count' => $lateCount, 'early_departure_count' => $earlyCount],
            ];
        }

        // Overtime + late combination = compounded stress
        if ($totalOt > self::OT_WEEKLY_LIMIT && $lateCount >= 2) {
            $alerts[] = [
                'type'        => 'overtime_overload',
                'severity'    => 'high',
                'description' => "Combined overtime ({$totalOt}h) with {$lateCount} late arrivals suggests fatigue-driven stress.",
                'evidence'    => ['overtime_hours' => round($totalOt, 1), 'late_count' => $lateCount],
            ];
        }

        return $alerts;
    }

    // ── detectBurnoutSignals ──────────────────────────────────────────────────

    public function detectBurnoutSignals(int $employeeId, string $start, string $end): array
    {
        $records     = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$start, $end])
            ->get();

        $workingDays = $this->workingDays($start, $end);
        $presentDays = $records->whereNotNull('clock_in')->count();
        $totalOt     = $records->sum('overtime_hours');
        $lateCount   = $records->where('is_late', true)->count();
        $absentRate  = $workingDays > 0 ? ($workingDays - $presentDays) / $workingDays * 100 : 0;

        $alerts = [];

        // Classic burnout pattern: high OT + increasing absences
        if ($totalOt >= 20 && $absentRate >= 15) {
            $alerts[] = [
                'type'        => 'burnout_signal',
                'severity'    => 'high',
                'description' => "Employee shows burnout pattern: {$totalOt}h overtime combined with " . round($absentRate, 1) . "% absence rate. Classic exhaustion cycle.",
                'evidence'    => ['overtime_hours' => round($totalOt, 1), 'absenteeism_rate' => round($absentRate, 1)],
            ];
        }

        // Extreme overtime alone
        if ($totalOt >= 30) {
            $alerts[] = [
                'type'        => 'burnout_signal',
                'severity'    => 'critical',
                'description' => "Extreme overtime of {$totalOt}h in the period. Immediate workload review required.",
                'evidence'    => ['overtime_hours' => round($totalOt, 1)],
            ];
        }

        return $alerts;
    }

    // ── detectAttendanceWithdrawal ────────────────────────────────────────────

    public function detectAttendanceWithdrawal(int $employeeId, string $start, string $end): array
    {
        $workingDays  = $this->workingDays($start, $end);
        $records      = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$start, $end])
            ->get();

        $presentDays  = $records->whereNotNull('clock_in')->count();
        $absentRate   = $workingDays > 0 ? ($workingDays - $presentDays) / $workingDays * 100 : 0;

        $alerts = [];

        if ($absentRate >= self::WITHDRAWAL_ABSENT_PCT) {
            $severity = $absentRate >= 40 ? 'critical' : 'high';
            $alerts[] = [
                'type'        => 'withdrawal',
                'severity'    => $severity,
                'description' => "Attendance withdrawal detected: " . round($absentRate, 1) . "% absence rate over the period. Employee may be disengaging.",
                'evidence'    => [
                    'absent_rate_pct' => round($absentRate, 1),
                    'present_days'    => $presentDays,
                    'working_days'    => $workingDays,
                ],
            ];
        }

        // Trend decline: compare first vs second half of period
        $midpoint = Carbon::parse($start)->addDays((int)(Carbon::parse($start)->diffInDays($end) / 2))->toDateString();

        $firstHalf  = $records->filter(fn($r) => $r->date->toDateString() <= $midpoint && $r->clock_in)->count();
        $secondHalf = $records->filter(fn($r) => $r->date->toDateString() > $midpoint && $r->clock_in)->count();

        $halfDays   = max(1, (int) ($workingDays / 2));

        if ($firstHalf > 0 && $secondHalf < $firstHalf * 0.6 && $halfDays >= 5) {
            $alerts[] = [
                'type'        => 'trend_decline',
                'severity'    => 'medium',
                'description' => "Attendance trend declining: first half {$firstHalf} days present vs second half {$secondHalf} days — a downward trajectory.",
                'evidence'    => ['first_half_present' => $firstHalf, 'second_half_present' => $secondHalf],
            ];
        }

        return $alerts;
    }

    // ── generateWellnessAlert ─────────────────────────────────────────────────

    public function generateWellnessAlert(int $employeeId, string $type, string $severity, string $description, array $evidence): AttendanceWellnessAlert
    {
        $today = now()->toDateString();
        $start = $evidence['period_start'] ?? now()->subDays(30)->toDateString();
        $end   = $evidence['period_end']   ?? $today;

        return AttendanceWellnessAlert::create([
            'employee_id'   => $employeeId,
            'alert_type'    => $type,
            'severity'      => $severity,
            'severity_color'=> AttendanceWellnessAlert::SEVERITY_COLORS[$severity] ?? 'warning',
            'period_start'  => $start,
            'period_end'    => $end,
            'description'   => $description,
            'evidence'      => $evidence,
            'status'        => 'open',
        ]);
    }

    // ── generateDepartmentWellnessScore ───────────────────────────────────────

    public function generateDepartmentWellnessScore(string $department, string $periodStart, string $periodEnd): AttendanceDepartmentWellnessScore
    {
        $empIds = DB::table('hr_people as hp')
            ->join('company_unit_user_pivots as p', 'p.user_id', '=', 'hp.user_id')
            ->join('company_units as cu', 'cu.id', '=', 'p.company_id')
            ->where('cu.type', 'department')
            ->where('cu.name', $department)
            ->where('hp.status', 1)
            ->pluck('hp.id');

      if ($empIds->isEmpty()) {
    $empIds = DB::table('hr_people')
        ->where('division_level_1', function ($q) use ($department) {
            $q->select('id')
              ->from('company_units')
              ->where('name', $department)
              ->where('type', 'department')
              ->limit(1);
        })
        ->where('status', 1)
        ->pluck('id');
}

        $headcount   = $empIds->count();
        $workingDays = $this->workingDays($periodStart, $periodEnd);

        if ($headcount === 0) {
            return AttendanceDepartmentWellnessScore::updateOrCreate(
                ['department' => $department, 'period_start' => $periodStart, 'period_end' => $periodEnd],
                ['wellness_score' => 100, 'risk_level' => 'low', 'risk_color' => 'success', 'headcount' => 0, 'calculated_at' => now()]
            );
        }

        $records = AttendanceRecord::whereIn('employee_id', $empIds)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get();

        $totalAbsences = 0;
        foreach ($empIds as $id) {
            $present       = $records->where('employee_id', $id)->whereNotNull('clock_in')->count();
            $totalAbsences += max(0, $workingDays - $present);
        }

        $avgAbsentism  = round($totalAbsences / max(1, $headcount * $workingDays) * 100, 1);
        $avgOt         = round($records->avg('overtime_hours') ?? 0, 1);
        $openAlerts    = AttendanceWellnessAlert::whereIn('employee_id', $empIds)->where('status', 'open')->count();
        $highRisk      = AttendanceWellnessScore::whereIn('employee_id', $empIds)->whereIn('burnout_risk', ['high', 'critical'])->count();

        // Department wellness score
        $score  = 100;
        $score -= min(30, $avgAbsentism * 1.2);
        $score -= min(20, $avgOt * 0.8);
        $score -= min(30, $highRisk / max(1, $headcount) * 100);
        $score -= min(20, $openAlerts * 2);
        $score  = max(0, (int) round($score));

        $risk = match(true) {
            $score <= 30 => 'critical',
            $score <= 50 => 'high',
            $score <= 70 => 'medium',
            default      => 'low',
        };

        $topIssues = [];
        if ($avgAbsentism >= 15)        $topIssues[] = "High absenteeism ({$avgAbsentism}%)";
        if ($avgOt >= 10)               $topIssues[] = "Excessive overtime (avg {$avgOt}h)";
        if ($highRisk >= $headcount/3)  $topIssues[] = "{$highRisk} employees at high burnout risk";
        if ($openAlerts >= 5)           $topIssues[] = "{$openAlerts} open wellness alerts";

        return AttendanceDepartmentWellnessScore::updateOrCreate(
            ['department' => $department, 'period_start' => $periodStart, 'period_end' => $periodEnd],
            [
                'wellness_score'       => $score,
                'risk_level'           => $risk,
                'risk_color'           => AttendanceDepartmentWellnessScore::colorFromRisk($risk),
                'headcount'            => $headcount,
                'high_risk_count'      => $highRisk,
                'open_alerts_count'    => $openAlerts,
                'avg_absenteeism_rate' => $avgAbsentism,
                'avg_overtime_hours'   => $avgOt,
                'top_issues'           => $topIssues,
                'calculated_at'        => now(),
            ]
        );
    }

    // ── generateManagerInsights (AI) ──────────────────────────────────────────

    public function generateManagerInsights(string $department, string $periodStart, string $periodEnd): string
    {
        $deptScore = AttendanceDepartmentWellnessScore::where('department', $department)
            ->where('period_start', $periodStart)
            ->first();

        $deptEmpIds = DB::table('hr_people as hp')
            ->join('company_unit_user_pivots as p', 'p.user_id', '=', 'hp.user_id')
            ->join('company_units as cu', 'cu.id', '=', 'p.company_id')
            ->where('cu.type', 'department')
            ->where('cu.name', $department)
            ->pluck('hp.id');

        if ($deptEmpIds->isEmpty()) {
            $deptEmpIds = DB::table('hr_people')->where('division_level_1', $department)->pluck('id');
        }

        $openAlerts = AttendanceWellnessAlert::whereIn('employee_id', $deptEmpIds)
            ->where('status', 'open')
            ->orderByDesc('severity')
            ->take(5)
            ->get()
            ->map(fn($a) => "{$a->alert_type}: {$a->description}")
            ->join('; ');

        $scoreStr   = $deptScore ? "Wellness score: {$deptScore->wellness_score}/100. Risk: {$deptScore->risk_level}. Avg absenteeism: {$deptScore->avg_absenteeism_rate}%. Avg OT: {$deptScore->avg_overtime_hours}h." : 'No score available.';
        $issuesStr  = $deptScore ? implode(', ', $deptScore->top_issues ?? []) : 'Unknown';

        $request = AgentRequest::make()
            ->withSystemPrompt('You are a workforce wellness advisor writing a concise manager briefing. Be direct, empathetic, and action-oriented. 4-6 bullet points. Never blame employees — focus on systemic patterns and what the manager can do.')
            ->withPrompt("Write a manager wellness briefing for department: {$department}. Period: {$periodStart} to {$periodEnd}. {$scoreStr} Key issues: {$issuesStr}. Open alerts summary: {$openAlerts}");

        try {
            $response = $this->agentRegistry->for('attendance_wellness')->ask($request);
            return $response->content ?? 'Manager insights unavailable.';
        } catch (\Throwable) {
            return "Department '{$department}' wellness score: " . ($deptScore?->wellness_score ?? '—') . "/100. Key issues: {$issuesStr}.";
        }
    }

    // ── AI insight for single alert ───────────────────────────────────────────

    public function explainAlert(AttendanceWellnessAlert $alert): string
    {
        $emp  = DB::table('hr_people')->where('id', $alert->employee_id)->select('first_name', 'surname')->first();
        $name = $emp ? "{$emp->first_name} {$emp->surname}" : "Employee #{$alert->employee_id}";
        $evidence = json_encode($alert->evidence ?? [], JSON_PRETTY_PRINT);

        $request = AgentRequest::make()
            ->withSystemPrompt('You are a workforce wellness expert. Write empathetic, evidence-based wellness insights for HR managers. 2-3 sentences. Acknowledge context — illness, family, workload. Never be accusatory.')
            ->withPrompt("Write a wellness insight for {$name}. Alert type: {$alert->alert_type}. Severity: {$alert->severity}. Description: {$alert->description}. Evidence: {$evidence}");

        try {
            $response = $this->agentRegistry->for('attendance_wellness')->ask($request);
            return $response->content ?? 'AI insight unavailable.';
        } catch (\Throwable) {
            return $alert->description;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function workingDays(string $start, string $end): int
    {
        $count = 0;
        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (! $day->isWeekend()) $count++;
        }
        return max(1, $count);
    }
}
