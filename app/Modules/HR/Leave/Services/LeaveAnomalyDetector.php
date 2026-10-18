<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveAnomalyType;
use App\Modules\HR\Leave\Models\LeaveAnomalyFlag;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\SickLeavePattern;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveAnomalyDetector
{
    public function analyseRequest(LeaveRequest $request): void
    {
        if ($request->leaveType?->statutory_type !== 'sick') {
            return;
        }

        $this->detectMondayFridayPattern($request);
        $this->detectHolidayAbuse($request);
        $this->detectExcessiveSick($request);
    }

    public function analyseEmployeeRisk(int $hrPersonId): array
    {
        $flags = LeaveAnomalyFlag::where('employee_id', $hrPersonId)
            ->open()
            ->get();

        $burnoutRisk = $this->calculateBurnoutRisk($hrPersonId);

        return [
            'open_flags'   => $flags->count(),
            'burnout_risk' => $burnoutRisk,
            'flags'        => $flags->map(fn($f) => [
                'type'     => $f->anomaly_type->label(),
                'severity' => $f->severity,
                'detected' => $f->detected_at?->format('Y-m-d'),
            ]),
        ];
    }

    public function detectNegativeBalance(int $hrPersonId, string $leaveTypeName, float $balance): void
    {
        if ($balance >= 0) {
            return;
        }

        $alreadyFlagged = LeaveAnomalyFlag::where('employee_id', $hrPersonId)
            ->where('anomaly_type', LeaveAnomalyType::NegativeBalance->value)
            ->open()
            ->exists();

        if ($alreadyFlagged) {
            return;
        }

        $this->flag($hrPersonId, 0, LeaveAnomalyType::NegativeBalance, 'high', [
            'description' => "Employee has a negative leave balance of " . round(abs($balance) / 8, 1) . " days on {$leaveTypeName}.",
            'evidence'    => ['leave_type' => $leaveTypeName, 'balance_hours' => $balance],
        ]);
    }

    public function flagBurnoutRisk(int $hrPersonId): void
    {
        $risk = $this->calculateBurnoutRisk($hrPersonId);

        if (! in_array($risk, ['high', 'critical'])) {
            return;
        }

        $alreadyFlagged = LeaveAnomalyFlag::where('employee_id', $hrPersonId)
            ->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)
            ->open()
            ->where('detected_at', '>=', now()->subDays(30))
            ->exists();

        if ($alreadyFlagged) {
            return;
        }

        $totalHours = (float) DB::table('leave_requests')
            ->where('employee_id', $hrPersonId)
            ->whereIn('status', ['approved'])
            ->where('start_date', '>=', now()->subMonths(6)->format('Y-m-d'))
            ->sum('working_hours');

        $this->flag($hrPersonId, 0, LeaveAnomalyType::BurnoutRisk, $risk === 'critical' ? 'critical' : 'high', [
            'description' => "Burnout risk level: {$risk}. Employee has used " . round($totalHours / 8) . " leave days in the last 6 months.",
            'evidence'    => ['burnout_risk' => $risk, 'leave_hours_6m' => $totalHours],
        ]);
    }

    public function detectUnderstaffingRisk(int $divisionLevel1, string $startDate, string $endDate): array
    {
        $totalInDivision = DB::table('hr_people')
            ->where('division_level_1', $divisionLevel1)
            ->where('status', 1)
            ->count();

        $onLeave = DB::table('leave_requests')
            ->join('hr_people', 'hr_people.id', '=', 'leave_requests.employee_id')
            ->where('hr_people.division_level_1', $divisionLevel1)
            ->whereIn('leave_requests.status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('leave_requests.start_date', '<=', $endDate)
            ->where('leave_requests.end_date', '>=', $startDate)
            ->distinct('leave_requests.employee_id')
            ->count('leave_requests.employee_id');

        $coveragePercent = $totalInDivision > 0
            ? round((($totalInDivision - $onLeave) / $totalInDivision) * 100)
            : 100;

        $riskLevel = match(true) {
            $coveragePercent < 50 => 'critical',
            $coveragePercent < 65 => 'high',
            $coveragePercent < 80 => 'medium',
            default               => 'low',
        };

        if (in_array($riskLevel, ['critical', 'high'])) {
            $this->flag(0, 0, LeaveAnomalyType::UnderstaffingRisk, $riskLevel, [
                'description' => "Division has {$coveragePercent}% coverage between {$startDate} and {$endDate}. {$onLeave} of {$totalInDivision} employees absent.",
                'evidence'    => [
                    'division_id'     => $divisionLevel1,
                    'coverage_pct'    => $coveragePercent,
                    'on_leave'        => $onLeave,
                    'total_headcount' => $totalInDivision,
                ],
            ]);
        }

        return [
            'division_id'     => $divisionLevel1,
            'total_headcount' => $totalInDivision,
            'on_leave'        => $onLeave,
            'coverage_pct'    => $coveragePercent,
            'risk_level'      => $riskLevel,
            'period'          => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    public function generateWorkforceRiskSummary(int $divisionLevel1): array
    {
        $employees = DB::table('hr_people')
            ->where('division_level_1', $divisionLevel1)
            ->where('status', 1)
            ->pluck('id');

        $openFlags = LeaveAnomalyFlag::whereIn('employee_id', $employees)
            ->open()
            ->selectRaw('anomaly_type, severity, count(*) as count')
            ->groupBy('anomaly_type', 'severity')
            ->get()
            ->map(fn($f) => [
                'type'     => $f->anomaly_type,
                'severity' => $f->severity,
                'count'    => $f->count,
            ]);

        $burnoutCount = $employees->filter(fn($id) =>
            in_array($this->calculateBurnoutRisk((int) $id), ['high', 'critical'])
        )->count();

        return [
            'division_id'   => $divisionLevel1,
            'headcount'     => $employees->count(),
            'open_flags'    => $openFlags,
            'burnout_count' => $burnoutCount,
        ];
    }

    private function detectMondayFridayPattern(LeaveRequest $request): void
    {
        $start = Carbon::parse($request->start_date);
        $end   = Carbon::parse($request->end_date);

        $isMon = $start->isMonday();
        $isFri = $end->isFriday();

        if (! $isMon && ! $isFri) {
            return;
        }

        $count = DB::table('leave_requests')
            ->where('employee_id', $request->employee_id)
            ->whereIn('status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('start_date', '>=', now()->subDays(90)->format('Y-m-d'))
            ->where('id', '!=', $request->id)
            ->count();

        if ($count >= 2) {
            $this->flag($request->employee_id, $request->id, LeaveAnomalyType::MondayFridayAbuse, 'medium', [
                'description' => "Employee has taken {$count} Monday/Friday sick leaves in the last 90 days.",
                'evidence'    => ['occurrences_90d' => $count, 'current_day' => $isMon ? 'Monday' : 'Friday'],
            ]);

            SickLeavePattern::create([
                'employee_id'      => $request->employee_id,
                'leave_request_id' => $request->id,
                'pattern_type'     => $isMon ? 'monday_absence' : 'friday_absence',
                'detection_date'   => today(),
                'occurrence_count' => $count + 1,
            ]);
        }
    }

    private function detectHolidayAbuse(LeaveRequest $request): void
    {
        $startDate = Carbon::parse($request->start_date);
        $prevDay   = $startDate->copy()->subDay();
        $nextDay   = Carbon::parse($request->end_date)->addDay();

        $isPreHoliday  = DB::table('public_holidays')->whereDate('date', $nextDay)->exists();
        $isPostHoliday = DB::table('public_holidays')->whereDate('date', $prevDay)->exists();

        if ($isPreHoliday || $isPostHoliday) {
            $this->flag($request->employee_id, $request->id, LeaveAnomalyType::HolidayAbuse, 'medium', [
                'description' => 'Sick leave taken immediately before or after a public holiday.',
                'evidence'    => ['adjacent_holiday' => $isPreHoliday ? $nextDay->format('Y-m-d') : $prevDay->format('Y-m-d')],
            ]);
        }
    }

    private function detectExcessiveSick(LeaveRequest $request): void
    {
        $totalHours = DB::table('leave_requests')
            ->where('employee_id', $request->employee_id)
            ->whereIn('status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('start_date', '>=', now()->subYear()->format('Y-m-d'))
            ->sum('working_hours');

        if ($totalHours >= 240) {
            $this->flag($request->employee_id, $request->id, LeaveAnomalyType::ExcessiveSick, 'high', [
                'description' => "Employee has accumulated " . round($totalHours / 8) . " sick days this year — potential burnout or attendance concern.",
                'evidence'    => ['total_sick_hours_ytd' => $totalHours],
            ]);
        }
    }

    public function calculateBurnoutRisk(int $hrPersonId): string
    {
        $totalHours = DB::table('leave_requests')
            ->where('employee_id', $hrPersonId)
            ->whereIn('status', ['approved'])
            ->where('start_date', '>=', now()->subMonths(6)->format('Y-m-d'))
            ->sum('working_hours');

        return match(true) {
            $totalHours >= 320 => 'critical',
            $totalHours >= 200 => 'high',
            $totalHours >= 120 => 'medium',
            default            => 'low',
        };
    }

    private function flag(int $hrPersonId, int $requestId, LeaveAnomalyType $type, string $severity, array $data): void
    {
        LeaveAnomalyFlag::create([
            'employee_id'      => $hrPersonId ?: null,
            'leave_request_id' => $requestId  ?: null,
            'anomaly_type'     => $type->value,
            'severity'         => $severity,
            'detection_method' => 'rule_based',
            'description'      => $data['description'],
            'evidence'         => $data['evidence'],
            'status'           => 'open',
            'detected_at'      => now(),
        ]);
    }
}
