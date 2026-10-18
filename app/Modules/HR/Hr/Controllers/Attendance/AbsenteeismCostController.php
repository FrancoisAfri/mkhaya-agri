<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AbsenteeismCostController extends Controller
{
    // Assumed working hours per day when no payslip period data is available.
    private const HOURS_PER_DAY = 8;

    public function index(Request $request): Response
    {
        $months = max(1, min(12, (int) $request->integer('months', 3)));
        $from   = now()->startOfMonth()->subMonths($months - 1);

        // Per-employee: absent days in window, daily rate from their most recent payslip.
        $rows = DB::table('attendance_absent_users as aau')
            ->leftJoin('hr_people as p', 'p.id', '=', 'aau.employee_id')
            // Most recent payslip basic_salary
            ->leftJoinSub(
                DB::table('payslips')
                    ->select('employee_id', DB::raw('MAX(id) as latest_id'))
                    ->groupBy('employee_id'),
                'latest',
                'latest.employee_id', '=', 'aau.employee_id'
            )
            ->leftJoin('payslips as ps', 'ps.id', '=', 'latest.latest_id')
            // Department via company_unit_user_pivots
            ->leftJoinSub(
                DB::table('company_unit_user_pivots as cup')
                    ->join('company_units as cu', function ($j) {
                        $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department');
                    })
                    ->select('cup.user_id', 'cu.name as department'),
                'dept',
                'dept.user_id', '=', 'p.user_id'
            )
            ->where('aau.date', '>=', $from)
            ->select([
                'aau.employee_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(p.first_name,' ',p.surname)),''), CAST(aau.employee_id AS TEXT)) AS employee_name"),
                DB::raw("COALESCE(dept.department, 'Unassigned') AS department"),
                DB::raw('COUNT(*) AS absent_days'),
                DB::raw('COALESCE(ps.basic_salary, 0) AS basic_salary'),
                // Daily rate = monthly salary / 22 working days
                DB::raw('ROUND((COALESCE(ps.basic_salary, 0) / 22.0)::numeric, 2) AS daily_rate'),
                DB::raw('ROUND((COALESCE(ps.basic_salary, 0) / 22.0 * COUNT(*))::numeric, 2) AS absent_cost'),
            ])
            ->groupBy('aau.employee_id', 'p.first_name', 'p.surname', 'dept.department', 'ps.basic_salary')
            ->having(DB::raw('COALESCE(ps.basic_salary, 0)'), '>', 0)
            ->orderByDesc('absent_cost')
            ->get()
            ->map(fn ($r) => [
                'employee_id'   => $r->employee_id,
                'employee_name' => $r->employee_name,
                'department'    => $r->department,
                'absent_days'   => (int)   $r->absent_days,
                'basic_salary'  => (float) $r->basic_salary,
                'daily_rate'    => (float) $r->daily_rate,
                'absent_cost'   => (float) $r->absent_cost,
            ]);

        // Department rollup
        $byDepartment = $rows
            ->groupBy('department')
            ->map(fn ($group, $dept) => [
                'department'  => $dept,
                'absent_days' => $group->sum('absent_days'),
                'absent_cost' => round($group->sum('absent_cost'), 2),
                'headcount'   => $group->count(),
            ])
            ->values()
            ->sortByDesc('absent_cost')
            ->values();

        $summary = [
            'total_absent_days'    => $rows->sum('absent_days'),
            'total_absent_cost'    => round($rows->sum('absent_cost'), 2),
            'annualised_cost'      => round($rows->sum('absent_cost') / $months * 12, 2),
            'affected_employees'   => $rows->count(),
            'avg_cost_per_absence' => $rows->count()
                ? round($rows->sum('absent_cost') / $rows->sum('absent_days'), 2)
                : 0,
            'months_analysed'      => $months,
            'from'                 => $from->toDateString(),
        ];

        return Inertia::render('HR/Attendance/Analytics/AbsenteeismCost', [
            'rows'         => $rows->values(),
            'byDepartment' => $byDepartment,
            'summary'      => $summary,
            'months'       => $months,
        ]);
    }
}
