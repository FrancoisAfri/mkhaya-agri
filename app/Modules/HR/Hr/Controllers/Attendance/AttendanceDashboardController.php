<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceStatus;
use App\Modules\HR\Hr\Models\HRPerson;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today   = Carbon::today();
        $month   = $today->month;
        $year    = $today->year;

        $totalEmployees = HRPerson::where('status', 1)->count();

        $todayRecords = AttendanceRecord::with(['status', 'employee'])
            ->whereDate('date', $today)
            ->get();

        $presentToday = $todayRecords->where('status.is_present', true)->count();
        $absentToday  = $totalEmployees - $presentToday;
        $lateToday    = $todayRecords->where('is_late', true)->count();
        $overtimeToday = $todayRecords->where('overtime_hours', '>', 0)->count();

        $monthlyStats = AttendanceRecord::selectRaw(
            'SUM(hours_worked) as total_hours,
             SUM(overtime_hours) as total_overtime,
             COUNT(CASE WHEN is_late = true THEN 1 END) as late_count,
             COUNT(DISTINCT employee_id) as unique_employees'
        )
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->first();

        $recentRecords = AttendanceRecord::with(['employee.user', 'status', 'shift'])
            ->whereDate('date', $today)
            ->orderBy('clock_in', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'id'         => $r->id,
                'employee'   => $r->employee?->full_name ?? '—',
                'status'     => $r->status?->name ?? '—',
                'statusColor' => $r->status?->color ?? '#6c757d',
                'clock_in'   => $r->clock_in?->format('H:i'),
                'clock_out'  => $r->clock_out?->format('H:i'),
                'hours'      => $r->hours_worked,
                'is_late'    => $r->is_late,
            ]);

        return Inertia::render('HR/Attendance/Dashboard', [
            'stats' => [
                'total_employees'  => $totalEmployees,
                'present_today'    => $presentToday,
                'absent_today'     => $absentToday,
                'late_today'       => $lateToday,
                'overtime_today'   => $overtimeToday,
                'monthly_hours'    => round($monthlyStats->total_hours ?? 0, 1),
                'monthly_overtime' => round($monthlyStats->total_overtime ?? 0, 1),
                'monthly_late'     => $monthlyStats->late_count ?? 0,
            ],
            'recentRecords' => $recentRecords,
            'today'         => $today->toDateString(),
        ]);
    }
}
