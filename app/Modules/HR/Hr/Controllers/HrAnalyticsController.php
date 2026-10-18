<?php

namespace App\Modules\HR\Hr\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HrAnalyticsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('HR/Analytics/Dashboard', [
            'headcount'        => $this->headcountByDepartment(),
            'statusBreakdown'  => $this->employeeStatusBreakdown(),
            'payrollTrend'     => $this->payrollMonthlyTrend(),
            'leaveStats'       => $this->leaveStats(),
            'attendanceStats'  => $this->attendanceStats(),
            'recruitmentStats' => $this->recruitmentStats(),
        ]);
    }

    private function headcountByDepartment(): array
    {
        // Active employees per department via company_unit_user_pivots
        return DB::table('company_unit_user_pivots as cup')
            ->join('company_units as cu', 'cu.id', '=', 'cup.company_id')
            ->join('hr_people as hp', 'hp.user_id', '=', 'cup.user_id')
            ->where('cu.type', 'department')
            ->whereIn('hp.status', ['pre_boarding', 'onboarding', 'active'])
            ->select('cu.name as department', DB::raw('COUNT(DISTINCT cup.user_id) as headcount'))
            ->groupBy('cu.id', 'cu.name')
            ->orderByDesc('headcount')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['department' => $r->department, 'headcount' => (int) $r->headcount])
            ->toArray();
    }

    private function employeeStatusBreakdown(): array
    {
        return DB::table('hr_people')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count])
            ->toArray();
    }

    private function payrollMonthlyTrend(): array
    {
        // Last 6 released payroll runs grouped by month
        return DB::table('payroll_runs')
            ->where('status', 'released')
            ->where('period_start', '>=', now()->subMonths(6)->startOfMonth())
            ->select(
                DB::raw("TO_CHAR(period_start, 'Mon YYYY') as month"),
                DB::raw("DATE_TRUNC('month', period_start) as sort_key"),
                DB::raw('COALESCE(SUM(total_gross), 0) as total_gross'),
                DB::raw('COALESCE(SUM(total_net), 0) as total_net'),
                DB::raw('SUM(employee_count) as employee_count')
            )
            ->groupBy('month', 'sort_key')
            ->orderBy('sort_key')
            ->get()
            ->map(fn ($r) => [
                'month'          => $r->month,
                'total_gross'    => (float) $r->total_gross,
                'total_net'      => (float) $r->total_net,
                'employee_count' => (int) $r->employee_count,
            ])
            ->toArray();
    }

    private function leaveStats(): array
    {
        // Leave requests this calendar year — uses leave_requests (the active module table)
        // Duration column is `working_days`; status values are enum strings (approved/pending/rejected/cancelled etc.)
        $year  = now()->year;
        $stats = DB::table('leave_requests')
            ->whereNull('deleted_at')
            ->whereYear('start_date', $year)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(working_days), 0) as total_days'))
            ->groupBy('status')
            ->get();

        return [
            'total_applications' => (int) $stats->sum('count'),
            'total_days'         => (float) $stats->sum('total_days'),
            'approved'           => (int) ($stats->firstWhere('status', 'approved')?->count ?? 0),
            'pending'            => (int) $stats->filter(fn ($r) => in_array($r->status, [
                'pending', 'pending_manager', 'pending_department', 'pending_hr', 'pending_payroll', 'escalated',
            ]))->sum('count'),
            'rejected'           => (int) ($stats->firstWhere('status', 'rejected')?->count ?? 0),
        ];
    }

    private function attendanceStats(): array
    {
        // From AttendanceProductivityScore — current month
        $from   = now()->startOfMonth();
        $to     = now()->endOfMonth();
        $scores = DB::table('attendance_productivity_scores')
            ->whereBetween('period_start', [$from, $to])
            ->select(
                DB::raw('ROUND(AVG(overall_score)::numeric, 1) as avg_score'),
                DB::raw('ROUND(AVG(attendance_rate)::numeric, 1) as avg_attendance_rate'),
                DB::raw("COUNT(CASE WHEN productivity_tier = 'high' THEN 1 END) as high_performers"),
                DB::raw("COUNT(CASE WHEN productivity_tier IN ('low','at_risk') THEN 1 END) as at_risk"),
                DB::raw('COUNT(*) as total_scored')
            )
            ->first();

        return [
            'avg_score'           => (float) ($scores->avg_score ?? 0),
            'avg_attendance_rate' => (float) ($scores->avg_attendance_rate ?? 0),
            'high_performers'     => (int) ($scores->high_performers ?? 0),
            'at_risk'             => (int) ($scores->at_risk ?? 0),
            'total_scored'        => (int) ($scores->total_scored ?? 0),
        ];
    }

    private function recruitmentStats(): array
    {
        return [
            'open_jobs'           => DB::table('recruitment_jobs')->where('is_active', true)->count(),
            'applications'        => DB::table('recruitment_applications')
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'shortlists'          => DB::table('recruitment_shortlists')->count(),
            'active_requisitions' => DB::table('recruitment_requisitions')
                ->whereNull('deleted_at')
                ->whereIn('status', ['pending', 'approved'])
                ->count(),
        ];
    }
}
