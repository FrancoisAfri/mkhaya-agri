<?php

namespace App\Modules\HR\Hr\Services;

use App\Modules\HR\Hr\Models\Attendance\AttendanceAnomaly;
use App\Modules\HR\Hr\Models\Attendance\AttendanceBehaviorPattern;
use App\Modules\HR\Hr\Models\Attendance\AttendanceClocking;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRiskScore;
use App\Modules\HR\Hr\Models\Attendance\AttendanceSetting;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveRequestStatus;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Services\WorkingDaysCalculator;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceAnomalyService
{
    // Configurable thresholds
    private const REPEATED_LATE_THRESHOLD          = 3;   // occurrences in period
    private const ABSENT_THRESHOLD_PERCENT         = 20;  // % of working days
    private const EXCESSIVE_OVERTIME_HOURS         = 4;   // hours over standard per day
    private const EXCESSIVE_OVERTIME_DAYS          = 5;   // days in period
    private const BUDDY_CLOCK_SECONDS              = 120; // within 2 minutes
    private const BUDDY_CLOCK_MIN_OCCURRENCES      = 3;
    private const SUSPICIOUS_PATTERN_THRESHOLD     = 3;   // same weekday late
    private const SHIFT_ABUSE_MINUTES              = 30;  // clocking outside shift window

    // ── Public API ────────────────────────────────────────────────────────────

    public function runFullScan(string $periodStart, string $periodEnd): array
    {
        $employees = HRPerson::where('status', 1)->pluck('id');
        $results   = [];

        foreach ($employees as $employeeId) {
            $detected = $this->scanEmployee($employeeId, $periodStart, $periodEnd);
            if (!empty($detected)) {
                $results[$employeeId] = $detected;
            }
            $this->generateAttendanceRiskScore($employeeId, $periodStart, $periodEnd);
            $this->buildBehaviorPatterns($employeeId, $periodStart, $periodEnd);
        }

        return $results;
    }

    public function scanEmployee(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $anomalies = [];

        // No-show detection runs even with zero records (a full-period absence has no records).
        $noShow = $this->detectUnauthorisedAbsence($employeeId, $periodStart, $periodEnd);

        $records = $this->getRecords($employeeId, $periodStart, $periodEnd);
        if ($records->isEmpty()) {
            return $noShow ? [$noShow] : [];
        }

        $late    = $this->detectRepeatedLateness($employeeId, $records, $periodStart, $periodEnd);
        $absent  = $this->detectUnusualAbsenteeism($employeeId, $records, $periodStart, $periodEnd);
        $ot      = $this->detectExcessiveOvertime($employeeId, $records, $periodStart, $periodEnd);
        $manip   = $this->detectAttendanceManipulation($employeeId, $records, $periodStart, $periodEnd);
        $buddy   = $this->detectBuddyClocking($employeeId, $periodStart, $periodEnd);
        $missing = $this->detectMissingClockouts($employeeId, $periodStart, $periodEnd);
        $pattern = $this->detectSuspiciousAttendancePatterns($employeeId, $records, $periodStart, $periodEnd);
        $shift   = $this->detectShiftAbuse($employeeId, $periodStart, $periodEnd);

        foreach (array_filter([$noShow, $late, $absent, $ot, $manip, $buddy, $missing, $pattern, $shift]) as $anomaly) {
            $anomalies[] = $anomaly;
        }

        return $anomalies;
    }

    /**
     * Unauthorised absence — working days in the period where the employee neither clocked in
     * (ERS) nor had approved leave. Catches anything the daily ERS absence cron missed.
     */
    public function detectUnauthorisedAbsence(int $employeeId, string $start, string $end): ?AttendanceAnomaly
    {
        $calc = new WorkingDaysCalculator();
        $missed = [];

        $cursor = Carbon::parse($start)->startOfDay();
        $last   = Carbon::parse($end)->startOfDay();
        while ($cursor->lte($last)) {
            if ($calc->isWorkingDay($cursor)) {
                $d = $cursor->toDateString();

                $clockedIn = AttendanceRecord::where('employee_id', $employeeId)
                    ->whereDate('date', $d)->whereNotNull('clock_in')->exists();

                if (! $clockedIn) {
                    $onLeave = LeaveRequest::where('employee_id', $employeeId)
                        ->where('status', LeaveRequestStatus::Approved->value)
                        ->whereDate('start_date', '<=', $d)->whereDate('end_date', '>=', $d)->exists();
                    if (! $onLeave) {
                        $missed[] = $d;
                    }
                }
            }
            $cursor->addDay();
        }

        if (! $missed) {
            return null;
        }

        $count    = count($missed);
        $severity = match (true) {
            $count >= 4 => 'high',
            $count >= 2 => 'medium',
            default     => 'low',
        };

        return $this->createAnomaly($employeeId, 'unauthorised_absence', $severity, $start, $end,
            "Employee did not clock in and had no approved leave on {$count} working day(s) between {$start} and {$end}.",
            ['absent_dates' => $missed, 'count' => $count]
        );
    }

    // ── Detection Functions ───────────────────────────────────────────────────

    public function detectRepeatedLateness(int $employeeId, Collection $records, string $start, string $end): ?AttendanceAnomaly
    {
        $lateRecords = $records->where('is_late', true);
        $count       = $lateRecords->count();

        if ($count < self::REPEATED_LATE_THRESHOLD) {
            return null;
        }

        $lateDates      = $lateRecords->pluck('date')->map(fn($d) => Carbon::parse($d)->toDateString())->toArray();
        $avgLateMinutes = round($lateRecords->avg('late_minutes'), 1);
        $maxLateMinutes = $lateRecords->max('late_minutes');
        $severity       = match (true) {
            $count >= 10 => 'critical',
            $count >= 7  => 'high',
            $count >= 5  => 'medium',
            default      => 'low',
        };

        return $this->createAnomaly($employeeId, 'repeated_lateness', $severity, $start, $end,
            "Employee was late {$count} times between {$start} and {$end}. " .
            "Average lateness: {$avgLateMinutes} minutes. Maximum: {$maxLateMinutes} minutes.",
            ['late_count' => $count, 'late_dates' => $lateDates, 'avg_minutes' => $avgLateMinutes, 'max_minutes' => $maxLateMinutes]
        );
    }

    public function detectUnusualAbsenteeism(int $employeeId, Collection $records, string $start, string $end): ?AttendanceAnomaly
    {
        $workingDays = $this->countWorkingDays($start, $end);
        if ($workingDays === 0) {
            return null;
        }

        $presentDays    = $records->filter(fn($r) => $r->clock_in !== null)->count();
        $absentDays     = $workingDays - $presentDays;
        $absencePercent = round(($absentDays / $workingDays) * 100, 1);

        if ($absencePercent < self::ABSENT_THRESHOLD_PERCENT) {
            return null;
        }

        $severity = match (true) {
            $absencePercent >= 50 => 'critical',
            $absencePercent >= 35 => 'high',
            $absencePercent >= 25 => 'medium',
            default               => 'low',
        };

        return $this->createAnomaly($employeeId, 'unusual_absenteeism', $severity, $start, $end,
            "Employee was absent {$absentDays} of {$workingDays} working days ({$absencePercent}%) between {$start} and {$end}.",
            ['absent_days' => $absentDays, 'working_days' => $workingDays, 'absence_percent' => $absencePercent]
        );
    }

    public function detectExcessiveOvertime(int $employeeId, Collection $records, string $start, string $end): ?AttendanceAnomaly
    {
        $excessiveDays = $records->filter(fn($r) => $r->overtime_hours >= self::EXCESSIVE_OVERTIME_HOURS);
        $count         = $excessiveDays->count();

        if ($count < self::EXCESSIVE_OVERTIME_DAYS) {
            return null;
        }

        $totalOt  = round($excessiveDays->sum('overtime_hours'), 1);
        $severity = match (true) {
            $count >= 15 || $totalOt >= 60 => 'critical',
            $count >= 10 || $totalOt >= 40 => 'high',
            $count >= 7  || $totalOt >= 25 => 'medium',
            default                         => 'low',
        };

        return $this->createAnomaly($employeeId, 'excessive_overtime', $severity, $start, $end,
            "Employee worked excessive overtime on {$count} days ({$totalOt} total OT hours) between {$start} and {$end}. " .
            "Each day exceeded " . self::EXCESSIVE_OVERTIME_HOURS . " hours over standard.",
            ['excessive_ot_days' => $count, 'total_ot_hours' => $totalOt, 'threshold_hours' => self::EXCESSIVE_OVERTIME_HOURS]
        );
    }

    public function detectAttendanceManipulation(int $employeeId, Collection $records, string $start, string $end): ?AttendanceAnomaly
    {
        // Flags: clock-in/out within 1 minute of each other (impossible commute), or
        // hours_worked exactly matching shift hours every single day (suspiciously consistent)
        $suspiciousDays = [];

        foreach ($records as $record) {
            if (!$record->clock_in || !$record->clock_out) {
                continue;
            }

            $diffMinutes = Carbon::parse($record->clock_in)->diffInMinutes(Carbon::parse($record->clock_out));

            // Clock-in and clock-out within 3 minutes
            if ($diffMinutes < 3) {
                $suspiciousDays[] = ['date' => $record->date, 'reason' => 'clock_in_out_same_time', 'diff_minutes' => $diffMinutes];
            }
        }

        // Detect perfectly round hours every day (e.g., exactly 8.00 hours every day for 10+ days)
        $roundHourDays = $records->filter(fn($r) => $r->hours_worked > 0 && fmod((float)$r->hours_worked, 1.0) === 0.0);
        if ($roundHourDays->count() >= 10 && $roundHourDays->count() === $records->filter(fn($r) => $r->hours_worked > 0)->count()) {
            $suspiciousDays[] = ['reason' => 'perfectly_round_hours_every_day', 'count' => $roundHourDays->count()];
        }

        if (empty($suspiciousDays)) {
            return null;
        }

        return $this->createAnomaly($employeeId, 'manipulation', 'high', $start, $end,
            "Suspicious attendance patterns detected suggesting possible record manipulation. " .
            count($suspiciousDays) . " indicator(s) found between {$start} and {$end}.",
            ['indicators' => $suspiciousDays]
        );
    }

    public function detectBuddyClocking(int $employeeId, string $start, string $end): ?AttendanceAnomaly
    {
        // Find other employees who clocked in within BUDDY_CLOCK_SECONDS of this employee, repeatedly
        $clockings = AttendanceClocking::forEmployee($employeeId)
            ->clockIns()
            ->whereBetween('clocked_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->get(['id', 'clocked_at']);

        $buddyPairs = [];

        foreach ($clockings as $clocking) {
            $window = [
                Carbon::parse($clocking->clocked_at)->subSeconds(self::BUDDY_CLOCK_SECONDS)->toDateTimeString(),
                Carbon::parse($clocking->clocked_at)->addSeconds(self::BUDDY_CLOCK_SECONDS)->toDateTimeString(),
            ];

            $nearbyEmployees = AttendanceClocking::clockIns()
                ->whereBetween('clocked_at', $window)
                ->where('employee_id', '!=', $employeeId)
                ->pluck('employee_id');

            foreach ($nearbyEmployees as $peerId) {
                $key = min($employeeId, $peerId) . '-' . max($employeeId, $peerId);
                $buddyPairs[$key] = ($buddyPairs[$key] ?? 0) + 1;
            }
        }

        $confirmed = array_filter($buddyPairs, fn($count) => $count >= self::BUDDY_CLOCK_MIN_OCCURRENCES);

        if (empty($confirmed)) {
            return null;
        }

        $pairs = array_map(fn($key, $count) => ['pair' => $key, 'occurrences' => $count], array_keys($confirmed), $confirmed);

        return $this->createAnomaly($employeeId, 'buddy_clocking', 'high', $start, $end,
            "Possible buddy clocking detected. Employee consistently clocked in within " . self::BUDDY_CLOCK_SECONDS . " seconds of " .
            count($pairs) . " other employee(s) on " . array_sum(array_column($pairs, 'occurrences')) . " occasions.",
            ['pairs' => $pairs, 'window_seconds' => self::BUDDY_CLOCK_SECONDS]
        );
    }

    public function detectMissingClockouts(int $employeeId, string $start, string $end): ?AttendanceAnomaly
    {
        $missingCount = AttendanceClocking::forEmployee($employeeId)
            ->where('is_missed', true)
            ->where('type', 'clock_out')
            ->whereBetween('clocked_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->count();

        if ($missingCount < 3) {
            return null;
        }

        $severity = match (true) {
            $missingCount >= 10 => 'high',
            $missingCount >= 6  => 'medium',
            default             => 'low',
        };

        return $this->createAnomaly($employeeId, 'missing_clockout', $severity, $start, $end,
            "Employee had {$missingCount} missing clock-outs (auto-detected) between {$start} and {$end}.",
            ['missing_clockout_count' => $missingCount]
        );
    }

    public function detectSuspiciousAttendancePatterns(int $employeeId, Collection $records, string $start, string $end): ?AttendanceAnomaly
    {
        // Detect: always late on specific weekday (e.g., every Monday)
        $lateByDay = [];
        foreach ($records->where('is_late', true) as $r) {
            $dayName = Carbon::parse($r->date)->dayName;
            $lateByDay[$dayName] = ($lateByDay[$dayName] ?? 0) + 1;
        }

        $patternDays = array_filter($lateByDay, fn($count) => $count >= self::SUSPICIOUS_PATTERN_THRESHOLD);

        if (empty($patternDays)) {
            return null;
        }

        $dayList = implode(', ', array_keys($patternDays));

        return $this->createAnomaly($employeeId, 'suspicious_pattern', 'medium', $start, $end,
            "Employee shows a recurring lateness pattern on specific days of the week: {$dayList}. " .
            "This may indicate a systematic avoidance of certain days.",
            ['late_by_weekday' => $patternDays, 'threshold' => self::SUSPICIOUS_PATTERN_THRESHOLD]
        );
    }

    public function detectShiftAbuse(int $employeeId, string $start, string $end): ?AttendanceAnomaly
    {
        // Detect clockings that happen far outside the employee's assigned shift
        $offShiftClockings = AttendanceClocking::forEmployee($employeeId)
            ->clockIns()
            ->whereBetween('clocked_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->where('is_missed', false)
            ->with('attendanceRecord.shift')
            ->get()
            ->filter(function ($clocking) {
                $shift = $clocking->attendanceRecord?->shift;
                if (!$shift) {
                    return false;
                }
                $shiftStart = Carbon::parse($clocking->clocked_at->toDateString() . ' ' . $shift->start_time);
                $diffMinutes = abs($shiftStart->diffInMinutes(Carbon::parse($clocking->clocked_at)));
                return $diffMinutes > self::SHIFT_ABUSE_MINUTES;
            });

        $count = $offShiftClockings->count();
        if ($count < 3) {
            return null;
        }

        $severity = $count >= 8 ? 'high' : 'medium';

        return $this->createAnomaly($employeeId, 'shift_abuse', $severity, $start, $end,
            "Employee clocked in more than " . self::SHIFT_ABUSE_MINUTES . " minutes outside their assigned shift window on {$count} occasions between {$start} and {$end}.",
            ['off_shift_count' => $count, 'threshold_minutes' => self::SHIFT_ABUSE_MINUTES]
        );
    }

    // ── Risk Scoring ──────────────────────────────────────────────────────────

    public function generateAttendanceRiskScore(int $employeeId, string $start, string $end): AttendanceRiskScore
    {
        $records  = $this->getRecords($employeeId, $start, $end);
        $settings = AttendanceSetting::current();

        $workingDays      = $this->countWorkingDays($start, $end);
        $presentDays      = $records->filter(fn($r) => $r->clock_in !== null)->count();
        $lateCount        = $records->where('is_late', true)->count();
        $absentCount      = max(0, $workingDays - $presentDays);
        $missedClockouts  = AttendanceClocking::forEmployee($employeeId)
            ->where('is_missed', true)->where('type', 'clock_out')
            ->whereBetween('clocked_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->count();
        $anomalyCount     = AttendanceAnomaly::forEmployee($employeeId)
            ->where('status', 'open')
            ->whereBetween('period_start', [$start, $end])
            ->count();
        $excessiveOtDays  = $records->filter(fn($r) => $r->overtime_hours >= self::EXCESSIVE_OVERTIME_HOURS)->count();
        $attendanceRate   = $workingDays > 0 ? round(($presentDays / $workingDays) * 100, 2) : 100;

        // Score = weighted sum (0-100)
        $breakdown = [
            'lateness'        => ['value' => $lateCount,       'weight' => 2.5,  'max' => 25],
            'absenteeism'     => ['value' => $absentCount,     'weight' => 3.0,  'max' => 30],
            'missed_clockout' => ['value' => $missedClockouts, 'weight' => 1.5,  'max' => 15],
            'anomalies'       => ['value' => $anomalyCount,    'weight' => 5.0,  'max' => 20],
            'excessive_ot'    => ['value' => $excessiveOtDays, 'weight' => 1.0,  'max' => 10],
        ];

        $score = 0;
        foreach ($breakdown as $key => &$factor) {
            $contribution        = min($factor['value'] * $factor['weight'], $factor['max']);
            $factor['score']     = round($contribution, 1);
            $score              += $contribution;
        }
        $score = min(100, round($score, 2));

        return AttendanceRiskScore::updateOrCreate(
            ['employee_id' => $employeeId, 'period_start' => $start, 'period_end' => $end],
            [
                'score'                  => $score,
                'risk_level'             => AttendanceRiskScore::levelFromScore($score),
                'calculated_at'          => now(),
                'late_count'             => $lateCount,
                'absent_count'           => $absentCount,
                'missed_clockout_count'  => $missedClockouts,
                'anomaly_count'          => $anomalyCount,
                'overtime_excessive_days' => $excessiveOtDays,
                'attendance_rate'        => $attendanceRate,
                'score_breakdown'        => $breakdown,
            ]
        );
    }

    // ── Behavior Pattern Builder ──────────────────────────────────────────────

    public function buildBehaviorPatterns(int $employeeId, string $start, string $end, string $periodType = 'monthly'): void
    {
        $records = $this->getRecords($employeeId, $start, $end);
        if ($records->isEmpty()) {
            return;
        }

        // Late day pattern
        $lateByDay = [];
        foreach ($records->where('is_late', true) as $r) {
            $day = Carbon::parse($r->date)->dayName;
            $lateByDay[$day] = ($lateByDay[$day] ?? 0) + 1;
        }
        if (!empty($lateByDay)) {
            AttendanceBehaviorPattern::updateOrCreate(
                ['employee_id' => $employeeId, 'pattern_type' => 'late_days', 'period_start' => $start, 'period_end' => $end],
                ['period_type' => $periodType, 'data' => ['by_weekday' => $lateByDay, 'total' => array_sum($lateByDay)], 'confidence' => 80]
            );
        }

        // Absence days
        $workingDays = $this->countWorkingDays($start, $end);
        $absentCount = max(0, $workingDays - $records->filter(fn($r) => $r->clock_in !== null)->count());
        AttendanceBehaviorPattern::updateOrCreate(
            ['employee_id' => $employeeId, 'pattern_type' => 'absence_days', 'period_start' => $start, 'period_end' => $end],
            ['period_type' => $periodType, 'data' => ['absent_count' => $absentCount, 'working_days' => $workingDays], 'confidence' => 90]
        );

        // Overtime pattern
        $otDays = $records->filter(fn($r) => $r->overtime_hours > 0);
        if ($otDays->isNotEmpty()) {
            AttendanceBehaviorPattern::updateOrCreate(
                ['employee_id' => $employeeId, 'pattern_type' => 'overtime_days', 'period_start' => $start, 'period_end' => $end],
                ['period_type' => $periodType, 'data' => [
                    'ot_days'     => $otDays->count(),
                    'total_hours' => round($otDays->sum('overtime_hours'), 2),
                    'avg_hours'   => round($otDays->avg('overtime_hours'), 2),
                ], 'confidence' => 85]
            );
        }

        // Work type preference
        $workTypes = AttendanceClocking::forEmployee($employeeId)
            ->clockIns()
            ->whereBetween('clocked_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->selectRaw('work_type, COUNT(*) as count')
            ->groupBy('work_type')
            ->pluck('count', 'work_type')
            ->toArray();
        if (!empty($workTypes)) {
            AttendanceBehaviorPattern::updateOrCreate(
                ['employee_id' => $employeeId, 'pattern_type' => 'work_type_preference', 'period_start' => $start, 'period_end' => $end],
                ['period_type' => $periodType, 'data' => $workTypes, 'confidence' => 75]
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createAnomaly(
        int $employeeId, string $type, string $severity,
        string $start, string $end, string $description, array $evidence
    ): AttendanceAnomaly {
        return AttendanceAnomaly::updateOrCreate(
            ['employee_id' => $employeeId, 'anomaly_type' => $type, 'period_start' => $start, 'period_end' => $end],
            [
                'severity'    => $severity,
                'detected_at' => now(),
                'description' => $description,
                'evidence'    => $evidence,
                'status'      => 'open',
            ]
        );
    }

    private function getRecords(int $employeeId, string $start, string $end): Collection
    {
        return AttendanceRecord::forEmployee($employeeId)
            ->whereBetween('date', [$start, $end])
            ->get();
    }

    private function countWorkingDays(string $start, string $end): int
    {
        $count = 0;
        foreach (CarbonPeriod::create($start, $end) as $date) {
            if (!$date->isWeekend()) {
                $count++;
            }
        }
        return $count;
    }
}
