<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BceaComplianceController extends Controller
{
    public function index(Request $request): Response
    {
        $weeks = max(4, min(52, (int) $request->integer('weeks', 12)));

        // Weekly hours violations: ordinary > 45h or overtime > 10h
        $weeklyViolations = DB::table('attendance_records as ar')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'ar.employee_id')
            ->where('ar.date', '>=', now()->subWeeks($weeks))
            ->select([
                'ar.employee_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(p.first_name,' ',p.surname)),''), CAST(ar.employee_id AS TEXT)) AS employee_name"),
                DB::raw("DATE_TRUNC('week', ar.date::timestamp)::date AS week_start"),
                DB::raw('ROUND(SUM(ar.hours_worked)::numeric, 2) AS total_hours'),
                DB::raw('ROUND(SUM(ar.overtime_hours)::numeric, 2) AS total_overtime'),
            ])
            ->groupBy('ar.employee_id', 'week_start', 'p.first_name', 'p.surname')
            ->havingRaw('SUM(ar.hours_worked) > 45 OR SUM(ar.overtime_hours) > 10')
            ->orderByDesc('week_start')
            ->orderByDesc('total_hours')
            ->limit(200)
            ->get()
            ->map(fn($r) => [
                'employee_id'    => $r->employee_id,
                'employee_name'  => $r->employee_name,
                'week_start'     => $r->week_start,
                'total_hours'    => (float) $r->total_hours,
                'total_overtime' => (float) $r->total_overtime,
                'violation_type' => $r->total_overtime > 10 ? 'overtime_exceeded' : 'ordinary_exceeded',
            ]);

        // Rest period violations: < 12h between clock_out and next day's clock_in
        $restViolations = DB::table('attendance_records as a1')
            ->join('attendance_records as a2', function ($join) {
                $join->on('a1.employee_id', '=', 'a2.employee_id')
                    ->whereRaw("a2.date = a1.date + INTERVAL '1 day'");
            })
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'a1.employee_id')
            ->whereNotNull('a1.clock_out')
            ->whereNotNull('a2.clock_in')
            ->where('a1.date', '>=', now()->subWeeks($weeks))
            ->whereRaw("EXTRACT(EPOCH FROM (a2.clock_in - a1.clock_out))/3600 < 12")
            ->select([
                'a1.employee_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(p.first_name,' ',p.surname)),''), CAST(a1.employee_id AS TEXT)) AS employee_name"),
                'a1.date AS first_day',
                'a1.clock_out AS first_clock_out',
                'a2.date AS second_day',
                'a2.clock_in AS second_clock_in',
                DB::raw("ROUND((EXTRACT(EPOCH FROM (a2.clock_in - a1.clock_out))/3600)::numeric, 2) AS rest_hours"),
            ])
            ->orderByDesc('a1.date')
            ->limit(200)
            ->get()
            ->map(fn($r) => [
                'employee_id'      => $r->employee_id,
                'employee_name'    => $r->employee_name,
                'first_day'        => $r->first_day,
                'first_clock_out'  => $r->first_clock_out,
                'second_day'       => $r->second_day,
                'second_clock_in'  => $r->second_clock_in,
                'rest_hours'       => (float) $r->rest_hours,
            ]);

        $stats = [
            'weekly_violations'   => $weeklyViolations->count(),
            'rest_violations'     => $restViolations->count(),
            'affected_employees'  => $weeklyViolations->pluck('employee_id')
                ->merge($restViolations->pluck('employee_id'))
                ->unique()
                ->count(),
            'weeks_analysed'      => $weeks,
        ];

        return Inertia::render('HR/Attendance/BCEA/Index', [
            'weeklyViolations' => $weeklyViolations->values(),
            'restViolations'   => $restViolations->values(),
            'stats'            => $stats,
            'weeks'            => $weeks,
        ]);
    }
}
