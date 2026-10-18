<?php

namespace App\Modules\HR\Hr\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceClocking;
use App\Modules\HR\Hr\Models\Attendance\AttendanceWellnessScore;
use App\Modules\HR\Hr\Models\Attendance\BurnoutRiskProfile;
use App\Modules\HR\Hr\Models\Attendance\WorkforcePressurePrediction;
use App\Shared\DTOs\AgentRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkforcePressureService
{
    private const OT_WEEKLY_THRESHOLD   = 10;  // hours per week before flagging
    private const CONSECUTIVE_DAY_LIMIT = 6;   // days without rest
    private const REST_DEFICIT_HOURS    = 8;   // minimum rest between shifts
    private const ABSENTEEISM_SPIKE_PCT = 30;  // % above baseline triggers spike
    private const TURNOVER_SCORE_CUTOFF = 65;  // combined score triggers turnover risk

    public function __construct(
        private readonly AgentRegistry $agentRegistry
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    public function runPressureScan(string $periodStart, string $periodEnd): array
    {
        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->select('id', 'first_name', 'surname', 'division_level_1')
            ->get();

        $wellnessScores = [];
        $burnoutProfiles = [];

        foreach ($employees as $emp) {
            $wellness = $this->generateWellnessScore($emp->id, $periodStart, $periodEnd);
            $wellnessScores[] = $wellness;

            if ($wellness->burnout_risk !== 'low') {
                $burnoutProfiles[] = $this->detectBurnoutRisk($emp->id, $periodStart, $periodEnd);
            }
        }

        $predictions = array_merge(
            $this->predictAbsenteeismSpike($periodStart, $periodEnd),
            $this->predictTurnoverRisk($periodStart, $periodEnd),
            $this->detectUnderstaffedDepartments($periodEnd)
        );

        return [
            'wellness_scores'  => count($wellnessScores),
            'burnout_profiles' => count($burnoutProfiles),
            'predictions'      => count($predictions),
        ];
    }

    public function generateWellnessScore(int $employeeId, string $periodStart, string $periodEnd): AttendanceWellnessScore
    {
        $records = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get();

        $workingDays  = $this->countWorkingDays($periodStart, $periodEnd);
        $presentDays  = $records->where('clock_in', '!=', null)->count();
        $absentDays   = max(0, $workingDays - $presentDays);
        $totalOt      = $records->sum('overtime_hours');
        $lateCount    = $records->where('is_late', true)->count();
        $absentRate   = $workingDays > 0 ? round(($absentDays / $workingDays) * 100, 1) : 0;

        $consecutive  = $this->maxConsecutiveDays($employeeId, $periodStart, $periodEnd);
        $restDeficit  = $this->calculateRestDeficit($employeeId, $periodStart, $periodEnd);
        $weekendWork  = $this->countWeekendWork($employeeId, $periodStart, $periodEnd);

        // Wellness score starts at 100, penalties deducted
        $score = 100;
        $score -= min(25, $totalOt * 1.5);               // OT penalty max 25
        $score -= min(20, $consecutive * 2.5);            // consecutive days max 20
        $score -= min(20, $absentRate * 0.5);             // absenteeism max 20
        $score -= min(15, $lateCount * 2);                // lateness max 15
        $score -= min(10, $restDeficit * 1);              // rest deficit max 10
        $score -= min(10, $weekendWork * 2);              // weekend work max 10
        $score = max(0, (int) round($score));

        $burnoutRisk = match(true) {
            $score <= 30 => 'critical',
            $score <= 50 => 'high',
            $score <= 70 => 'medium',
            default      => 'low',
        };

        $fatigueLevel = match(true) {
            $consecutive >= 7 || $restDeficit >= 16 => 'high',
            $consecutive >= 5 || $restDeficit >= 8  => 'medium',
            default                                  => 'low',
        };

        return AttendanceWellnessScore::updateOrCreate(
            ['employee_id' => $employeeId, 'period_start' => $periodStart, 'period_end' => $periodEnd],
            [
                'wellness_score'         => $score,
                'burnout_risk'           => $burnoutRisk,
                'fatigue_level'          => $fatigueLevel,
                'total_overtime_hours'   => round($totalOt, 2),
                'consecutive_days_worked'=> $consecutive,
                'rest_deficit_hours'     => round($restDeficit, 2),
                'late_arrivals_count'    => $lateCount,
                'absenteeism_rate'       => $absentRate,
                'weekend_work_count'     => $weekendWork,
                'score_breakdown' => [
                    'ot_penalty'         => min(25, round($totalOt * 1.5)),
                    'consecutive_penalty'=> min(20, round($consecutive * 2.5)),
                    'absent_penalty'     => min(20, round($absentRate * 0.5)),
                    'late_penalty'       => min(15, $lateCount * 2),
                    'rest_penalty'       => min(10, round($restDeficit)),
                    'weekend_penalty'    => min(10, $weekendWork * 2),
                ],
                'calculated_at' => now(),
            ]
        );
    }

    public function detectBurnoutRisk(int $employeeId, string $periodStart, string $periodEnd): BurnoutRiskProfile
    {
        $records  = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get();

        $workingDays = $this->countWorkingDays($periodStart, $periodEnd);
        $totalOt     = $records->sum('overtime_hours');
        $lateCount   = $records->where('is_late', true)->count();
        $consecutive = $this->maxConsecutiveDays($employeeId, $periodStart, $periodEnd);
        $absentCount = max(0, $workingDays - $records->where('clock_in', '!=', null)->count());
        $weekendWork = $this->countWeekendWork($employeeId, $periodStart, $periodEnd);

        $otScore          = min(100, (int) ($totalOt / max(1, $workingDays / 5) * 10));
        $consecutiveScore = min(100, (int) ($consecutive / self::CONSECUTIVE_DAY_LIMIT * 100));
        $absenceScore     = min(100, (int) ($absentCount / max(1, $workingDays) * 200));
        $lateScore        = min(100, $lateCount * 10);
        $shiftScore       = min(100, $weekendWork * 12);

        $composite = (int) round(
            ($otScore * 0.30) + ($consecutiveScore * 0.25) +
            ($absenceScore * 0.20) + ($lateScore * 0.15) + ($shiftScore * 0.10)
        );

        $overall = match(true) {
            $composite >= 75 => 'critical',
            $composite >= 50 => 'high',
            $composite >= 25 => 'medium',
            default          => 'low',
        };

        $factors = [];
        if ($totalOt > self::OT_WEEKLY_THRESHOLD)   $factors[] = "Excessive overtime ({$totalOt}h)";
        if ($consecutive >= self::CONSECUTIVE_DAY_LIMIT) $factors[] = "{$consecutive} consecutive working days without rest";
        if ($absentCount >= 3)                       $factors[] = "{$absentCount} unexplained absences";
        if ($lateCount >= 5)                         $factors[] = "{$lateCount} late arrivals";
        if ($weekendWork >= 3)                       $factors[] = "{$weekendWork} weekend shifts";

        $recommendations = [];
        if ($otScore > 50)        $recommendations[] = 'Enforce overtime limits and redistribute workload.';
        if ($consecutiveScore>50) $recommendations[] = 'Schedule mandatory rest day within the next 2 days.';
        if ($absenceScore > 50)   $recommendations[] = 'Conduct welfare check and review workload.';
        if ($lateScore > 50)      $recommendations[] = 'Review commute/home situation; consider flexible start.';
        if ($shiftScore > 50)     $recommendations[] = 'Reduce weekend roster assignments for this employee.';

        return BurnoutRiskProfile::updateOrCreate(
            ['employee_id' => $employeeId, 'period_start' => $periodStart, 'period_end' => $periodEnd],
            [
                'overall_risk'             => $overall,
                'risk_color'               => BurnoutRiskProfile::colorFromRisk($overall),
                'overtime_overload_score'  => $otScore,
                'consecutive_work_score'   => $consecutiveScore,
                'absence_pattern_score'    => $absenceScore,
                'late_arrival_score'       => $lateScore,
                'shift_instability_score'  => $shiftScore,
                'risk_factors'             => $factors,
                'recommendations'          => $recommendations,
                'assessed_at'              => now(),
            ]
        );
    }

    public function detectFatigueRisk(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $consecutive  = $this->maxConsecutiveDays($employeeId, $periodStart, $periodEnd);
        $restDeficit  = $this->calculateRestDeficit($employeeId, $periodStart, $periodEnd);
        $weekendDays  = $this->countWeekendWork($employeeId, $periodStart, $periodEnd);

        $level = match(true) {
            $consecutive >= 7 || $restDeficit >= 16 || $weekendDays >= 5 => 'high',
            $consecutive >= 5 || $restDeficit >= 8  || $weekendDays >= 3 => 'medium',
            default                                                        => 'low',
        };

        return [
            'employee_id'      => $employeeId,
            'fatigue_level'    => $level,
            'consecutive_days' => $consecutive,
            'rest_deficit_h'   => round($restDeficit, 1),
            'weekend_days'     => $weekendDays,
        ];
    }

    public function predictAbsenteeismSpike(string $periodStart, string $periodEnd): array
    {
        $departments = $this->departmentHeadcounts();

        $predictions = [];
        $workingDays = $this->countWorkingDays($periodStart, $periodEnd);

        foreach ($departments as $dept) {
            $empIds = $this->employeeIdsForDepartment($dept->department);

            $absences = AttendanceRecord::whereIn('employee_id', $empIds)
                ->whereBetween('date', [$periodStart, $periodEnd])
                ->where('is_late', false)
                ->whereNull('clock_in')
                ->count();

            $expected = $dept->headcount * $workingDays * 0.05; // 5% baseline absence
            if ($absences < 1 || $expected < 1) continue;

            $spikeRatio = $absences / max(1, $expected);
            if ($spikeRatio < 1.3) continue; // must be 30% above baseline

            $risk = match(true) {
                $spikeRatio >= 3.0 => 'critical',
                $spikeRatio >= 2.0 => 'high',
                $spikeRatio >= 1.5 => 'medium',
                default            => 'low',
            };

            $prediction = WorkforcePressurePrediction::updateOrCreate(
                [
                    'prediction_type' => 'absenteeism_spike',
                    'department'      => $dept->department,
                    'period_start'    => $periodStart,
                    'period_end'      => $periodEnd,
                ],
                [
                    'scope'        => 'department',
                    'predicted_for'=> $periodEnd,
                    'confidence'   => min(95, (int) ($spikeRatio * 30)),
                    'risk_level'   => $risk,
                    'risk_color'   => WorkforcePressurePrediction::colorFromRisk($risk),
                    'evidence'     => [
                        'headcount'     => $dept->headcount,
                        'absences'      => $absences,
                        'expected'      => round($expected, 1),
                        'spike_ratio'   => round($spikeRatio, 2),
                        'working_days'  => $workingDays,
                    ],
                    'status' => 'active',
                ]
            );
            $predictions[] = $prediction;
        }

        return $predictions;
    }

    public function predictTurnoverRisk(string $periodStart, string $periodEnd): array
    {
        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->select('id', 'first_name', 'surname')
            ->get();

        $predictions = [];

        foreach ($employees as $emp) {
            $records    = AttendanceRecord::where('employee_id', $emp->id)
                ->whereBetween('date', [$periodStart, $periodEnd])
                ->get();

            $workingDays  = $this->countWorkingDays($periodStart, $periodEnd);
            $presentCount = $records->where('clock_in', '!=', null)->count();
            $absentRate   = $workingDays > 0 ? ($workingDays - $presentCount) / $workingDays * 100 : 0;
            $lateCount    = $records->where('is_late', true)->count();
            $totalOt      = $records->sum('overtime_hours');
            $consecutive  = $this->maxConsecutiveDays($emp->id, $periodStart, $periodEnd);

            // Composite turnover risk score
            $score  = 0;
            $score += min(30, $absentRate * 1.5);
            $score += min(25, $lateCount * 3);
            $score += min(25, $totalOt * 1.5);
            $score += min(20, $consecutive * 3);

            if ($score < self::TURNOVER_SCORE_CUTOFF) continue;

            $risk = match(true) {
                $score >= 90 => 'critical',
                $score >= 75 => 'high',
                $score >= 65 => 'medium',
                default      => 'low',
            };

            $prediction = WorkforcePressurePrediction::updateOrCreate(
                [
                    'prediction_type' => 'turnover_risk',
                    'employee_id'     => $emp->id,
                    'period_start'    => $periodStart,
                    'period_end'      => $periodEnd,
                ],
                [
                    'scope'         => 'employee',
                    'predicted_for' => $periodEnd,
                    'confidence'    => min(95, (int) $score),
                    'risk_level'    => $risk,
                    'risk_color'    => WorkforcePressurePrediction::colorFromRisk($risk),
                    'evidence'      => [
                        'absence_rate_pct' => round($absentRate, 1),
                        'late_count'       => $lateCount,
                        'overtime_hours'   => round($totalOt, 1),
                        'consecutive_days' => $consecutive,
                        'composite_score'  => (int) $score,
                    ],
                    'status' => 'active',
                ]
            );
            $predictions[] = $prediction;
        }

        return $predictions;
    }

    public function detectUnderstaffedDepartments(string $checkDate): array
    {
        $departments = $this->departmentHeadcounts();

        $predictions = [];

        foreach ($departments as $dept) {
            $empIds = $this->employeeIdsForDepartment($dept->department);

            $presentCount = AttendanceRecord::whereIn('employee_id', $empIds)
                ->where('date', $checkDate)
                ->whereNotNull('clock_in')
                ->count();

            $presentRate = $dept->headcount > 0 ? ($presentCount / $dept->headcount) * 100 : 100;

            if ($presentRate >= 70) continue; // only flag if below 70% coverage

            $risk = match(true) {
                $presentRate < 40 => 'critical',
                $presentRate < 50 => 'high',
                $presentRate < 60 => 'medium',
                default            => 'low',
            };

            $prediction = WorkforcePressurePrediction::updateOrCreate(
                [
                    'prediction_type' => 'understaffing',
                    'department'      => $dept->department,
                    'predicted_for'   => $checkDate,
                    'period_start'    => $checkDate,
                    'period_end'      => $checkDate,
                ],
                [
                    'scope'      => 'department',
                    'confidence' => min(95, (int) ((70 - $presentRate) * 2)),
                    'risk_level' => $risk,
                    'risk_color' => WorkforcePressurePrediction::colorFromRisk($risk),
                    'evidence'   => [
                        'headcount'     => $dept->headcount,
                        'present'       => $presentCount,
                        'present_rate'  => round($presentRate, 1),
                        'check_date'    => $checkDate,
                    ],
                    'status' => 'active',
                ]
            );
            $predictions[] = $prediction;
        }

        return $predictions;
    }

    // ── AI narrative generation ───────────────────────────────────────────────

    public function generateBurnoutNarrative(BurnoutRiskProfile $profile): string
    {
        $emp = DB::table('hr_people')->where('id', $profile->employee_id)
            ->select('first_name', 'surname')->first();

        $name    = $emp ? "{$emp->first_name} {$emp->surname}" : "Employee #{$profile->employee_id}";
        $factors = implode(', ', $profile->risk_factors ?? []);
        $recs    = implode(' ', $profile->recommendations ?? []);

$request = AgentRequest::make()
    ->withSystemPrompt('You are a workforce wellness analyst. Write empathetic, professional burnout risk assessments. Be concise (3-4 sentences). Never blame the employee — focus on patterns and systemic support.')
    ->withPrompt("Write a burnout risk narrative for {$name}. Overall risk: {$profile->overall_risk}. Key risk factors: {$factors}. Recommended actions: {$recs}. Period: {$profile->period_start->toDateString()} to {$profile->period_end->toDateString()}.");

        try {
            $response = $this->agentRegistry->for('workforce_pressure')->ask($request);
            return $response->content ?? 'AI narrative unavailable.';
        } catch (\Throwable) {
            return "This employee shows {$profile->overall_risk} burnout risk based on attendance patterns. Key concerns: {$factors}.";
        }
    }

    public function generateWellnessSummary(string $periodStart, string $periodEnd): string
    {
        $stats = AttendanceWellnessScore::whereBetween('period_start', [$periodStart, $periodEnd])
            ->select('burnout_risk', DB::raw('count(*) as count'), DB::raw('avg(wellness_score) as avg_score'))
            ->groupBy('burnout_risk')
            ->get()
            ->mapWithKeys(fn($r) => [$r->burnout_risk => ['count' => $r->count, 'avg' => round($r->avg_score, 1)]]);

        $summary = json_encode($stats, JSON_PRETTY_PRINT);

        $request = AgentRequest::make()
            ->withSystemPrompt('You are a workforce health strategist. Write concise, actionable workforce wellness summaries for HR leadership. 3-5 sentences. Focus on what needs action.')
            ->withPrompt("Generate a workforce wellness summary for period {$periodStart} to {$periodEnd}. Distribution: {$summary}");

        try {
            $response = $this->agentRegistry->for('workforce_pressure')->ask($request);
            return $response->content ?? 'Summary unavailable.';
        } catch (\Throwable) {
            return "Workforce wellness scan completed for {$periodStart} to {$periodEnd}.";
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function departmentHeadcounts(): \Illuminate\Support\Collection
    {
        $fromUnits = DB::table('company_units as cu')
            ->join('company_unit_user_pivots as p', 'p.company_id', '=', 'cu.id')
            ->join('hr_people as hp', 'hp.user_id', '=', 'p.user_id')
            ->where('cu.type', 'department')
            ->where('hp.status', 1)
            ->select('cu.name as department', DB::raw('count(DISTINCT hp.id) as headcount'))
            ->groupBy('cu.name')
            ->get();

        if ($fromUnits->isNotEmpty()) return $fromUnits;

        return DB::table('hr_people')
            ->where('status', 1)
            ->whereNotNull('division_level_1')
            ->select(DB::raw('division_level_1 as department'), DB::raw('count(*) as headcount'))
            ->groupBy('division_level_1')
            ->get();
    }

    private function employeeIdsForDepartment(string $department): \Illuminate\Support\Collection
    {
        $ids = DB::table('hr_people as hp')
            ->join('company_unit_user_pivots as p', 'p.user_id', '=', 'hp.user_id')
            ->join('company_units as cu', 'cu.id', '=', 'p.company_id')
            ->where('cu.type', 'department')
            ->where('cu.name', $department)
            ->where('hp.status', 1)
            ->pluck('hp.id');

        if ($ids->isNotEmpty()) return $ids;

        return DB::table('hr_people')
            ->where('division_level_1', $department)
            ->where('status', 1)
            ->pluck('id');
    }

    private function countWorkingDays(string $start, string $end): int
    {
        $count = 0;
        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (! $day->isWeekend()) $count++;
        }
        return max(1, $count);
    }

    private function maxConsecutiveDays(int $employeeId, string $start, string $end): int
    {
        $dates = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$start, $end])
            ->whereNotNull('clock_in')
            ->orderBy('date')
            ->pluck('date');

        $max = 0;
        $current = 1;

        for ($i = 1; $i < count($dates); $i++) {
            $prev = Carbon::parse($dates[$i - 1]);
            $curr = Carbon::parse($dates[$i]);
            if ($curr->diffInDays($prev) === 1) {
                $current++;
                $max = max($max, $current);
            } else {
                $current = 1;
            }
        }

        return max($max, $current > 1 ? $current : 0);
    }

    private function calculateRestDeficit(int $employeeId, string $start, string $end): float
    {
        $clockings = AttendanceClocking::where('employee_id', $employeeId)
            ->whereBetween('clocked_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->orderBy('clocked_at')
            ->get(['type', 'clocked_at']);

        $deficit = 0;
        $lastOut = null;

        foreach ($clockings as $c) {
            if ($c->clocking_type === 'clock_out') {
                $lastOut = Carbon::parse($c->clocked_at);
            } elseif ($c->clocking_type === 'clock_in' && $lastOut) {
                $gap = $lastOut->diffInHours(Carbon::parse($c->clocked_at));
                if ($gap < self::REST_DEFICIT_HOURS) {
                    $deficit += self::REST_DEFICIT_HOURS - $gap;
                }
                $lastOut = null;
            }
        }

        return $deficit;
    }

    private function countWeekendWork(int $employeeId, string $start, string $end): int
    {
        return AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$start, $end])
            ->whereNotNull('clock_in')
            ->get('date')
            ->filter(fn($r) => Carbon::parse($r->date)->isWeekend())
            ->count();
    }
}
