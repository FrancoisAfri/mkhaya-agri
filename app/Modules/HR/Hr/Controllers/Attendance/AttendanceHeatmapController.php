<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceHeatmapController extends Controller
{
    public function index(Request $request): Response
    {
        $days = max(30, min(365, (int) $request->integer('days', 90)));

        $rows = DB::table('attendance_records')
            ->whereNotNull('clock_in')
            ->where('date', '>=', now()->subDays($days))
            ->select([
                DB::raw('EXTRACT(DOW FROM date)::int AS day_of_week'),
                DB::raw('EXTRACT(HOUR FROM clock_in)::int AS hour_of_day'),
                DB::raw('COUNT(*) AS total_clockings'),
                DB::raw('SUM(CASE WHEN is_late THEN 1 ELSE 0 END) AS late_count'),
                DB::raw('ROUND(100.0 * SUM(CASE WHEN is_late THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 1) AS late_rate'),
            ])
            ->groupBy('day_of_week', 'hour_of_day')
            ->orderBy('day_of_week')
            ->orderBy('hour_of_day')
            ->get()
            ->map(fn($r) => [
                'day_of_week'     => (int)   $r->day_of_week,
                'hour_of_day'     => (int)   $r->hour_of_day,
                'total_clockings' => (int)   $r->total_clockings,
                'late_count'      => (int)   $r->late_count,
                'late_rate'       => (float) $r->late_rate,
            ]);

        $summary = [
            'total_clockings' => $rows->sum('total_clockings'),
            'total_late'      => $rows->sum('late_count'),
            'avg_late_rate'   => $rows->avg('late_rate') ? round($rows->avg('late_rate'), 1) : 0,
            'worst_hour'      => $rows->sortByDesc('late_rate')->first(),
        ];

        return Inertia::render('HR/Attendance/Analytics/Heatmap', [
            'cells'      => $rows->values(),
            'summary'    => $summary,
            'days'       => $days,
        ]);
    }
}
