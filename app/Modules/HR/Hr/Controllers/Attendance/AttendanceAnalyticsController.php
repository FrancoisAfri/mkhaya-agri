<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $filters = [
            'department_id' => $request->input('department_id'),
            'shift_id'      => $request->input('shift_id'),
            'status_id'     => $request->input('status_id'),
        ];

        $filterOptions = [
            'departments' => DB::table('company_units')->where('type', 'department')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'shifts'      => DB::table('attendance_shifts')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'statuses'    => DB::table('attendance_statuses')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
        ];

        return Inertia::render('HR/Attendance/Analytics/Index', [
            'period'              => $request->input('period', 'month'),
            'dateFrom'            => $request->input('date_from'),
            'dateTo'              => $request->input('date_to'),
            'filters'             => $filters,
            'filterOptions'       => $filterOptions,
            'kpis'                => $this->kpis($from, $to, $filters),
            'attendanceTrend'     => $this->attendanceTrend($from, $to, $filters),
            'latenessTrend'       => $this->latenessTrend($from, $to, $filters),
            'hoursTrend'          => $this->hoursTrend($from, $to, $filters),
            'byDepartment'        => $this->byDepartment($from, $to, $filters),
            'byShift'             => $this->byShift($from, $to, $filters),
            'byStatus'            => $this->byStatus($from, $to, $filters),
            'overtimeTrend'       => $this->overtimeTrend($from, $to, $filters),
            'topLate'             => $this->topLate($from, $to, $filters),
            'topOvertime'         => $this->topOvertime($from, $to, $filters),
        ]);
    }

    private function dateRange(Request $request): array
    {
        if ($request->filled('date_from') && $request->filled('date_to')) {
            return [now()->parse($request->input('date_from'))->startOfDay(), now()->parse($request->input('date_to'))->endOfDay()];
        }
        $now = now();
        return match ($request->input('period', 'month')) {
            'quarter'  => [$now->copy()->subMonths(2)->startOfMonth(), $now->copy()->endOfMonth()],
            'halfyear' => [$now->copy()->subMonths(5)->startOfMonth(), $now->copy()->endOfMonth()],
            'year'     => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'lastyear' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            default    => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    private function empIds(array $f): ?array
    {
        if (!$f['department_id']) return null;
        return DB::table('company_unit_user_pivots as cup')
            ->join('hr_people as p', 'p.user_id', '=', 'cup.user_id')
            ->join('company_units as cu', function ($j) { $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'); })
            ->where('cu.id', $f['department_id'])
            ->pluck('p.id')->toArray();
    }

    private function base($from, $to, array $f)
    {
        $q = DB::table('attendance_records as ar')->whereBetween('ar.date', [$from->toDateString(), $to->toDateString()]);
        if ($f['shift_id'])   $q->where('ar.shift_id', $f['shift_id']);
        if ($f['status_id'])  $q->where('ar.status_id', $f['status_id']);
        $empIds = $this->empIds($f);
        if ($empIds !== null) $q->whereIn('ar.employee_id', $empIds ?: [-1]);
        return $q;
    }

    private function kpis($from, $to, array $f): array
    {
        $q           = $this->base($from, $to, $f);
        $total       = (clone $q)->count();
        $late        = (clone $q)->where('ar.is_late', true)->count();
        $earlyDepart = (clone $q)->where('ar.is_early_departure', true)->count();
        $avgHours    = (clone $q)->avg('ar.hours_worked') ?? 0;
        $totalOT     = (clone $q)->sum('ar.overtime_hours') ?? 0;
        $avgLateMin  = (clone $q)->where('ar.is_late', true)->avg('ar.late_minutes') ?? 0;
        $approved    = (clone $q)->where('ar.manager_approved', true)->count();

        return [
            'total_records'         => $total,
            'late_count'            => $late,
            'late_pct'              => $total > 0 ? round($late / $total * 100, 1) : 0,
            'early_departure_count' => $earlyDepart,
            'avg_hours_worked'      => round($avgHours, 2),
            'total_overtime_hrs'    => round($totalOT, 1),
            'avg_late_minutes'      => round($avgLateMin, 0),
            'approved_pct'          => $total > 0 ? round($approved / $total * 100, 1) : 0,
        ];
    }

    private function attendanceTrend($from, $to, array $f): array
    {
        return $this->base($from, $to, $f)
            ->selectRaw("TO_CHAR(ar.date,'YYYY-MM') as month, COUNT(*) as total, SUM(CASE WHEN ar.is_late THEN 1 ELSE 0 END) as late, SUM(CASE WHEN ar.is_early_departure THEN 1 ELSE 0 END) as early_departure")
            ->groupByRaw("TO_CHAR(ar.date,'YYYY-MM')")->orderByRaw("TO_CHAR(ar.date,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'total' => (int)$r->total, 'late' => (int)$r->late, 'early_departure' => (int)$r->early_departure])->toArray();
    }

    private function latenessTrend($from, $to, array $f): array
    {
        return $this->base($from, $to, $f)->where('ar.is_late', true)
            ->selectRaw("TO_CHAR(ar.date,'YYYY-MM') as month, COUNT(*) as late_count, ROUND(AVG(ar.late_minutes)::numeric,0) as avg_late_mins")
            ->groupByRaw("TO_CHAR(ar.date,'YYYY-MM')")->orderByRaw("TO_CHAR(ar.date,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'late_count' => (int)$r->late_count, 'avg_late_mins' => (float)$r->avg_late_mins])->toArray();
    }

    private function hoursTrend($from, $to, array $f): array
    {
        return $this->base($from, $to, $f)
            ->selectRaw("TO_CHAR(ar.date,'YYYY-MM') as month, ROUND(AVG(ar.hours_worked)::numeric,2) as avg_hours, ROUND(SUM(ar.hours_worked)::numeric,1) as total_hours")
            ->groupByRaw("TO_CHAR(ar.date,'YYYY-MM')")->orderByRaw("TO_CHAR(ar.date,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'avg_hours' => (float)$r->avg_hours, 'total_hours' => (float)$r->total_hours])->toArray();
    }

    private function byDepartment($from, $to, array $f): array
    {
        if ($f['department_id']) return [];
        return DB::table('attendance_records as ar')
            ->whereBetween('ar.date', [$from->toDateString(), $to->toDateString()])
            ->join('hr_people as p', 'p.id', '=', 'ar.employee_id')
            ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'p.user_id')
            ->join('company_units as cu', function ($j) { $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'); })
            ->selectRaw("cu.name as department, COUNT(*) as records, SUM(CASE WHEN ar.is_late THEN 1 ELSE 0 END) as late_count, ROUND(AVG(ar.hours_worked)::numeric,2) as avg_hours")
            ->groupBy('cu.name')->orderByDesc('records')->limit(12)
            ->get()->map(fn($r) => ['department' => $r->department, 'records' => (int)$r->records, 'late_count' => (int)$r->late_count, 'avg_hours' => (float)$r->avg_hours])->toArray();
    }

    private function byShift($from, $to, array $f): array
    {
        return $this->base($from, $to, $f)
            ->leftJoin('attendance_shifts as sh', 'sh.id', '=', 'ar.shift_id')
            ->selectRaw("COALESCE(sh.name,'Unassigned') as shift, COUNT(*) as count, ROUND(AVG(ar.hours_worked)::numeric,2) as avg_hours")
            ->groupBy('sh.name')->orderByDesc('count')
            ->get()->map(fn($r) => ['shift' => $r->shift, 'count' => (int)$r->count, 'avg_hours' => (float)$r->avg_hours])->toArray();
    }

    private function byStatus($from, $to, array $f): array
    {
        return $this->base($from, $to, $f)
            ->leftJoin('attendance_statuses as s', 's.id', '=', 'ar.status_id')
            ->selectRaw("COALESCE(s.name,'None') as status, COUNT(*) as count")
            ->groupBy('s.name')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count])->toArray();
    }

    private function overtimeTrend($from, $to, array $f): array
    {
        return $this->base($from, $to, $f)->where('ar.overtime_hours', '>', 0)
            ->selectRaw("TO_CHAR(ar.date,'YYYY-MM') as month, ROUND(SUM(ar.overtime_hours)::numeric,1) as total_ot, COUNT(*) as employees_with_ot")
            ->groupByRaw("TO_CHAR(ar.date,'YYYY-MM')")->orderByRaw("TO_CHAR(ar.date,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'total_ot' => (float)$r->total_ot, 'employees_with_ot' => (int)$r->employees_with_ot])->toArray();
    }

    private function topLate($from, $to, array $f): array
    {
        return $this->base($from, $to, $f)->where('ar.is_late', true)
            ->join('hr_people as p', 'p.id', '=', 'ar.employee_id')
            ->selectRaw("CONCAT(p.first_name,' ',p.surname) as name, COUNT(*) as late_count, ROUND(AVG(ar.late_minutes)::numeric,0) as avg_late_mins, SUM(ar.late_minutes) as total_late_mins")
            ->groupBy('p.first_name', 'p.surname')->orderByDesc('late_count')->limit(10)
            ->get()->map(fn($r) => ['name' => $r->name, 'late_count' => (int)$r->late_count, 'avg_late_mins' => (float)$r->avg_late_mins, 'total_late_mins' => (int)$r->total_late_mins])->toArray();
    }

    private function topOvertime($from, $to, array $f): array
    {
        return $this->base($from, $to, $f)->where('ar.overtime_hours', '>', 0)
            ->join('hr_people as p', 'p.id', '=', 'ar.employee_id')
            ->selectRaw("CONCAT(p.first_name,' ',p.surname) as name, ROUND(SUM(ar.overtime_hours)::numeric,1) as total_ot, COUNT(*) as ot_days")
            ->groupBy('p.first_name', 'p.surname')->orderByDesc('total_ot')->limit(10)
            ->get()->map(fn($r) => ['name' => $r->name, 'total_ot' => (float)$r->total_ot, 'ot_days' => (int)$r->ot_days])->toArray();
    }
}
