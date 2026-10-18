<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceProductivityScore;
use App\Modules\HR\Hr\Models\Attendance\AttendanceProductivityTrend;
use App\Modules\HR\Hr\Models\Attendance\DepartmentEfficiencyScore;
use App\Modules\HR\Hr\Services\AttendanceProductivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class AttendanceProductivityController extends Controller
{
    public function __construct(private AttendanceProductivityService $service) {}

    public function index()
    {
        $from = now()->startOfMonth();
        $to   = now()->endOfMonth();

        $scores      = AttendanceProductivityScore::whereBetween('period_start', [$from, $to])->orderBy('overall_score')->get();
        $departments = DepartmentEfficiencyScore::whereBetween('period_start', [$from, $to])->orderByDesc('efficiency_score')->get();
        $trends      = AttendanceProductivityTrend::where('scope', 'company')->orderByDesc('period_date')->take(12)->get();

        $summary = [
            'avg_score'        => (int) $scores->avg('overall_score'),
            'high_performers'  => $scores->where('productivity_tier', 'high')->count(),
            'low_performers'   => $scores->whereIn('productivity_tier', ['low', 'at_risk'])->count(),
            'avg_attendance'   => round($scores->avg('attendance_rate'), 1),
            'overworked_depts' => $departments->where('is_overworked_dept', true)->count(),
        ];

        return Inertia::render('HR/Attendance/Productivity/Index', compact('scores', 'departments', 'trends', 'summary'));
    }

    public function departments()
    {
        $from = now()->startOfMonth();
        $to   = now()->endOfMonth();
        $departments = DepartmentEfficiencyScore::whereBetween('period_start', [$from, $to])->orderBy('efficiency_score')->get();

        return Inertia::render('HR/Attendance/Productivity/Departments', compact('departments'));
    }

    public function trends()
    {
        $trends = AttendanceProductivityTrend::where('scope', 'company')
            ->orderByDesc('period_date')
            ->take(24)
            ->get()
            ->groupBy('metric');

        return Inertia::render('HR/Attendance/Productivity/Trends', compact('trends'));
    }

    public function runScan(Request $request)
    {
        $from = Carbon::parse($request->input('from', now()->startOfMonth()));
        $to   = Carbon::parse($request->input('to', now()->endOfMonth()));

        $scores      = $this->service->analyzeAttendanceProductivity($from, $to);
        $departments = $this->service->trackDepartmentEfficiency($from, $to);

        return redirect()->back()->with('success', "Productivity scan complete. {$scores->count()} employee(s) and {$departments->count()} department(s) scored.");
    }

    public function generateInsights(Request $request)
    {
        $from  = now()->startOfMonth();
        $to    = now()->endOfMonth();
        $scores = AttendanceProductivityScore::whereBetween('period_start', [$from, $to])->get();
        $depts  = DepartmentEfficiencyScore::whereBetween('period_start', [$from, $to])->get();

        $insights = $this->service->generateAttendanceInsights(
            $from->format('F Y'),
            [
                'avg_attendance_rate' => round($scores->avg('attendance_rate'), 1),
                'avg_score'           => (int) $scores->avg('overall_score'),
                'low_performers'      => $scores->whereIn('productivity_tier', ['low', 'at_risk'])->count(),
                'high_performers'     => $scores->where('productivity_tier', 'high')->count(),
                'overworked_depts'    => $depts->where('is_overworked_dept', true)->count(),
            ]
        );

        return response()->json(['insights' => $insights]);
    }

    public function generateDeptInsights(DepartmentEfficiencyScore $departmentEfficiencyScore)
    {
        $dept = $departmentEfficiencyScore;
        $insights = $this->service->generateAttendanceInsights(
            "{$dept->department} — " . Carbon::parse($dept->period_start)->format('F Y'),
            [
                'avg_attendance_rate' => $dept->avg_attendance_rate,
                'avg_score'           => $dept->efficiency_score,
                'low_performers'      => $dept->low_productivity_count,
                'high_performers'     => $dept->headcount - $dept->low_productivity_count,
                'overworked_depts'    => $dept->is_overworked_dept ? 1 : 0,
            ]
        );

        $dept->update(['ai_summary' => $insights]);

        return response()->json(['summary' => $insights]);
    }
}
