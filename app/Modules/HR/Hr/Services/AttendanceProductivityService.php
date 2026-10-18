<?php

namespace App\Modules\HR\Hr\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAuditLog;
use App\Modules\HR\Hr\Models\Attendance\AttendanceProductivityScore;
use App\Modules\HR\Hr\Models\Attendance\AttendanceProductivityTrend;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\DepartmentEfficiencyScore;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceProductivityService
{

public function __construct(private AgentRegistry $agentRegistry) {}
    public function analyzeAttendanceProductivity(Carbon $from, Carbon $to): Collection
    {
        $employees = HRPerson::where('status', 1)->get();
        $results   = collect();

        foreach ($employees as $emp) {
            $score = $this->scoreEmployee($emp, $from, $to);
            $results->push($score);
        }

        $this->buildCompanyTrend($from, $to, $results);
        AttendanceAuditLog::record('productivity_scan', notes: "Analyzed {$results->count()} employees for {$from->toDateString()} – {$to->toDateString()}");

        return $results;
    }

    public function trackDepartmentEfficiency(Carbon $from, Carbon $to): Collection
    {
        $depts = DB::table('company_units')
            ->where('type', 'department')
            ->where('active', true)
            ->pluck('name');

        if ($depts->isEmpty()) {
            $depts = DB::table('hr_people')
                ->where('status', 1)
                ->whereNotNull('division_level_1')
                ->distinct()
                ->pluck('division_level_1');
        }

        $results = collect();

        foreach ($depts as $dept) {
            $empIds = DB::table('hr_people as hp')
                ->join('company_unit_user_pivots as p', 'p.user_id', '=', 'hp.user_id')
                ->join('company_units as cu', 'cu.id', '=', 'p.company_id')
                ->where('cu.type', 'department')
                ->where('cu.name', $dept)
                ->where('hp.status', 1)
                ->pluck('hp.id');

         if ($empIds->isEmpty()) {
    $empIds = DB::table('hr_people')
        ->where('division_level_1', function ($q) use ($dept) {
            $q->select('id')
              ->from('company_units')
              ->where('name', $dept)
              ->where('type', 'department')
              ->limit(1);
        })
        ->where('status', 1)
        ->pluck('id');
}

            $deptEmployees = HRPerson::whereIn('id', $empIds)->get();
            if ($deptEmployees->isEmpty()) continue;

            $scores = $deptEmployees->map(fn($e) => $this->scoreEmployee($e, $from, $to));

            $avgAttendance  = $scores->avg('attendance_rate');
            $avgPunctuality = $scores->avg('punctuality_score');
            $totalOt        = $scores->sum('overtime_hours');
            $overworkedCnt  = $scores->where('overtime_hours', '>', 20)->count();
            $lowProdCnt     = $scores->whereIn('productivity_tier', ['low', 'at_risk'])->count();
            $effScore       = (int) (($avgAttendance / 100 * 50) + ($avgPunctuality / 100 * 50));

            $record = DepartmentEfficiencyScore::updateOrCreate(
                ['department' => $dept, 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString()],
                [
                    'efficiency_score'       => $effScore,
                    'headcount'              => $deptEmployees->count(),
                    'avg_attendance_rate'    => round($avgAttendance, 2),
                    'avg_punctuality_rate'   => round($avgPunctuality, 2),
                    'total_overtime_hours'   => round($totalOt, 2),
                    'overworked_count'       => $overworkedCnt,
                    'low_productivity_count' => $lowProdCnt,
                    'efficiency_tier'        => DepartmentEfficiencyScore::tierFromScore($effScore),
                    'is_overworked_dept'     => $overworkedCnt > ($deptEmployees->count() * 0.3),
                    'computed_at'            => now(),
                ]
            );
            $results->push($record);
        }

        return $results;
    }

    public function detectLowProductivityPatterns(Carbon $from, Carbon $to): Collection
    {
        return AttendanceProductivityScore::lowPerformers()
            ->whereBetween('period_start', [$from, $to])
            ->orderBy('overall_score')
            ->get();
    }

    public function detectOverworkedDepartments(Carbon $from, Carbon $to): Collection
    {
        return DepartmentEfficiencyScore::overworked()
            ->whereBetween('period_start', [$from, $to])
            ->orderByDesc('total_overtime_hours')
            ->get();
    }

  public function generateAttendanceInsights(string $periodLabel, array $stats)
{
    $prompt = "You are an HR analytics AI. Generate a concise workforce productivity insights report.\n\n"
        . "Period: {$periodLabel}\n"
        . "Company Attendance Rate: {$stats['avg_attendance_rate']}%\n"
        . "Average Productivity Score: {$stats['avg_score']}/100\n"
        . "Low Performers: {$stats['low_performers']}\n"
        . "High Performers: {$stats['high_performers']}\n"
        . "Overworked Departments: {$stats['overworked_depts']}\n\n"
        . "Provide 3-5 bullet-point insights and 2 concrete recommendations. Keep it under 200 words.";

    return $this->agentRegistry->for('hr_analytics')
        ->ask(AgentRequest::make()
            ->withSystemPrompt('You are an HR workforce analytics assistant.')
            ->withPrompt($prompt));
}

    private function scoreEmployee(HRPerson $emp, Carbon $from, Carbon $to): AttendanceProductivityScore
    {
        $workDays = $this->countWorkingDays($from, $to);
        $records  = AttendanceRecord::where('employee_id', $emp->id)
            ->whereBetween('date', [$from, $to])
            ->get();

        $present         = $records->whereNotIn('status', ['absent'])->count();
        $absent          = max(0, $workDays - $present);
        $lateCount       = $records->where('is_late', true)->count();
        $earlyCount      = $records->where('early_departure', true)->count();
        $overtimeHours   = $records->sum('overtime_hours');
        $attendanceRate  = $workDays > 0 ? round($present / $workDays * 100, 2) : 0;

        $punctualityScore     = max(0, 25 - $lateCount * 3);
        $attendanceRateScore  = (int) ($attendanceRate / 4);
        $overtimeBalScore     = $overtimeHours > 30 ? max(0, 25 - (int)(($overtimeHours - 30) / 2)) : 25;
        $consistencyScore     = max(0, 25 - $earlyCount * 3);
        $overall              = $punctualityScore + $attendanceRateScore + $overtimeBalScore + $consistencyScore;

        return AttendanceProductivityScore::updateOrCreate(
            ['employee_id' => $emp->id, 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString()],
            [
                'overall_score'            => $overall,
                'punctuality_score'        => $punctualityScore,
                'attendance_rate_score'    => $attendanceRateScore,
                'overtime_balance_score'   => $overtimeBalScore,
                'consistency_score'        => $consistencyScore,
                'attendance_rate'          => $attendanceRate,
                'days_present'             => $present,
                'days_absent'              => $absent,
                'late_arrivals'            => $lateCount,
                'early_departures'         => $earlyCount,
                'overtime_hours'           => $overtimeHours,
                'productivity_tier'        => AttendanceProductivityScore::tierFromScore($overall),
                'computed_at'              => now(),
            ]
        );
    }

    private function buildCompanyTrend(Carbon $from, Carbon $to, Collection $scores): void
    {
        $avgScore      = $scores->avg('overall_score');
        $avgAttendance = $scores->avg('attendance_rate');

        $prev = AttendanceProductivityTrend::where('scope', 'company')
            ->where('metric', 'productivity_score')
            ->orderByDesc('period_date')
            ->first();

        $changePct   = $prev ? round(($avgScore - $prev->value) / max(1, $prev->value) * 100, 2) : null;
        $direction   = $changePct === null ? null : ($changePct > 1 ? 'up' : ($changePct < -1 ? 'down' : 'stable'));

        AttendanceProductivityTrend::create([
            'scope'           => 'company',
            'scope_key'       => null,
            'metric'          => 'productivity_score',
            'period_date'     => $from->toDateString(),
            'period_type'     => 'monthly',
            'value'           => round($avgScore, 2),
            'prev_value'      => $prev?->value,
            'change_pct'      => $changePct,
            'trend_direction' => $direction,
        ]);

        AttendanceProductivityTrend::create([
            'scope'       => 'company',
            'metric'      => 'attendance_rate',
            'period_date' => $from->toDateString(),
            'period_type' => 'monthly',
            'value'       => round($avgAttendance, 2),
        ]);
    }

    private function countWorkingDays(Carbon $from, Carbon $to): int
    {
        $count = 0;
        $day   = $from->copy();
        while ($day->lte($to)) {
            if ($day->isWeekday()) $count++;
            $day->addDay();
        }
        return $count;
    }
}
