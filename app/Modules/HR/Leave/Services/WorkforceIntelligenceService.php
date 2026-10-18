<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Leave\AI\LeaveAdvisorAI;
use Illuminate\Support\Facades\DB;

class WorkforceIntelligenceService
{
    public function __construct(
        private readonly LeaveAdvisorAI       $ai,
        private readonly LeaveAnomalyDetector $detector,
    ) {}

    /**
     * Predict staff shortage risk for a division over the next N days.
     */
    public function predictStaffShortage(int $divisionLevel1, int $days = 30): array
    {
        $startDate = now()->format('Y-m-d');
        $endDate   = now()->addDays($days)->format('Y-m-d');

        $coverage = $this->detector->detectUnderstaffingRisk($divisionLevel1, $startDate, $endDate);

        $upcomingLeave = DB::table('leave_requests')
            ->join('hr_people', 'hr_people.id', '=', 'leave_requests.employee_id')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->where('hr_people.division_level_1', $divisionLevel1)
            ->whereIn('leave_requests.status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('leave_requests.start_date', '<=', $endDate)
            ->where('leave_requests.end_date', '>=', $startDate)
            ->select('leave_requests.start_date', 'leave_requests.end_date', 'leave_types.name as leave_type', 'leave_requests.working_days')
            ->orderBy('leave_requests.start_date')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        $aiInsight = $this->ai->interpretWorkforceData(
            hrPersonId: 0,
            insightType: 'staff shortage prediction',
            data: array_merge($coverage, ['upcoming_leave' => $upcomingLeave, 'forecast_days' => $days]),
        );

        return [
            'coverage'       => $coverage,
            'upcoming_leave' => $upcomingLeave,
            'ai_insight'     => $aiInsight,
        ];
    }

    /**
     * Identify leave clashes — multiple employees in the same division absent on the same dates.
     */
    public function predictLeaveClash(int $divisionLevel1, string $startDate, string $endDate): array
    {
        $clashData = DB::table('leave_requests as lr')
            ->join('hr_people as hp', 'hp.id', '=', 'lr.employee_id')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->where('hp.division_level_1', $divisionLevel1)
            ->whereIn('lr.status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('lr.start_date', '<=', $endDate)
            ->where('lr.end_date', '>=', $startDate)
            ->selectRaw("lr.start_date, lr.end_date, lt.name as leave_type, count(*) as concurrent_count")
            ->groupByRaw("lr.start_date, lr.end_date, lt.name")
            ->havingRaw('count(*) > 1')
            ->orderByRaw('count(*) desc')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        $totalHeadcount = DB::table('hr_people')
            ->where('division_level_1', $divisionLevel1)
            ->where('status', 1)
            ->count();

        $aiInsight = $this->ai->interpretWorkforceData(
            hrPersonId: 0,
            insightType: 'leave clash analysis',
            data: [
                'period'          => ['start' => $startDate, 'end' => $endDate],
                'total_headcount' => $totalHeadcount,
                'clashes'         => $clashData,
            ],
        );

        return [
            'clashes'    => $clashData,
            'ai_insight' => $aiInsight,
        ];
    }

    /**
     * Analyse current leave pressure across a division.
     */
    public function analyseDepartmentPressure(int $divisionLevel1): array
    {
        $today = now()->format('Y-m-d');

        $currentlyAbsent = DB::table('leave_requests')
            ->join('hr_people', 'hr_people.id', '=', 'leave_requests.employee_id')
            ->where('hr_people.division_level_1', $divisionLevel1)
            ->where('leave_requests.status', 'approved')
            ->where('leave_requests.start_date', '<=', $today)
            ->where('leave_requests.end_date', '>=', $today)
            ->count();

        $pendingApprovals = DB::table('leave_requests')
            ->join('hr_people', 'hr_people.id', '=', 'leave_requests.employee_id')
            ->where('hr_people.division_level_1', $divisionLevel1)
            ->whereIn('leave_requests.status', ['pending_manager', 'pending_hr'])
            ->count();

        $riskSummary = $this->detector->generateWorkforceRiskSummary($divisionLevel1);

        $totalHeadcount = DB::table('hr_people')
            ->where('division_level_1', $divisionLevel1)
            ->where('status', 1)
            ->count();

        $pressureData = [
            'division_id'       => $divisionLevel1,
            'total_headcount'   => $totalHeadcount,
            'currently_absent'  => $currentlyAbsent,
            'pending_approvals' => $pendingApprovals,
            'coverage_today_pct' => $totalHeadcount > 0
                ? round((($totalHeadcount - $currentlyAbsent) / $totalHeadcount) * 100)
                : 100,
            'open_anomaly_flags' => $riskSummary['open_flags'],
            'burnout_risk_count' => $riskSummary['burnout_count'],
        ];

        $aiInsight = $this->ai->interpretWorkforceData(
            hrPersonId: 0,
            insightType: 'department pressure analysis',
            data: $pressureData,
        );

        return array_merge($pressureData, ['ai_insight' => $aiInsight]);
    }

    /**
     * Forecast total leave liability (positive balances × notional daily cost).
     * Returns per-leave-type breakdown and AI narrative.
     */
    public function forecastLeaveLiability(int $divisionLevel1 = 0): array
    {
        $query = DB::table('leave_balance_transactions as lbt')
            ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
            ->join('hr_people as hp', 'hp.id', '=', 'lbt.hr_person_id')
            ->whereIn('lbt.id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('leave_balance_transactions')
                    ->groupBy('hr_person_id', 'leave_type_id');
            })
            ->where('lbt.balance_after', '>', 0);

        if ($divisionLevel1 > 0) {
            $query->where('hp.division_level_1', $divisionLevel1);
        }

        $balances = $query
            ->selectRaw('lt.name as leave_type, count(*) as employee_count, SUM(lbt.balance_after) as total_hours')
            ->groupBy('lt.name')
            ->get()
            ->map(fn($r) => [
                'leave_type'     => $r->leave_type,
                'employee_count' => $r->employee_count,
                'total_hours'    => round((float) $r->total_hours, 1),
                'total_days'     => round((float) $r->total_hours / 8, 1),
            ])
            ->toArray();

        $totalDays = collect($balances)->sum('total_days');

        $aiInsight = $this->ai->interpretWorkforceData(
            hrPersonId: 0,
            insightType: 'leave liability forecast',
            data: [
                'division_id'      => $divisionLevel1 ?: 'all',
                'balances_by_type' => $balances,
                'total_days_owed'  => $totalDays,
            ],
        );

        return [
            'by_type'     => $balances,
            'total_days'  => $totalDays,
            'ai_insight'  => $aiInsight,
        ];
    }

    /**
     * Predict individual burnout risk with an AI narrative.
     */
    public function predictBurnout(int $hrPersonId): array
    {
        $risk = $this->detector->calculateBurnoutRisk($hrPersonId);

        $leaveHistory = DB::table('leave_requests')
            ->where('employee_id', $hrPersonId)
            ->whereIn('status', ['approved'])
            ->where('start_date', '>=', now()->subMonths(6)->format('Y-m-d'))
            ->selectRaw('count(*) as request_count, SUM(working_hours) as total_hours, SUM(working_days) as total_days')
            ->first();

        $openFlags = DB::table('leave_anomaly_flags')
            ->where('employee_id', $hrPersonId)
            ->where('status', 'open')
            ->count();

        $data = [
            'burnout_risk'  => $risk,
            'leave_6m'      => [
                'requests'   => (int) ($leaveHistory->request_count ?? 0),
                'total_days' => (float) ($leaveHistory->total_days ?? 0),
                'total_hours' => (float) ($leaveHistory->total_hours ?? 0),
            ],
            'open_flags' => $openFlags,
        ];

        $aiInsight = $this->ai->interpretWorkforceData(
            hrPersonId: $hrPersonId,
            insightType: 'individual burnout prediction',
            data: $data,
            question: 'Is this employee at risk of burnout? What should HR do?',
        );

        return array_merge($data, ['ai_insight' => $aiInsight]);
    }
}
