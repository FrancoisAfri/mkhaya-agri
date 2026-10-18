<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceClocking;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDisciplineCase;
use App\Modules\HR\Hr\Models\Attendance\AttendancePolicyViolation;
use App\Modules\HR\Hr\Models\Attendance\AttendanceProductivityScore;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceScheduledReport;
use App\Modules\HR\Hr\Models\Attendance\AttendanceWellnessScore;
use App\Modules\HR\Hr\Models\Attendance\BurnoutRiskProfile;
use App\Modules\HR\Hr\Models\Attendance\DepartmentEfficiencyScore;
use App\Modules\HR\Hr\Models\Attendance\WorkforcePressurePrediction;
use App\Modules\HR\Hr\Models\HRPerson;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class AttendanceReportsController extends Controller
{
    // ── Reports page ──────────────────────────────────────────────────────────

 public function index(Request $request)
{
    $from = $request->input('date_from', now()->startOfMonth()->toDateString());
    $to   = $request->input('date_to',   now()->toDateString());

    $employees   = $this->employeeList();
    $departments = $this->departmentList();

    $lateArrivals = AttendanceRecord::with(['employee.user.companyUnits'])
        ->where('is_late', true)
        ->whereBetween('date', [$from, $to])
        ->orderByDesc('date')
        ->limit(500)
        ->get()
        ->map(fn($r) => [
            'employee_name' => $r->employee ? "{$r->employee->first_name} {$r->employee->surname}" : '—',
            'department'    => $r->employee?->department ?? '—',
            'date'          => $r->date->toDateString(),
            'clock_in'      => $r->clock_in?->format('H:i'),
            'late_minutes'  => $r->late_minutes,
            'shift'         => $r->shift?->name ?? '—',
        ]);

    $overtimeRows = AttendanceRecord::with(['employee.user.companyUnits'])
        ->where('overtime_hours', '>', 0)
        ->whereBetween('date', [$from, $to])
        ->orderByDesc('overtime_hours')
        ->limit(500)
        ->get()
        ->map(fn($r) => [
            'employee_name'  => $r->employee ? "{$r->employee->first_name} {$r->employee->surname}" : '—',
            'department'     => $r->employee?->department ?? '—',
            'date'           => $r->date->toDateString(),
            'hours_worked'   => $r->hours_worked,
            'overtime_hours' => $r->overtime_hours,
            'approved'       => $r->manager_approved ? 'Yes' : 'No',
        ]);

    $earlyDepartures = AttendanceRecord::with(['employee.user.companyUnits'])
        ->where('is_early_departure', true)
        ->whereBetween('date', [$from, $to])
        ->orderByDesc('date')
        ->limit(500)
        ->get()
        ->map(fn($r) => [
            'employee_name' => $r->employee ? "{$r->employee->first_name} {$r->employee->surname}" : '—',
            'department'    => $r->employee?->department ?? '—',
            'date'          => $r->date->toDateString(),
            'clock_out'     => $r->clock_out?->format('H:i'),
            'hours_worked'  => $r->hours_worked,
        ]);

    $clockingLog = AttendanceClocking::with(['employee.user.companyUnits'])
        ->whereBetween(DB::raw('DATE(clocked_at)'), [$from, $to])
        ->orderByDesc('clocked_at')
        ->limit(500)
        ->get()
        ->map(fn($c) => [
            'employee_name' => $c->employee ? "{$c->employee->first_name} {$c->employee->surname}" : '—',
            'department'    => $c->employee?->department ?? '—',
            'type'          => $c->type,
            'method'        => $c->method,
            'work_type'     => $c->work_type,
            'clocked_at'    => $c->clocked_at?->format('Y-m-d H:i:s'),
            'location'      => $c->location,
            'is_missed'     => $c->is_missed ? 'Yes' : 'No',
        ]);

    $attendanceRate = AttendanceRecord::select('employee_id',
            DB::raw('COUNT(*) as total_days'),
            DB::raw('SUM(CASE WHEN clock_in IS NOT NULL THEN 1 ELSE 0 END) as days_present'),
            DB::raw('SUM(CASE WHEN is_late = true THEN 1 ELSE 0 END) as late_count'),
            DB::raw('SUM(CASE WHEN is_early_departure = true THEN 1 ELSE 0 END) as early_count'),
            DB::raw('ROUND(SUM(hours_worked)::numeric, 1) as total_hours'),
            DB::raw('ROUND(SUM(overtime_hours)::numeric, 1) as total_overtime')
        )
        ->whereBetween('date', [$from, $to])
        ->groupBy('employee_id')
        ->with(['employee.user.companyUnits'])
        ->get()
        ->map(fn($r) => [
            'employee_name'   => $r->employee ? "{$r->employee->first_name} {$r->employee->surname}" : '—',
            'department'      => $r->employee?->department ?? '—',
            'total_days'      => $r->total_days,
            'days_present'    => $r->days_present,
            'late_count'      => $r->late_count,
            'early_count'     => $r->early_count,
            'attendance_rate' => $r->total_days > 0 ? round(($r->days_present / $r->total_days) * 100, 1) : 0,
            'total_hours'     => $r->total_hours,
            'total_overtime'  => $r->total_overtime,
        ]);

    $deptSummary = AttendanceRecord::select(
            DB::raw("COALESCE(cu.name, hr_people.division_level_1::text, '(Unassigned)') as department"),
            DB::raw('COUNT(DISTINCT attendance_records.employee_id) as headcount'),
            DB::raw('COUNT(*) as total_records'),
            DB::raw('SUM(CASE WHEN attendance_records.is_late = true THEN 1 ELSE 0 END) as total_late'),
            DB::raw('ROUND(AVG(attendance_records.hours_worked)::numeric, 1) as avg_hours'),
            DB::raw('ROUND(SUM(attendance_records.overtime_hours)::numeric, 1) as total_overtime')
        )
        ->join('hr_people', 'hr_people.id', '=', 'attendance_records.employee_id')
        ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'hr_people.user_id')
        ->leftJoin('company_units as cu', function ($j) {
            $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', '=', 'department');
        })
        ->whereBetween('attendance_records.date', [$from, $to])
        ->groupBy(DB::raw("COALESCE(cu.name, hr_people.division_level_1::text, '(Unassigned)')"))
        ->orderBy('department')
        ->get()
        ->map(fn($r) => [
            'department'     => $r->department ?? '(Unassigned)',
            'headcount'      => $r->headcount,
            'total_records'  => $r->total_records,
            'total_late'     => $r->total_late,
            'avg_hours'      => $r->avg_hours,
            'total_overtime' => $r->total_overtime,
        ]);

    $wellnessReport = AttendanceWellnessScore::with(['employee.user.companyUnits'])
        ->orderBy('wellness_score')
        ->limit(500)
        ->get()
        ->map(fn($s) => [
            'employee_name'    => $s->employee ? "{$s->employee->first_name} {$s->employee->surname}" : '—',
            'department'       => $s->employee?->department ?? '—',
            'wellness_score'   => $s->wellness_score,
            'burnout_risk'     => $s->burnout_risk,
            'fatigue_level'    => $s->fatigue_level,
            'total_overtime_h' => $s->total_overtime_hours,
            'absenteeism_pct'  => $s->absenteeism_rate,
            'period_start'     => $s->period_start->toDateString(),
            'period_end'       => $s->period_end->toDateString(),
        ]);

    $productivityReport = AttendanceProductivityScore::with(['employee.user.companyUnits'])
        ->orderBy('overall_score')
        ->limit(500)
        ->get()
        ->map(fn($s) => [
            'employee_name'   => $s->employee ? "{$s->employee->first_name} {$s->employee->surname}" : '—',
            'department'      => $s->employee?->department ?? '—',
            'overall_score'   => $s->overall_score,
            'tier'            => $s->productivity_tier,
            'attendance_rate' => $s->attendance_rate,
            'late_count'      => $s->late_count,
            'early_count'     => $s->early_departures,
            'period_start'    => $s->period_start->toDateString(),
            'period_end'      => $s->period_end->toDateString(),
        ]);

    $policyViolations = AttendancePolicyViolation::with(['employee.user.companyUnits', 'policyRule'])
        ->orderByDesc('created_at')
        ->limit(500)
        ->get()
        ->map(fn($v) => [
            'employee_name' =>$v->employee ? "{$v->employee->first_name} {$v->employee->surname}" : '—',
            'department'    => $v->employee?->department ?? '—',
            'rule'          => $v->policyRule?->name ?? '—',
            'category'      => $v->policyRule?->category ?? '—',
            'severity'      => $v->policyRule?->severity ?? '—',
            'status'        => $v->status,
            'blocked'       => $v->blocked ? 'Yes' : 'No',
            'created_at'    => $v->created_at?->toDateString(),
        ]);

    $disciplineCases = AttendanceDisciplineCase::with(['employee.user.companyUnits'])
        ->orderByDesc('created_at')
        ->limit(500)
        ->get()
        ->map(fn($c) => [
            'employee_name' => $c->employee ? "{$c->employee->first_name} {$c->employee->surname}" : '—',
            'department'    => $c->employee?->department ?? '—',
            'case_number'   => $c->case_number,
            'case_type'     => AttendanceDisciplineCase::CASE_TYPES[$c->case_type] ?? $c->case_type,
            'severity'      => $c->severity,
            'status'        => $c->status,
            'period_start'  => $c->period_start->toDateString(),
            'period_end'    => $c->period_end->toDateString(),
            'created_at'    => $c->created_at?->toDateString(),
        ]);

    return Inertia::render('HR/Attendance/Reports/Index', [
        'lateArrivals'       => $lateArrivals,
        'overtimeRows'       => $overtimeRows,
        'earlyDepartures'    => $earlyDepartures,
        'clockingLog'        => $clockingLog,
        'attendanceRate'     => $attendanceRate,
        'deptSummary'        => $deptSummary,
        'wellnessReport'     => $wellnessReport,
        'productivityReport' => $productivityReport,
        'policyViolations'   => $policyViolations,
        'disciplineCases'    => $disciplineCases,
        'employees'          => $employees,
        'departments'        => $departments,
        'filters'            => ['date_from' => $from, 'date_to' => $to],
    ]);
}

    // ── Generate (count) ──────────────────────────────────────────────────────

    public function generate(string $key): JsonResponse
    {
        $count = match ($key) {
            'late_arrivals'    => AttendanceRecord::where('is_late', true)->whereMonth('date', now()->month)->count(),
            'overtime'         => AttendanceRecord::where('overtime_hours', '>', 0)->whereMonth('date', now()->month)->count(),
            'early_departures' => AttendanceRecord::where('is_early_departure', true)->whereMonth('date', now()->month)->count(),
            'clocking_log'     => AttendanceClocking::whereMonth('clocked_at', now()->month)->count(),
            'attendance_rate'  => AttendanceRecord::distinct('employee_id')->whereMonth('date', now()->month)->count(),
            'dept_summary'     => AttendanceRecord::join('hr_people', 'hr_people.id', '=', 'attendance_records.employee_id')->leftJoin('company_unit_user_pivots as cup2', 'cup2.user_id', '=', 'hr_people.user_id')->leftJoin('company_units as cu2', function($j) { $j->on('cu2.id', '=', 'cup2.company_id')->where('cu2.type', '=', 'department'); })->select(DB::raw("COALESCE(cu2.name, hr_people.division_level_1::text)"))->distinct()->count(),
            'wellness'         => AttendanceWellnessScore::count(),
            'productivity'     => AttendanceProductivityScore::count(),
            'policy_violations'=> AttendancePolicyViolation::count(),
            'discipline_cases' => AttendanceDisciplineCase::count(),
            default            => 0,
        };

        return response()->json(['key' => $key, 'count' => $count]);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(Request $request, string $key, string $format)
    {
        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to   = $request->input('date_to',   now()->toDateString());

        [$headers, $rows] = $this->buildExportData($key, $from, $to);
        $filename = "attendance-{$key}-" . now()->format('Y-m-d');

        if ($format === 'xlsx') {
            return Excel::download(
                new \App\Modules\HR\Hr\Exports\AttendanceReportExport($headers, $rows),
                "{$filename}.xlsx",
            );
        }

        $pdf = Pdf::loadView('reports.attendance-report', [
            'title'   => $this->reportTitle($key),
            'headers' => $headers,
            'rows'    => $rows,
            'date'    => now()->format('d M Y'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download("{$filename}.pdf");
    }

    // ── Queue (email large export) ─────────────────────────────────────────────

    public function queue(string $key): JsonResponse
    {
        return response()->json(['queued' => true, 'key' => $key]);
    }

    // ── Scheduled reports ─────────────────────────────────────────────────────

    public function scheduledReports()
    {
        $reports = AttendanceScheduledReport::orderByDesc('created_at')->get()->map(fn($r) => $this->formatScheduled($r));

        return Inertia::render('HR/Attendance/Reports/ScheduledReports', [
            'reports'    => $reports,
            'reportKeys' => $this->reportKeys(),
        ]);
    }

    public function storeScheduled(Request $request)
    {
        $data = $this->validateScheduled($request);
        $report = AttendanceScheduledReport::create(array_merge($data, ['created_by' => Auth::id()]));
        $report->update(['next_run_at' => $report->computeNextRun()]);

        return redirect()->back()->with('success', 'Scheduled report created.');
    }

    public function updateScheduled(Request $request, AttendanceScheduledReport $scheduledReport)
    {
        $data = $this->validateScheduled($request);
        $scheduledReport->fill($data);
        $scheduledReport->next_run_at = $scheduledReport->computeNextRun();
        $scheduledReport->save();

        return redirect()->back()->with('success', 'Scheduled report updated.');
    }

    public function destroyScheduled(AttendanceScheduledReport $scheduledReport)
    {
        $scheduledReport->delete();
        return redirect()->back()->with('success', 'Scheduled report deleted.');
    }

    // ── Executive Dashboard ───────────────────────────────────────────────────

    public function executive()
    {
        $today = Carbon::today();
        $month = $today->month;
        $year  = $today->year;

        $totalActive = HRPerson::where('status', 1)->count();

        $todayStats = AttendanceRecord::whereDate('date', $today)->get();
        $presentToday  = $todayStats->whereNotNull('clock_in')->count();
        $lateToday     = $todayStats->where('is_late', true)->count();
        $overtimeToday = $todayStats->where('overtime_hours', '>', 0)->count();
        $wfhToday      = AttendanceClocking::where('work_type', 'remote')->whereDate('clocked_at', $today)->distinct('employee_id')->count();

        // Monthly trend — last 12 weeks, attendance rate per week
        $weeklyTrend = AttendanceRecord::selectRaw(
                "DATE_TRUNC('week', date) as week_start,
                 COUNT(*) as total,
                 SUM(CASE WHEN clock_in IS NOT NULL THEN 1 ELSE 0 END) as present,
                 SUM(CASE WHEN is_late = true THEN 1 ELSE 0 END) as late,
                 ROUND(SUM(overtime_hours)::numeric, 1) as overtime"
            )
            ->where('date', '>=', $today->copy()->subWeeks(12))
            ->groupByRaw("DATE_TRUNC('week', date)")
            ->orderByRaw("DATE_TRUNC('week', date)")
            ->get()
            ->map(fn($r) => [
                'week'           => Carbon::parse($r->week_start)->format('d M'),
                'attendance_rate'=> $r->total > 0 ? round(($r->present / $r->total) * 100, 1) : 0,
                'late_count'     => (int) $r->late,
                'overtime'       => (float) $r->overtime,
            ]);

        // Burnout / wellness risk summary
        $burnoutSummary = [
            'critical' => BurnoutRiskProfile::where('overall_risk', 'critical')->count(),
            'high'     => BurnoutRiskProfile::where('overall_risk', 'high')->count(),
            'medium'   => BurnoutRiskProfile::where('overall_risk', 'medium')->count(),
        ];

        // Top at-risk employees (burnout critical/high)
        $atRiskEmployees = BurnoutRiskProfile::with('employee')
            ->whereIn('overall_risk', ['critical', 'high'])
            ->orderByDesc(DB::raw('(overtime_overload_score + consecutive_work_score + absence_pattern_score + late_arrival_score + shift_instability_score) / 5'))
            ->take(8)
            ->get()
            ->map(fn($p) => [
                'employee'       => $p->employee ? "{$p->employee->first_name} {$p->employee->surname}" : '—',
                'department'     => $p->employee?->department ?? '—',
                'overall_risk'   => $p->overall_risk,
                'risk_color'     => $p->risk_color,
                'composite_score'=> $p->composite_score,
                'risk_factors'   => $p->risk_factors,
            ]);

        // Policy violations requiring attention
        $openViolations = AttendancePolicyViolation::with(['employee', 'policyRule'])
            ->whereIn('status', ['open', 'escalated'])
            ->orderByDesc('created_at')
            ->take(8)
            ->get()
            ->map(fn($v) => [
                'employee'  => $v->employee?->full_name ?? '—',
                'department'=> $v->employee?->department ?? '—',
                'rule'      => $v->policyRule?->name ?? '—',
                'severity'  => $v->policyRule?->severity ?? '—',
                'status'    => $v->status,
                'blocked'   => $v->blocked,
                'days_open' => $v->created_at ? $v->created_at->diffInDays(now()) : 0,
            ]);

        // Department efficiency ranking
        $deptEfficiency = DepartmentEfficiencyScore::whereMonth('period_start', $month)->whereYear('period_start', $year)
            ->orderByDesc('efficiency_score')
            ->get()
            ->map(fn($d) => [
                'department'       => $d->department,
                'efficiency_score' => $d->efficiency_score,
                'headcount'        => $d->headcount,
                'is_overworked'    => $d->is_overworked_dept,
                'avg_hours'        => $d->avg_hours_worked,
            ]);

        // Productivity summary
        $productivitySummary = [
            'high'    => AttendanceProductivityScore::where('productivity_tier', 'high')->whereMonth('period_start', $month)->count(),
            'average' => AttendanceProductivityScore::where('productivity_tier', 'average')->whereMonth('period_start', $month)->count(),
            'low'     => AttendanceProductivityScore::where('productivity_tier', 'low')->whereMonth('period_start', $month)->count(),
            'at_risk' => AttendanceProductivityScore::where('productivity_tier', 'at_risk')->whereMonth('period_start', $month)->count(),
        ];

        // Active pressure predictions
        $predictions = WorkforcePressurePrediction::active()
            ->orderByDesc('confidence')
            ->take(6)
            ->get()
            ->map(fn($p) => [
                'type'        => WorkforcePressurePrediction::TYPES[$p->prediction_type] ?? $p->prediction_type,
                'scope'       => $p->scope,
                'department'  => $p->department,
                'risk_level'  => $p->risk_level,
                'risk_color'  => $p->risk_color,
                'confidence'  => $p->confidence,
                'predicted_for'=> $p->predicted_for->toDateString(),
            ]);

        return Inertia::render('HR/Attendance/Executive/Index', [
            'today'              => $today->toDateString(),
            'totalActive'        => $totalActive,
            'todayKpis'          => [
                'present'   => $presentToday,
                'absent'    => $totalActive - $presentToday,
                'late'      => $lateToday,
                'wfh'       => $wfhToday,
                'overtime'  => $overtimeToday,
            ],
            'weeklyTrend'        => $weeklyTrend,
            'burnoutSummary'     => $burnoutSummary,
            'atRiskEmployees'    => $atRiskEmployees,
            'openViolations'     => $openViolations,
            'deptEfficiency'     => $deptEfficiency,
            'productivitySummary'=> $productivitySummary,
            'predictions'        => $predictions,
        ]);
    }

    // ── Executive AI Summary ──────────────────────────────────────────────────

    public function executiveAiSummary(Request $request): JsonResponse
    {
        $today       = $request->input('today', now()->toDateString());
        $totalActive = HRPerson::where('status', 1)->count();
        $todayRecs   = AttendanceRecord::whereDate('date', $today)->get();
        $presentToday  = $todayRecs->whereNotNull('clock_in')->count();
        $lateToday     = $todayRecs->where('is_late', true)->count();
        $overtimeToday = $todayRecs->where('overtime_hours', '>', 0)->count();
        $burnoutCritical = BurnoutRiskProfile::where('overall_risk', 'critical')->count();
        $burnoutHigh     = BurnoutRiskProfile::where('overall_risk', 'high')->count();
        $openViolations  = AttendancePolicyViolation::whereIn('status', ['open', 'escalated'])->count();
        $lowProductivity = AttendanceProductivityScore::whereIn('productivity_tier', ['low', 'at_risk'])->whereMonth('period_start', now()->month)->count();

        $context = "Today ({$today}): {$presentToday}/{$totalActive} employees present, {$lateToday} late, {$overtimeToday} on overtime. " .
                   "Burnout risk: {$burnoutCritical} critical, {$burnoutHigh} high. " .
                   "Open policy violations: {$openViolations}. " .
                   "Low/at-risk productivity employees this month: {$lowProductivity}. ";

        try {
            $service = app(\App\Modules\HR\Hr\Services\AttendanceIntelligenceService::class);
            $summary = $service->generateExecutiveSummary($context);
        } catch (\Throwable) {
            $summary = "Attendance Intelligence Summary for {$today}:\n\n" .
                       "Present: {$presentToday} of {$totalActive} active employees ({$lateToday} late, {$overtimeToday} overtime). " .
                       "Burnout risk flags: {$burnoutCritical} critical and {$burnoutHigh} high risk profiles. " .
                       "Policy violations requiring attention: {$openViolations}. " .
                       "Employees scoring low/at-risk on productivity this month: {$lowProductivity}.";
        }

        return response()->json(['summary' => $summary]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function employeeList(): \Illuminate\Support\Collection
    {
        return DB::table('hr_people')
            ->where('status', 1)
            ->orderByRaw("TRIM(CONCAT(first_name, ' ', surname))")
            ->get(['id', DB::raw("TRIM(CONCAT(first_name, ' ', surname)) as name")])
            ->map(fn($e) => ['id' => $e->id, 'name' => $e->name]);
    }

    private function departmentList(): \Illuminate\Support\Collection
    {
        $fromUnits = DB::table('company_units')
            ->where('type', 'department')
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name');

        if ($fromUnits->isNotEmpty()) {
            return $fromUnits->map(fn($d) => ['id' => $d, 'name' => $d]);
        }

        return DB::table('hr_people')
            ->whereNotNull('division_level_1')
            ->distinct()
            ->orderBy('division_level_1')
            ->pluck('division_level_1')
            ->map(fn($d) => ['id' => $d, 'name' => $d]);
    }

    private function buildExportData(string $key, string $from, string $to): array
    {
        return match ($key) {
            'late_arrivals' => [
                ['Employee', 'Department', 'Date', 'Clock In', 'Late (min)', 'Shift'],
                AttendanceRecord::with('employee')->where('is_late', true)->whereBetween('date', [$from, $to])
                    ->get()->map(fn($r) => [$r->employee?->full_name, $r->employee?->department, $r->date->toDateString(), $r->clock_in?->format('H:i'), $r->late_minutes, $r->shift?->name])->toArray(),
            ],
            'overtime' => [
                ['Employee', 'Department', 'Date', 'Hours Worked', 'Overtime (h)', 'Approved'],
                AttendanceRecord::with('employee')->where('overtime_hours', '>', 0)->whereBetween('date', [$from, $to])
                    ->get()->map(fn($r) => [$r->employee?->full_name, $r->employee?->department, $r->date->toDateString(), $r->hours_worked, $r->overtime_hours, $r->manager_approved ? 'Yes' : 'No'])->toArray(),
            ],
            'attendance_rate' => [
                ['Employee', 'Department', 'Total Days', 'Present', 'Late', 'Attendance Rate %', 'Total Hours', 'Overtime (h)'],
                AttendanceRecord::select('employee_id', DB::raw('COUNT(*) as total_days'), DB::raw('SUM(CASE WHEN clock_in IS NOT NULL THEN 1 ELSE 0 END) as days_present'), DB::raw('SUM(CASE WHEN is_late = true THEN 1 ELSE 0 END) as late_count'), DB::raw('ROUND(SUM(hours_worked)::numeric,1) as total_hours'), DB::raw('ROUND(SUM(overtime_hours)::numeric,1) as overtime'))
                    ->whereBetween('date', [$from, $to])->groupBy('employee_id')->with('employee')
                    ->get()->map(fn($r) => [$r->employee?->full_name, $r->employee?->department, $r->total_days, $r->days_present, $r->late_count, $r->total_days > 0 ? round(($r->days_present / $r->total_days) * 100, 1) : 0, $r->total_hours, $r->overtime])->toArray(),
            ],
            default => [[], []],
        };
    }

    private function validateScheduled(Request $request): array
    {
        return $request->validate([
            'name'          => 'required|string|max:150',
            'report_key'    => 'required|string|in:' . implode(',', array_keys($this->reportKeys())),
            'cadence'       => 'required|in:daily,weekly,monthly',
            'run_time'      => 'required|date_format:H:i',
            'day_of_week'   => 'nullable|integer|between:1,7',
            'day_of_month'  => 'nullable|integer|between:1,28',
            'format'        => 'required|in:pdf,excel,both',
            'recipients'    => 'required|string|max:1000',
            'active'        => 'boolean',
        ]);
    }

    private function formatScheduled(AttendanceScheduledReport $r): array
    {
        return [
            'id'           => $r->id,
            'name'         => $r->name,
            'report_key'   => $r->report_key,
            'cadence'      => $r->cadence,
            'run_time'     => $r->run_time,
            'day_of_week'  => $r->day_of_week,
            'day_of_month' => $r->day_of_month,
            'format'       => $r->format,
            'recipients'   => $r->recipients,
            'active'       => $r->active,
            'next_run'     => $r->next_run_at?->format('d M Y H:i'),
            'last_run_at'  => $r->last_run_at?->format('d M Y H:i'),
        ];
    }

    private function reportKeys(): array
    {
        return [
            'late_arrivals'    => 'Late Arrivals',
            'overtime'         => 'Overtime Report',
            'early_departures' => 'Early Departures',
            'clocking_log'     => 'Clocking Log',
            'attendance_rate'  => 'Attendance Rate',
            'dept_summary'     => 'Department Summary',
            'wellness'         => 'Wellness Scores',
            'productivity'     => 'Productivity Scores',
            'policy_violations'=> 'Policy Violations',
            'discipline_cases' => 'Discipline Cases',
        ];
    }

    private function reportTitle(string $key): string
    {
        return $this->reportKeys()[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
