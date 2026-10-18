<?php

namespace App\Modules\Policies\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PolicyAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $filters = [
            'category_id'   => $request->input('category_id'),
            'department_id' => $request->input('department_id'),
            'status'        => $request->input('status'),
        ];

        $filterOptions = [
            'categories'  => DB::table('policy_category')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'departments' => DB::table('company_units')->where('type', 'department')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'statuses'    => ['draft', 'active', 'under_review', 'archived'],
        ];

        return Inertia::render('Policies/Analytics/Index', [
            'period'               => $request->input('period', 'year'),
            'dateFrom'             => $request->input('date_from'),
            'dateTo'               => $request->input('date_to'),
            'filters'              => $filters,
            'filterOptions'        => $filterOptions,
            'kpis'                 => $this->kpis($from, $to, $filters),
            'byStatus'             => $this->byStatus($filters),
            'byCategory'           => $this->byCategory($filters),
            'violationTrend'       => $this->violationTrend($from, $to, $filters),
            'violationBySeverity'  => $this->violationBySeverity($from, $to, $filters),
            'violationByOutcome'   => $this->violationByOutcome($from, $to, $filters),
            'attestationTrend'     => $this->attestationTrend($from, $to, $filters),
            'attestationByStatus'  => $this->attestationByStatus($from, $to, $filters),
            'complianceByDept'     => $this->complianceByDept($from, $to, $filters),
            'topViolatedPolicies'  => $this->topViolatedPolicies($from, $to, $filters),
            'openViolations'       => $this->openViolations($filters),
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

    private function kpis($from, $to, array $f): array
    {
        $base = DB::table('policy')->whereNull('deleted_at');
        if ($f['category_id']) $base->where('category_id', $f['category_id']);
        if ($f['status'])      $base->where('status', $f['status']);
        if ($f['department_id']) $base->where('department_id', $f['department_id']);

        $total       = (clone $base)->count();
        $active      = (clone $base)->where('status', 'active')->count();
        $requireAttest = (clone $base)->where('requires_attestation', true)->count();

        $vQ = DB::table('policy_violations')->whereBetween('created_at', [$from, $to]);
        if ($f['department_id']) $vQ->where('department_id', $f['department_id']);
        $violations = $vQ->count();
        $openViolations = (clone $vQ)->where('status', 'open')->count();

        $aQ = DB::table('policy_attestations')->whereBetween('created_at', [$from, $to]);
        $totalAttest    = $aQ->count();
        $completedAttest= (clone $aQ)->where('status', 'completed')->count();
        $overdueAttest  = (clone $aQ)->where('status', 'overdue')->count();

        return [
            'total_policies'       => $total,
            'active_policies'      => $active,
            'require_attestation'  => $requireAttest,
            'violations'           => $violations,
            'open_violations'      => $openViolations,
            'attestations'         => $totalAttest,
            'completed_attestations' => $completedAttest,
            'overdue_attestations' => $overdueAttest,
            'attestation_rate'     => $totalAttest > 0 ? round($completedAttest / $totalAttest * 100, 1) : 0,
        ];
    }

    private function byStatus(array $f): array
    {
        $q = DB::table('policy')->whereNull('deleted_at');
        if ($f['category_id'])   $q->where('category_id', $f['category_id']);
        if ($f['department_id']) $q->where('department_id', $f['department_id']);
        return $q->selectRaw("status, COUNT(*) as count")->groupBy('status')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count])->toArray();
    }

    private function byCategory(array $f): array
    {
        $q = DB::table('policy as p')
            ->join('policy_category as c', 'c.id', '=', 'p.category_id')
            ->whereNull('p.deleted_at');
        if ($f['status'])        $q->where('p.status', $f['status']);
        if ($f['department_id']) $q->where('p.department_id', $f['department_id']);
        return $q->selectRaw("c.name as category, COUNT(*) as count, SUM(CASE WHEN p.status='active' THEN 1 ELSE 0 END) as active")
            ->groupBy('c.name')->orderByDesc('count')->limit(10)
            ->get()->map(fn($r) => ['category' => $r->category, 'count' => (int)$r->count, 'active' => (int)$r->active])->toArray();
    }

    private function violationTrend($from, $to, array $f): array
    {
        $q = DB::table('policy_violations')->whereBetween('created_at', [$from, $to]);
        if ($f['department_id']) $q->where('department_id', $f['department_id']);
        return $q->selectRaw("TO_CHAR(created_at,'YYYY-MM') as month, COUNT(*) as total, SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) as resolved")
            ->groupByRaw("TO_CHAR(created_at,'YYYY-MM')")->orderByRaw("TO_CHAR(created_at,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'total' => (int)$r->total, 'resolved' => (int)$r->resolved])->toArray();
    }

    private function violationBySeverity($from, $to, array $f): array
    {
        $q = DB::table('policy_violations')->whereBetween('created_at', [$from, $to]);
        if ($f['department_id']) $q->where('department_id', $f['department_id']);
        return $q->selectRaw("severity, COUNT(*) as count")->groupBy('severity')->orderByDesc('count')
            ->get()->map(fn($r) => ['severity' => $r->severity, 'count' => (int)$r->count])->toArray();
    }

    private function violationByOutcome($from, $to, array $f): array
    {
        $q = DB::table('policy_violations')->whereBetween('created_at', [$from, $to]);
        if ($f['department_id']) $q->where('department_id', $f['department_id']);
        return $q->selectRaw("outcome, COUNT(*) as count")->groupBy('outcome')->orderByDesc('count')
            ->get()->map(fn($r) => ['outcome' => $r->outcome, 'count' => (int)$r->count])->toArray();
    }

    private function attestationTrend($from, $to, array $f): array
    {
        return DB::table('policy_attestations')->whereBetween('created_at', [$from, $to])
            ->selectRaw("TO_CHAR(created_at,'YYYY-MM') as month, COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='overdue' THEN 1 ELSE 0 END) as overdue")
            ->groupByRaw("TO_CHAR(created_at,'YYYY-MM')")->orderByRaw("TO_CHAR(created_at,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'total' => (int)$r->total, 'completed' => (int)$r->completed, 'overdue' => (int)$r->overdue])->toArray();
    }

    private function attestationByStatus($from, $to, array $f): array
    {
        return DB::table('policy_attestations')->whereBetween('created_at', [$from, $to])
            ->selectRaw("status, COUNT(*) as count")->groupBy('status')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count])->toArray();
    }

    private function complianceByDept($from, $to, array $f): array
    {
        if ($f['department_id']) return [];
        return DB::table('policy_violations as v')
            ->join('company_units as cu', 'cu.id', '=', 'v.department_id')
            ->whereBetween('v.created_at', [$from, $to])
            ->selectRaw("cu.name as department, COUNT(*) as violations, SUM(CASE WHEN v.status='resolved' THEN 1 ELSE 0 END) as resolved")
            ->groupBy('cu.name')->orderByDesc('violations')->limit(10)
            ->get()->map(fn($r) => ['department' => $r->department, 'violations' => (int)$r->violations, 'resolved' => (int)$r->resolved])->toArray();
    }

    private function topViolatedPolicies($from, $to, array $f): array
    {
        $q = DB::table('policy_violations as v')
            ->join('policy as p', 'p.id', '=', 'v.policy_id')
            ->whereBetween('v.created_at', [$from, $to]);
        if ($f['department_id']) $q->where('v.department_id', $f['department_id']);
        return $q->selectRaw("p.name as policy_name, COUNT(*) as violations, SUM(CASE WHEN v.status='open' THEN 1 ELSE 0 END) as open_count")
            ->groupBy('p.name')->orderByDesc('violations')->limit(10)
            ->get()->map(fn($r) => ['policy_name' => $r->policy_name, 'violations' => (int)$r->violations, 'open_count' => (int)$r->open_count])->toArray();
    }

    private function openViolations(array $f): array
    {
        $q = DB::table('policy_violations as v')
            ->join('policy as p', 'p.id', '=', 'v.policy_id')
            ->join('users as u', 'u.id', '=', 'v.user_id')
            ->join('hr_people as hp', 'hp.user_id', '=', 'u.id')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'v.department_id')
            ->whereIn('v.status', ['open', 'under_investigation']);
        if ($f['department_id']) $q->where('v.department_id', $f['department_id']);
        return $q->selectRaw("p.name as policy_name, CONCAT(hp.first_name,' ',hp.surname) as employee, v.severity, v.outcome, v.status, v.created_at")
            ->orderByDesc('v.created_at')->limit(15)
            ->get()->map(fn($r) => (array)$r)->toArray();
    }
}
