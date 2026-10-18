<?php

namespace App\Modules\HR\Hr\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDisciplineCase;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDisciplineOffender;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDisciplinePack;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Shared\DTOs\AgentRequest;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceDisciplineService
{
    // Thresholds for automatic case recommendation
    private const LATE_THRESHOLD          = 5;   // 5+ lates → written warning
    private const ABSENT_THRESHOLD_PCT    = 20;  // 20%+ absence → written warning
    private const NO_SHOW_THRESHOLD       = 3;   // 3+ no-shows → final warning
    private const REPEAT_OFFENDER_CASES   = 2;   // 2+ prior cases → serious level

    public function __construct(
        private readonly AgentRegistry $agentRegistry
    ) {}

    // ── Run full discipline scan ──────────────────────────────────────────────

    public function runDisciplineScan(string $periodStart, string $periodEnd, int $userId): array
    {
        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->select('id', 'first_name', 'surname')
            ->get();

        $caseCount     = 0;
        $offenderCount = 0;

        foreach ($employees as $emp) {
            $offender = $this->detectRepeatOffenders($emp->id, $periodStart, $periodEnd);
            if ($offender) $offenderCount++;

            $case = $this->generateDisciplinaryRecommendation($emp->id, $periodStart, $periodEnd, $userId);
            if ($case) $caseCount++;
        }

        return ['cases' => $caseCount, 'offenders' => $offenderCount];
    }

    // ── detectRepeatOffenders ─────────────────────────────────────────────────

    public function detectRepeatOffenders(int $employeeId, string $periodStart, string $periodEnd): ?AttendanceDisciplineOffender
    {
        $records     = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get();

        $workingDays      = $this->workingDays($periodStart, $periodEnd);
        $lateCount        = $records->where('is_late', true)->count();
        $earlyDepartCount = $records->where('is_early_departure', true)->count();
        $presentDays      = $records->whereNotNull('clock_in')->count();
        $absentCount      = max(0, $workingDays - $presentDays);
        $noShowCount      = $records->whereNull('clock_in')->where('manager_approved', false)->count();

        $totalViolations = $lateCount + $absentCount + $noShowCount + $earlyDepartCount;

        if ($totalViolations < 3) return null;

        $priorCases  = AttendanceDisciplineCase::where('employee_id', $employeeId)
            ->where('period_end', '<', $periodStart)
            ->count();

        $level = match(true) {
            $priorCases >= self::REPEAT_OFFENDER_CASES || $noShowCount >= self::NO_SHOW_THRESHOLD => 'repeat',
            $totalViolations >= 15 => 'serious',
            $totalViolations >= 8  => 'moderate',
            default                => 'minor',
        };

        $firstRecord = $records->whereNotNull('clock_in')->sortBy('date')->first();
        $lastRecord  = $records->where(fn($r) => $r->is_late || $r->is_early_departure)->sortByDesc('date')->first();

        return AttendanceDisciplineOffender::updateOrCreate(
            ['employee_id' => $employeeId, 'period_start' => $periodStart, 'period_end' => $periodEnd],
            [
                'total_violations'    => $totalViolations,
                'late_count'          => $lateCount,
                'absent_count'        => $absentCount,
                'no_show_count'       => $noShowCount,
                'early_departure_count'=> $earlyDepartCount,
                'prior_warnings'      => $priorCases,
                'offender_level'      => $level,
                'offender_color'      => AttendanceDisciplineOffender::colorFromLevel($level),
                'first_offence_date'  => $firstRecord?->date,
                'last_offence_date'   => $lastRecord?->date ?? $records->last()?->date,
                'violation_breakdown' => [
                    'late'            => $lateCount,
                    'absent'          => $absentCount,
                    'no_show'         => $noShowCount,
                    'early_departure' => $earlyDepartCount,
                    'prior_cases'     => $priorCases,
                ],
                'assessed_at' => now(),
            ]
        );
    }

    // ── generateDisciplinaryRecommendation ────────────────────────────────────

    public function generateDisciplinaryRecommendation(int $employeeId, string $periodStart, string $periodEnd, int $userId): ?AttendanceDisciplineCase
    {
        $records     = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get();

        $workingDays  = $this->workingDays($periodStart, $periodEnd);
        $lateCount    = $records->where('is_late', true)->count();
        $presentDays  = $records->whereNotNull('clock_in')->count();
        $absentCount  = max(0, $workingDays - $presentDays);
        $absentRate   = $workingDays > 0 ? $absentCount / $workingDays * 100 : 0;
        $noShowCount  = $records->whereNull('clock_in')->where('manager_approved', false)->count();
        $priorCases   = AttendanceDisciplineCase::where('employee_id', $employeeId)->where('status', '!=', 'dismissed')->count();

        // Determine if case is warranted
        $warranted = $lateCount >= self::LATE_THRESHOLD
            || $absentRate >= self::ABSENT_THRESHOLD_PCT
            || $noShowCount >= self::NO_SHOW_THRESHOLD;

        if (! $warranted) return null;

        // Already has an open case this period?
        $existing = AttendanceDisciplineCase::where('employee_id', $employeeId)
            ->where('period_start', $periodStart)
            ->where('status', 'pending')
            ->first();

        if ($existing) return null;

        // Case type escalation
        $caseType = match(true) {
            $priorCases >= 3 || $noShowCount >= self::NO_SHOW_THRESHOLD => 'final_warning',
            $priorCases >= 2 => 'written_warning',
            $priorCases >= 1 => 'written_warning',
            default          => 'verbal_warning',
        };

        $severity = match($caseType) {
            'final_warning' => 'critical',
            'written_warning'=> 'high',
            default          => 'medium',
        };

        $violations = [];
        if ($lateCount >= self::LATE_THRESHOLD)   $violations[] = "Late arrivals: {$lateCount} occurrences";
        if ($absentRate >= self::ABSENT_THRESHOLD_PCT) $violations[] = 'Absenteeism rate: ' . round($absentRate, 1) . '%';
        if ($noShowCount > 0)                     $violations[] = "Unauthorised no-shows: {$noShowCount}";

        return AttendanceDisciplineCase::create([
            'employee_id'   => $employeeId,
            'case_number'   => 'ATT-' . strtoupper(Str::random(6)),
            'case_type'     => $caseType,
            'status'        => 'pending',
            'severity'      => $severity,
            'severity_color'=> AttendanceDisciplineCase::SEVERITY_COLORS[$severity] ?? 'warning',
            'period_start'  => $periodStart,
            'period_end'    => $periodEnd,
            'summary'       => "Attendance violations detected: " . implode('; ', $violations),
            'violations'    => $violations,
            'created_by'    => $userId,
        ]);
    }

    // ── generateWarningRecommendation (AI) ────────────────────────────────────

    public function generateWarningRecommendation(AttendanceDisciplineCase $case): string
    {
        $emp  = DB::table('hr_people')->where('id', $case->employee_id)->select('first_name', 'surname')->first();
        $name = $emp ? "{$emp->first_name} {$emp->surname}" : "Employee #{$case->employee_id}";
        $violations = implode(', ', $case->violations ?? []);
        $caseLabel  = AttendanceDisciplineCase::CASE_TYPES[$case->case_type] ?? $case->case_type;

$request = AgentRequest::make()
    ->withSystemPrompt('You are an HR disciplinary advisor. Write fair, legally grounded, evidence-based warning recommendations. Be professional and objective. Reference the specific violations. 3-5 sentences.')
    ->withPrompt("Write a {$caseLabel} recommendation for {$name}. Violations: {$violations}. Period: {$case->period_start->toDateString()} to {$case->period_end->toDateString()}. Severity: {$case->severity}. Provide the recommended course of action.");

        try {
            $response = $this->agentRegistry->for('attendance_discipline')->ask($request);
            return $response->content ?? 'Recommendation unavailable.';
        } catch (\Throwable) {
            return "Based on the attendance record showing {$violations}, a {$caseLabel} is recommended in line with company policy.";
        }
    }

    // ── generateDisciplinaryPack (AI-assembled evidence pack) ─────────────────

    public function generateDisciplinaryPack(AttendanceDisciplineCase $case, int $userId): AttendanceDisciplinePack
    {
        $records = AttendanceRecord::where('employee_id', $case->employee_id)
            ->whereBetween('date', [$case->period_start->toDateString(), $case->period_end->toDateString()])
            ->orderBy('date')
            ->get();

        $workingDays  = $this->workingDays($case->period_start->toDateString(), $case->period_end->toDateString());
        $presentDays  = $records->whereNotNull('clock_in')->count();
        $lateCount    = $records->where('is_late', true)->count();
        $totalOt      = round($records->sum('overtime_hours'), 1);
        $absentCount  = max(0, $workingDays - $presentDays);

        $attendanceSummary = [
            'period'        => $case->period_start->toDateString() . ' to ' . $case->period_end->toDateString(),
            'working_days'  => $workingDays,
            'present_days'  => $presentDays,
            'absent_days'   => $absentCount,
            'attendance_pct'=> round($presentDays / max(1, $workingDays) * 100, 1),
            'late_arrivals' => $lateCount,
            'overtime_hours'=> $totalOt,
        ];

        $violationDetails = [];
        foreach ($records->where('is_late', true) as $r) {
            $violationDetails[] = [
                'date'   => $r->date->toDateString(),
                'type'   => 'late_arrival',
                'detail' => "Late by {$r->late_minutes} minutes",
            ];
        }
        foreach ($records->whereNull('clock_in') as $r) {
            $violationDetails[] = [
                'date'   => $r->date->toDateString(),
                'type'   => 'absence',
                'detail' => 'No clock-in recorded',
            ];
        }

        // AI narrative for pack
        $emp     = DB::table('hr_people')->where('id', $case->employee_id)->select('first_name', 'surname')->first();
        $name    = $emp ? "{$emp->first_name} {$emp->surname}" : "Employee #{$case->employee_id}";
        $caseLabel = AttendanceDisciplineCase::CASE_TYPES[$case->case_type] ?? $case->case_type;
        $summaryStr = json_encode($attendanceSummary);

      $request = AgentRequest::make()
    ->withSystemPrompt('You are an HR legal advisor drafting attendance disciplinary documentation. Write factual, objective, and legally sound content. No emotional language. Reference specific dates and counts. 4-6 paragraphs.')
    ->withPrompt("Draft a disciplinary pack narrative for {$name}. Case type: {$caseLabel}. Case number: {$case->case_number}. Attendance summary: {$summaryStr}. Violations: " . implode(', ', $case->violations ?? []) . ". Include: factual summary, pattern description, policy references, and recommended sanction.");

        $narrative = 'AI narrative unavailable.';
        try {
            $response  = $this->agentRegistry->for('attendance_discipline')->ask($request);
            $narrative = $response->content ?? $narrative;
        } catch (\Throwable) {}

        // Warning letter
        $warningLetter = $this->composeWarningLetter($name, $case, $attendanceSummary);

        $pack = AttendanceDisciplinePack::create([
            'discipline_case_id'   => $case->id,
            'employee_id'          => $case->employee_id,
            'pack_type'            => 'full',
            'attendance_summary'   => $attendanceSummary,
            'violation_details'    => $violationDetails,
            'pattern_analysis'     => $case->violations,
            'ai_narrative'         => $narrative,
            'warning_letter_content'=> $warningLetter,
            'recommended_sanction' => $caseLabel,
            'supporting_references'=> ['Attendance Policy', 'Employment Equity Act', 'BCEA', 'Company Disciplinary Code'],
            'generated_by'         => $userId,
            'generated_at'         => now(),
        ]);

        // Update case with AI recommendation
        $recommendation = $this->generateWarningRecommendation($case);
        $case->update(['ai_recommendation' => $recommendation, 'status' => 'in_progress']);

        return $pack;
    }

    // ── Compose warning letter template ───────────────────────────────────────

    private function composeWarningLetter(string $employeeName, AttendanceDisciplineCase $case, array $summary): string
    {
        $date      = now()->format('d F Y');
        $caseLabel = AttendanceDisciplineCase::CASE_TYPES[$case->case_type] ?? $case->case_type;
        $violations = implode("\n- ", $case->violations ?? []);

        return <<<LETTER
DATE: {$date}
CASE NUMBER: {$case->case_number}
TYPE: {$caseLabel}

ADDRESSEE: {$employeeName}

Dear {$employeeName},

RE: NOTICE OF {$caseLabel}

This letter serves as formal notice of a {$caseLabel} regarding your attendance record for the period {$case->period_start->toDateString()} to {$case->period_end->toDateString()}.

VIOLATIONS IDENTIFIED:
- {$violations}

ATTENDANCE SUMMARY:
- Working Days in Period: {$summary['working_days']}
- Days Present: {$summary['present_days']} ({$summary['attendance_pct']}%)
- Days Absent: {$summary['absent_days']}
- Late Arrivals: {$summary['late_arrivals']}

You are required to meet with your line manager within 5 working days of receipt of this notice to discuss your attendance record and provide any mitigating circumstances.

Failure to improve your attendance record may result in further disciplinary action, up to and including dismissal.

This warning will remain on your personnel file for a period of 12 months from the date of issue.

Yours sincerely,
Human Resources Department
LETTER;
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
