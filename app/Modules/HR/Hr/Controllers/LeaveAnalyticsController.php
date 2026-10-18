<?php

namespace App\Modules\HR\Hr\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LeaveAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $filters = [
            'leave_type_id' => $request->input('leave_type_id'),
            'department_id' => $request->input('department_id'),
            'status'        => $request->input('status'),
        ];

        $filterOptions = [
            'leave_types' => DB::table('leave_types')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'departments' => DB::table('company_units')->where('type', 'department')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'statuses'    => [
                ['value' => '0', 'label' => 'Pending'],
                ['value' => '1', 'label' => 'Approved'],
                ['value' => '2', 'label' => 'Rejected'],
                ['value' => '3', 'label' => 'Cancelled'],
            ],
        ];

        return Inertia::render('HR/Leave/Analytics/Index', [
            'period'        => $request->input('period', 'year'),
            'dateFrom'      => $request->input('date_from'),
            'dateTo'        => $request->input('date_to'),
            'filters'       => $filters,
            'filterOptions' => $filterOptions,
            'kpis'          => $this->kpis($from, $to, $filters),
            'byLeaveType'   => $this->byLeaveType($from, $to, $filters),
            'byStatus'      => $this->byStatus($from, $to, $filters),
            'monthlyTrend'  => $this->monthlyTrend($from, $to, $filters),
            'byDepartment'  => $this->byDepartment($from, $to, $filters),
            'leaveDuration' => $this->leaveDuration($from, $to, $filters),
            'topTakers'     => $this->topTakers($from, $to, $filters),
            'pendingApprovals' => $this->pendingApprovals($filters),
        ]);
    }

    private function dateRange(Request $request): array
    {
        if ($request->filled('date_from') && $request->filled('date_to')) {
            return [now()->parse($request->input('date_from'))->startOfDay(), now()->parse($request->input('date_to'))->endOfDay()];
        }
        $now = now();
        return match ($request->input('period', 'year')) {
            'month'    => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'quarter'  => [$now->copy()->subMonths(2)->startOfMonth(), $now->copy()->endOfMonth()],
            'halfyear' => [$now->copy()->subMonths(5)->startOfMonth(), $now->copy()->endOfMonth()],
            'lastyear' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            default    => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        };
    }

    private function base($from, $to, array $f)
    {
        $q = DB::table('leave_applications as la')->whereBetween('la.created_at', [$from, $to]);
        if ($f['leave_type_id']) $q->where('la.leave_type_id', $f['leave_type_id']);
        if ($f['status'] !== null && $f['status'] !== '') $q->where('la.status', $f['status']);
        return $q;
    }

    private function kpis($from, $to, array $f): array
    {
        $q        = $this->base($from, $to, $f);
        $total    = (clone $q)->count();
        $approved = (clone $q)->where('la.status', 1)->count();
        $pending  = (clone $q)->where('la.status', 0)->count();
        $rejected = (clone $q)->where('la.status', 2)->count();
        $cancelled= (clone $q)->where('la.status', 3)->count();
        $avgDays  = (clone $q)->where('la.status', 1)->avg('la.leave_days') ?? 0;
        $totalDays= (clone $q)->where('la.status', 1)->sum('la.leave_days') ?? 0;

        return [
            'total'          => $total,
            'approved'       => $approved,
            'pending'        => $pending,
            'rejected'       => $rejected,
            'cancelled'      => $cancelled,
            'approval_rate'  => $total > 0 ? round($approved / $total * 100, 1) : 0,
            'avg_days'       => round($avgDays, 1),
            'total_days'     => (int) $totalDays,
        ];
    }

    private function byLeaveType($from, $to, array $f): array
    {
        $q = $this->base($from, $to, $f)
            ->join('leave_types as lt', 'lt.id', '=', 'la.leave_type_id');
        return $q->selectRaw("lt.name as leave_type, COUNT(*) as count, SUM(CASE WHEN la.status=1 THEN la.leave_days ELSE 0 END) as days_taken")
            ->groupBy('lt.name')->orderByDesc('count')->limit(15)
            ->get()->map(fn($r) => ['leave_type' => $r->leave_type, 'count' => (int)$r->count, 'days_taken' => (float)$r->days_taken])->toArray();
    }

    private function byStatus($from, $to, array $f): array
    {
        $q = $this->base($from, $to, $f);
        return $q->selectRaw("CASE la.status WHEN 0 THEN 'Pending' WHEN 1 THEN 'Approved' WHEN 2 THEN 'Rejected' WHEN 3 THEN 'Cancelled' ELSE 'Unknown' END as status_label, COUNT(*) as count")
            ->groupBy('la.status')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status_label, 'count' => (int)$r->count])->toArray();
    }

    private function monthlyTrend($from, $to, array $f): array
    {
        $q = $this->base($from, $to, $f);
        return $q->selectRaw("TO_CHAR(la.created_at,'YYYY-MM') as month, COUNT(*) as total, SUM(CASE WHEN la.status=1 THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN la.status=0 THEN 1 ELSE 0 END) as pending")
            ->groupByRaw("TO_CHAR(la.created_at,'YYYY-MM')")->orderByRaw("TO_CHAR(la.created_at,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'total' => (int)$r->total, 'approved' => (int)$r->approved, 'pending' => (int)$r->pending])->toArray();
    }

    private function byDepartment($from, $to, array $f): array
    {
        if ($f['department_id']) {
            return [];
        }
        $q = $this->base($from, $to, $f)
            ->join('hr_people as p', 'p.id', '=', 'la.hr_id')
            ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'p.user_id')
            ->join('company_units as cu', function ($j) {
                $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department');
            });
        return $q->selectRaw("cu.name as department, COUNT(*) as count, ROUND(AVG(CASE WHEN la.status=1 THEN la.leave_days END)::numeric,1) as avg_days")
            ->groupBy('cu.name')->orderByDesc('count')->limit(12)
            ->get()->map(fn($r) => ['department' => $r->department, 'count' => (int)$r->count, 'avg_days' => (float)$r->avg_days])->toArray();
    }

    private function leaveDuration($from, $to, array $f): array
    {
        $q = $this->base($from, $to, $f)
            ->join('leave_types as lt', 'lt.id', '=', 'la.leave_type_id')
            ->where('la.status', 1)->whereNotNull('la.leave_days');
        return $q->selectRaw("lt.name as leave_type, ROUND(AVG(la.leave_days)::numeric,1) as avg_days, MAX(la.leave_days) as max_days, COUNT(*) as count")
            ->groupBy('lt.name')->orderByDesc('avg_days')->limit(10)
            ->get()->map(fn($r) => ['leave_type' => $r->leave_type, 'avg_days' => (float)$r->avg_days, 'max_days' => (int)$r->max_days, 'count' => (int)$r->count])->toArray();
    }

    private function topTakers($from, $to, array $f): array
    {
        $q = $this->base($from, $to, $f)
            ->join('hr_people as p', 'p.id', '=', 'la.hr_id')
            ->where('la.status', 1);
        if ($f['department_id']) {
            $q->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'p.user_id')
              ->join('company_units as cu', function ($j) { $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'); })
              ->where('cu.id', $f['department_id']);
        }
        return $q->selectRaw("CONCAT(p.first_name,' ',p.surname) as name, COUNT(*) as requests, SUM(la.leave_days) as total_days")
            ->groupBy('p.first_name', 'p.surname')->orderByDesc('total_days')->limit(10)
            ->get()->map(fn($r) => ['name' => $r->name, 'requests' => (int)$r->requests, 'total_days' => (float)$r->total_days])->toArray();
    }

    private function pendingApprovals(array $f): array
    {
        $q = DB::table('leave_applications as la')
            ->join('hr_people as p', 'p.id', '=', 'la.hr_id')
            ->join('leave_types as lt', 'lt.id', '=', 'la.leave_type_id')
            ->where('la.status', 0);
        if ($f['leave_type_id']) $q->where('la.leave_type_id', $f['leave_type_id']);
        return $q->selectRaw("CONCAT(p.first_name,' ',p.surname) as name, lt.name as leave_type, la.leave_days, la.created_at")
            ->orderBy('la.created_at')->limit(15)
            ->get()->map(fn($r) => (array)$r)->toArray();
    }
}
