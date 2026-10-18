<?php

namespace App\Modules\HR\Hr\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\HR\Hr\Models\Attendance\AttendanceComplianceScore;
use App\Modules\HR\Hr\Models\Attendance\AttendancePolicyException;
use App\Modules\HR\Hr\Models\Attendance\AttendancePolicyRule;
use App\Modules\HR\Hr\Models\Attendance\AttendancePolicyViolation;
use App\Shared\DTOs\AgentRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendancePolicyService
{
    // Policy thresholds (overridden per rule, these are defaults)
    private const LATENESS_WINDOW_DAYS     = 30;
    private const OVERTIME_WEEKLY_LIMIT    = 20;  // hours — manager approval required above this
    private const CONSECUTIVE_HOUR_LIMIT   = 12;
    private const MISSED_CLOCKING_LIMIT    = 3;
    private const REST_HOURS_REQUIRED      = 8;

    public function __construct(
        private readonly AgentRegistry $agentRegistry
    ) {}

    // ── Main validation entry point ───────────────────────────────────────────

    public function validateAttendancePolicy(int $employeeId, string $periodStart, string $periodEnd, int $userId): array
    {
        $violations = [];

        $latenessResult = $this->checkLatenessPolicy($employeeId, $periodStart, $periodEnd);
        if ($latenessResult) $violations[] = $latenessResult;

        $overtimeResult = $this->checkOvertimePolicy($employeeId, $periodStart, $periodEnd);
        if ($overtimeResult) $violations[] = $overtimeResult;

        $shiftResult = $this->checkShiftRestPolicy($employeeId, $periodStart, $periodEnd);
        if ($shiftResult) $violations[] = $shiftResult;

        $remoteResult = $this->checkRemoteWorkPolicy($employeeId, $periodStart, $periodEnd);
        if ($remoteResult) $violations[] = $remoteResult;

        $missedResult = $this->checkMissedClockingPolicy($employeeId, $periodStart, $periodEnd);
        if ($missedResult) $violations[] = $missedResult;

        return $violations;
    }

    // ── Policy checks ─────────────────────────────────────────────────────────

    public function checkLatenessPolicy(int $employeeId, string $periodStart, string $periodEnd): ?array
    {
        $rule = AttendancePolicyRule::active()->ofCategory('lateness')->first();
        if (! $rule) return null;

        $lateCount = DB::table('attendance_records')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->where('is_late', true)
            ->count();

        if ($lateCount < $rule->threshold) return null;

        return [
            'rule'            => $rule,
            'detected_value'  => "{$lateCount} late arrival(s) in period",
            'threshold_value' => "Threshold: {$rule->threshold} in {$rule->period_days} days",
            'blocked'         => $rule->action === 'block',
        ];
    }

    public function checkOvertimePolicy(int $employeeId, string $periodStart, string $periodEnd): ?array
    {
        $rule = AttendancePolicyRule::active()->ofCategory('overtime')->first();
        if (! $rule) return null;

        // Sum overtime_hours across records in the period
        $overtimeHours = DB::table('attendance_records')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->sum('overtime_hours') ?? 0;

        if ($overtimeHours <= $rule->threshold) return null;

        return [
            'rule'            => $rule,
            'detected_value'  => number_format($overtimeHours, 1) . ' overtime hours in period',
            'threshold_value' => "Threshold: {$rule->threshold} {$rule->threshold_unit}",
            'blocked'         => $rule->action === 'block',
        ];
    }

public function checkShiftRestPolicy(int $employeeId, string $periodStart, string $periodEnd): ?array
{
    $rule = AttendancePolicyRule::active()->ofCategory('shift')->first();
    if (! $rule) return null;

    // a = clock_out, b = clock_in suivant du même employé
    $restViolations = DB::table('attendance_clockings as a')
        ->join('attendance_clockings as b', function ($join) {
            $join->on('b.employee_id', '=', 'a.employee_id')
                 ->where('b.type', '=', 'clock_in')
                 ->whereColumn('b.clocked_at', '>', 'a.clocked_at')
                 ->whereNotExists(function ($sub) {
                     $sub->from('attendance_clockings as c')
                         ->whereColumn('c.employee_id', '=', 'a.employee_id')
                         ->where('c.type', 'clock_in')
                         ->whereColumn('c.clocked_at', '>', 'a.clocked_at')
                         ->whereColumn('c.clocked_at', '<', 'b.clocked_at')
                         ->whereNull('c.deleted_at');
                 });
        })
        ->where('a.type', 'clock_out')
        ->where('a.employee_id', $employeeId)
        ->whereBetween(DB::raw('DATE(a.clocked_at)'), [$periodStart, $periodEnd])
        ->whereRaw(
            'EXTRACT(EPOCH FROM (b.clocked_at - a.clocked_at)) / 3600 < ?',
            [$rule->threshold]
        )
        ->whereNull('a.deleted_at')
        ->whereNull('b.deleted_at')
        ->count();

    if ($restViolations === 0) return null;

    return [
        'rule'            => $rule,
        'detected_value'  => "{$restViolations} shift(s) avec repos insuffisant",
        'threshold_value' => "Repos minimum requis : {$rule->threshold} heures",
        'blocked'         => false,
    ];
}

  public function checkRemoteWorkPolicy(int $employeeId, string $periodStart, string $periodEnd): ?array
{
    $rule = AttendancePolicyRule::active()->ofCategory('remote_work')->first();
    if (! $rule) return null;

    // Remote clockings without GPS verification
    $unverifiedRemote = DB::table('attendance_clockings')
        ->where('employee_id', $employeeId)
        ->where('type', 'clock_in')
        ->whereBetween(DB::raw('DATE(clocked_at)'), [$periodStart, $periodEnd])
        ->where('work_type', 'remote')
        ->whereNull('latitude')
        ->whereNull('deleted_at')
        ->count();

    if ($unverifiedRemote === 0) return null;

    return [
        'rule'            => $rule,
        'detected_value'  => "{$unverifiedRemote} remote session(s) without GPS verification",
        'threshold_value' => 'GPS verification required for remote work',
        'blocked'         => $rule->action === 'block',
    ];
}

  public function checkMissedClockingPolicy(int $employeeId, string $periodStart, string $periodEnd): ?array
{
    $rule = AttendancePolicyRule::active()->ofCategory('missed_clocking')->first();
    if (! $rule) return null;

    $missed = DB::table('attendance_clockings as a')
        ->where('a.employee_id', $employeeId)
        ->where('a.type', 'clock_in')
        ->whereBetween(DB::raw('DATE(a.clocked_at)'), [$periodStart, $periodEnd])
        ->whereNotExists(function ($sub) {
            $sub->from('attendance_clockings as b')
                ->whereColumn('b.employee_id', 'a.employee_id')
                ->where('b.type', 'clock_out')
                ->whereRaw('DATE(b.clocked_at) = DATE(a.clocked_at)')
                ->whereNull('b.deleted_at');
        })
        ->whereNull('a.deleted_at')
        ->count();

    if ($missed < $rule->threshold) return null;

    return [
        'rule'            => $rule,
        'detected_value'  => "{$missed} missed clocking(s) in period",
        'threshold_value' => "Threshold: {$rule->threshold} missed clockings",
        'blocked'         => $rule->action === 'block',
    ];
}

    // ── Block + persist violation ─────────────────────────────────────────────

    public function blockPolicyViolation(array $violationData, int $userId): AttendancePolicyViolation
    {
        return AttendancePolicyViolation::create([
            'employee_id'     => $violationData['employee_id'],
            'policy_rule_id'  => $violationData['rule']->id,
            'period_start'    => $violationData['period_start'],
            'period_end'      => $violationData['period_end'],
            'detected_value'  => $violationData['detected_value'],
            'threshold_value' => $violationData['threshold_value'],
            'status'          => $violationData['rule']->auto_escalate ? 'escalated' : 'open',
            'blocked'         => $violationData['blocked'] ?? false,
            'escalated_at'    => $violationData['rule']->auto_escalate ? now() : null,
            'created_by'      => $userId,
        ]);
    }

    // ── Full policy scan over all employees ───────────────────────────────────

    public function runPolicyScan(string $periodStart, string $periodEnd, int $userId): array
    {
        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->select('id', 'first_name', 'surname')
            ->get();

        $violationCount = 0;
        $blockedCount   = 0;

        foreach ($employees as $emp) {
            $results = $this->validateAttendancePolicy($emp->id, $periodStart, $periodEnd, $userId);

            foreach ($results as $result) {
                $existing = AttendancePolicyViolation::where('employee_id', $emp->id)
                    ->where('policy_rule_id', $result['rule']->id)
                    ->where('period_start', $periodStart)
                    ->whereIn('status', ['open', 'escalated'])
                    ->exists();

                if (! $existing) {
                    $v = $this->blockPolicyViolation(array_merge($result, [
                        'employee_id'  => $emp->id,
                        'period_start' => $periodStart,
                        'period_end'   => $periodEnd,
                    ]), $userId);
                    $violationCount++;
                    if ($v->blocked) $blockedCount++;
                }
            }
        }

        return ['violations' => $violationCount, 'blocked' => $blockedCount];
    }

    // ── Compliance score ──────────────────────────────────────────────────────

    public function generateComplianceScore(int $employeeId, string $periodStart, string $periodEnd): AttendanceComplianceScore
    {
        $totalDays    = Carbon::parse($periodStart)->diffInDays($periodEnd) + 1;
        $workingDays  = max(1, intval($totalDays * 5 / 7));

        $lateCount    = DB::table('attendance_records')->where('employee_id', $employeeId)->whereBetween('date', [$periodStart, $periodEnd])->where('is_late', true)->count();
$absentCount = DB::table('attendance_records')->join('attendance_statuses', 'attendance_statuses.id', '=', 'attendance_records.status_id')->where('attendance_records.employee_id', $employeeId)->whereBetween('attendance_records.date', [$periodStart, $periodEnd])
    ->where('attendance_statuses.name', 'absent')
    ->count();
        $otHours      = DB::table('attendance_records')->where('employee_id', $employeeId)->whereBetween('date', [$periodStart, $periodEnd])->sum('overtime_hours') ?? 0;

        $violations   = AttendancePolicyViolation::where('employee_id', $employeeId)->whereBetween('period_start', [$periodStart, $periodEnd])->count();
        $exceptions   = AttendancePolicyException::where('employee_id', $employeeId)->whereBetween('approved_at', [$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59'])->count();

        // Sub-scores (each out of 25)
        $latenessScore  = max(0, 25 - ($lateCount * 4));
        $overtimeScore  = max(0, 25 - (max(0, $otHours - self::OVERTIME_WEEKLY_LIMIT) * 1));
        $attendScore    = max(0, 25 - intval($absentCount / $workingDays * 100));
        $adherenceScore = max(0, 25 - ($violations * 5));

        $total = min(100, $latenessScore + $overtimeScore + $attendScore + $adherenceScore);

        return AttendanceComplianceScore::updateOrCreate(
            ['employee_id' => $employeeId, 'period_start' => $periodStart],
            [
                'period_end'        => $periodEnd,
                'total_score'       => $total,
                'lateness_score'    => $latenessScore,
                'overtime_score'    => $overtimeScore,
                'attendance_score'  => $attendScore,
                'adherence_score'   => $adherenceScore,
                'violations_count'  => $violations,
                'exceptions_count'  => $exceptions,
                'computed_at'       => now(),
            ]
        );
    }

    // ── AI enforcement decision ───────────────────────────────────────────────

    public function generateAiEnforcement(AttendancePolicyViolation $violation): string
    {
        $violation->load('employee', 'policyRule');
        $employee = $violation->employee;
        $rule     = $violation->policyRule;
        $name     = $employee ? "{$employee->first_name} {$employee->surname}" : "Employee #{$violation->employee_id}";

        $prompt = <<<TEXT
            Policy Violation Analysis

            Employee: {$name}
            Policy Rule: {$rule->name} (category: {$rule->category})
            Severity: {$rule->severity}
            Configured action: {$rule->action}
            Detected: {$violation->detected_value}
            Threshold: {$violation->threshold_value}
            Period: {$violation->period_start->toDateString()} to {$violation->period_end->toDateString()}

            Provide a formal policy enforcement decision (2-3 sentences) that:
            1. Acknowledges the violation
            2. States the required action under company policy
            3. Specifies any next steps or escalation path
            Keep it professional and factual. No bullet points.
            TEXT;

        try {
            return $this->agentRegistry
                ->for('attendance_policy')
                ->ask(
                    AgentRequest::make()
                        ->system('You are an attendance policy enforcement AI. You generate formal, HR-compliant policy enforcement decisions based on attendance data.')
                        ->user($prompt)
                )->content();
        } catch (\Throwable) {
            return "Policy violation confirmed: {$violation->detected_value}. Under {$rule->name} policy, the required action is: {$rule->action}. Please follow the standard HR process for {$rule->severity}-severity violations.";
        }
    }

    // ── AI compliance summary ────────────────────────────────────────────────

    public function generateComplianceSummary(AttendanceComplianceScore $score): string
    {
        $score->load('employee');
        $name = $score->employee
            ? "{$score->employee->first_name} {$score->employee->surname}"
            : "Employee #{$score->employee_id}";

        $label = AttendanceComplianceScore::labelFromScore($score->total_score);

        $prompt = <<<TEXT
            Employee: {$name}
            Period: {$score->period_start->toDateString()} to {$score->period_end->toDateString()}
            Compliance Score: {$score->total_score}/100 ({$label})
            - Lateness sub-score: {$score->lateness_score}/25
            - Overtime sub-score: {$score->overtime_score}/25
            - Attendance sub-score: {$score->attendance_score}/25
            - Policy adherence sub-score: {$score->adherence_score}/25
            - Violations in period: {$score->violations_count}
            - Exceptions granted: {$score->exceptions_count}

            Write a 2-sentence HR compliance summary: describe the employee's attendance compliance standing and recommend one action for improvement if needed.
            TEXT;

        try {
            return $this->agentRegistry
                ->for('attendance_policy')
                ->ask(
                    AgentRequest::make()
                        ->system('You are an HR compliance AI. Provide concise attendance compliance summaries.')
                        ->user($prompt)
                )->content();
        } catch (\Throwable) {
            return "Compliance score: {$score->total_score}/100 ({$label}) for period {$score->period_start->toDateString()} to {$score->period_end->toDateString()}. {$score->violations_count} violation(s) detected; review with HR if score is below 60.";
        }
    }

    // ── Seed default rules if none exist ─────────────────────────────────────

    public function seedDefaultRules(): void
    {
        $defaults = [
            ['code' => 'lateness_warning',       'name' => 'Lateness Warning Trigger',        'category' => 'lateness',          'threshold' => 3,  'threshold_unit' => 'count',      'period_days' => 30, 'severity' => 'medium', 'action' => 'warn',             'auto_escalate' => false],
            ['code' => 'overtime_approval',       'name' => 'Overtime Approval Required',      'category' => 'overtime',          'threshold' => 20, 'threshold_unit' => 'hours',      'period_days' => 7,  'severity' => 'medium', 'action' => 'require_approval', 'auto_escalate' => false],
            ['code' => 'consecutive_hours_block', 'name' => 'Consecutive Hours Block',         'category' => 'consecutive_hours', 'threshold' => 12, 'threshold_unit' => 'hours',      'period_days' => 1,  'severity' => 'high',   'action' => 'block',            'auto_escalate' => true],
            ['code' => 'shift_rest_violation',    'name' => 'Insufficient Shift Rest',         'category' => 'shift',             'threshold' => 8,  'threshold_unit' => 'hours',      'period_days' => 30, 'severity' => 'high',   'action' => 'escalate',         'auto_escalate' => true],
            ['code' => 'missed_clocking_probe',   'name' => 'Repeated Missed Clockings',       'category' => 'missed_clocking',   'threshold' => 3,  'threshold_unit' => 'count',      'period_days' => 30, 'severity' => 'medium', 'action' => 'escalate',         'auto_escalate' => false],
            ['code' => 'remote_gps_required',     'name' => 'Remote Work GPS Verification',   'category' => 'remote_work',       'threshold' => 1,  'threshold_unit' => 'count',      'period_days' => 30, 'severity' => 'low',    'action' => 'warn',             'auto_escalate' => false],
        ];

        foreach ($defaults as $rule) {
            AttendancePolicyRule::firstOrCreate(['code' => $rule['code']], $rule);
        }
    }
}
