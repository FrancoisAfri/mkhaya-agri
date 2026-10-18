<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $filters = [
            'cycle_id'      => $request->input('cycle_id'),
            'department_id' => $request->input('department_id'),
        ];

        $filterOptions = [
            'cycles'      => DB::table('pa_cycles')->select('id', 'name')->orderByDesc('id')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'departments' => DB::table('company_units')->where('type', 'department')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
        ];

        return Inertia::render('Modules/PerformanceAppraisal/Analytics/Index', [
            'period'            => $request->input('period', 'year'),
            'dateFrom'          => $request->input('date_from'),
            'dateTo'            => $request->input('date_to'),
            'filters'           => $filters,
            'filterOptions'     => $filterOptions,
            'kpis'              => $this->kpis($from, $to, $filters),
            'scoreDistribution' => $this->scoreDistribution($from, $to, $filters),
            'deptPerformance'   => $this->deptPerformance($from, $to, $filters),
            'scoreTrend'        => $this->scoreTrend($from, $to, $filters),
            'kpiCompletion'     => $this->kpiCompletion($from, $to, $filters),
            'pipStatus'         => $this->pipStatus($from, $to, $filters),
            'feedbackTrend'     => $this->feedbackTrend($from, $to, $filters),
            'goalProgress'      => $this->goalProgress($from, $to, $filters),
            'topPerformers'     => $this->topPerformers($from, $to, $filters),
            'pipList'           => $this->pipList($from, $to, $filters),
            'probationSummary'  => $this->probationSummary($from, $to),
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

    private function applyFilters($q, array $f, string $scoreAlias = 's'): void
    {
        if ($f['cycle_id']) $q->where("{$scoreAlias}.cycle_id", $f['cycle_id']);
        if ($f['department_id']) {
            $empIds = DB::table('company_unit_user_pivots')->where('company_id', $f['department_id'])->pluck('user_id');
            $q->whereIn("{$scoreAlias}.employee_id", $empIds);
        }
    }

    private function kpis($from, $to, array $f): array
    {
        $sQ = DB::table('pa_performance_snapshots as s')->whereBetween('s.created_at', [$from, $to]);
        $this->applyFilters($sQ, $f);

        $totalReviewed  = (clone $sQ)->count();
        $avgScore       = (clone $sQ)->avg('s.overall_score') ?? 0;
        $highPerformers = (clone $sQ)->where('s.overall_score', '>=', 4)->count();
        $lowPerformers  = (clone $sQ)->where('s.overall_score', '<', 2)->count();

        $pipQ = DB::table('pa_pip_plans')->whereBetween('created_at', [$from, $to]);
        $pipCount  = $pipQ->count();
        $pipActive = (clone $pipQ)->where('status', 'active')->count();

        $kpiQ = DB::table('pa_employee_kpis as k')->whereBetween('k.created_at', [$from, $to]);
        if ($f['cycle_id']) $kpiQ->where('k.cycle_id', $f['cycle_id']);
        $totalKpis    = $kpiQ->count();
        $completedKpis= (clone $kpiQ)->where('k.status', 'completed')->count();

        $feedbackCount = DB::table('pa_feedback')->whereBetween('created_at', [$from, $to])->count();

        return [
            'total_reviewed'       => $totalReviewed,
            'avg_score'            => round($avgScore, 2),
            'high_performers'      => $highPerformers,
            'high_performer_pct'   => $totalReviewed > 0 ? round($highPerformers / $totalReviewed * 100, 1) : 0,
            'low_performers'       => $lowPerformers,
            'pip_count'            => $pipCount,
            'pip_active'           => $pipActive,
            'kpi_completion_pct'   => $totalKpis > 0 ? round($completedKpis / $totalKpis * 100, 1) : 0,
            'feedback_count'       => $feedbackCount,
        ];
    }

    private function scoreDistribution($from, $to, array $f): array
    {
        $q = DB::table('pa_performance_snapshots as s')->whereBetween('s.created_at', [$from, $to]);
        $this->applyFilters($q, $f);
        return $q->selectRaw("FLOOR(s.overall_score)::int as score_band, COUNT(*) as count")
            ->groupByRaw('FLOOR(s.overall_score)')->orderBy('score_band')
            ->get()->map(fn($r) => ['band' => (int)$r->score_band, 'label' => $r->score_band . '.x', 'count' => (int)$r->count])->toArray();
    }

    private function deptPerformance($from, $to, array $f): array
    {
        $q = DB::table('pa_performance_snapshots as s')
            ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 's.employee_id')
            ->join('company_units as cu', function ($j) { $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'); })
            ->whereBetween('s.created_at', [$from, $to]);
        if ($f['cycle_id']) $q->where('s.cycle_id', $f['cycle_id']);
        return $q->selectRaw("cu.name as department, ROUND(AVG(s.overall_score)::numeric, 2) as avg_score, COUNT(*) as reviewed")
            ->groupBy('cu.name')->orderByDesc('avg_score')->limit(12)
            ->get()->map(fn($r) => ['department' => $r->department, 'avg_score' => (float)$r->avg_score, 'reviewed' => (int)$r->reviewed])->toArray();
    }

    private function scoreTrend($from, $to, array $f): array
    {
        $q = DB::table('pa_performance_snapshots as s')->whereBetween('s.created_at', [$from, $to]);
        $this->applyFilters($q, $f);
        return $q->selectRaw("TO_CHAR(s.created_at,'YYYY-MM') as month, ROUND(AVG(s.overall_score)::numeric,2) as avg_score, COUNT(*) as count")
            ->groupByRaw("TO_CHAR(s.created_at,'YYYY-MM')")->orderByRaw("TO_CHAR(s.created_at,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'avg_score' => (float)$r->avg_score, 'count' => (int)$r->count])->toArray();
    }

    private function kpiCompletion($from, $to, array $f): array
    {
        $q = DB::table('pa_employee_kpis as k')
            ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'k.employee_id')
            ->join('company_units as cu', function ($j) { $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'); })
            ->whereBetween('k.created_at', [$from, $to]);
        if ($f['cycle_id']) $q->where('k.cycle_id', $f['cycle_id']);
        if ($f['department_id']) $q->where('cup.company_id', $f['department_id']);
        return $q->selectRaw("cu.name as department, COUNT(*) as total, SUM(CASE WHEN k.status='completed' THEN 1 ELSE 0 END) as completed, ROUND(SUM(CASE WHEN k.status='completed' THEN 1 ELSE 0 END)::numeric/NULLIF(COUNT(*),0)*100,1) as completion_pct")
            ->groupBy('cu.name')->orderByDesc('completion_pct')->limit(10)
            ->get()->map(fn($r) => ['department' => $r->department, 'total' => (int)$r->total, 'completed' => (int)$r->completed, 'completion_pct' => (float)$r->completion_pct])->toArray();
    }

    private function pipStatus($from, $to, array $f): array
    {
        $q = DB::table('pa_pip_plans')->whereBetween('created_at', [$from, $to]);
        return $q->selectRaw("status, COUNT(*) as count")
            ->groupBy('status')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count])->toArray();
    }

    private function feedbackTrend($from, $to, array $f): array
    {
        $q = DB::table('pa_feedback')->whereBetween('created_at', [$from, $to]);
        if ($f['cycle_id']) $q->where('cycle_id', $f['cycle_id']);
        return $q->selectRaw("TO_CHAR(created_at,'YYYY-MM') as month, COUNT(*) as count, ROUND(AVG(rating)::numeric,2) as avg_rating")
            ->groupByRaw("TO_CHAR(created_at,'YYYY-MM')")->orderByRaw("TO_CHAR(created_at,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'count' => (int)$r->count, 'avg_rating' => (float)$r->avg_rating])->toArray();
    }

    private function goalProgress($from, $to, array $f): array
    {
        $q = DB::table('pa_goals')->whereBetween('created_at', [$from, $to]);
        if ($f['cycle_id']) $q->where('cycle_id', $f['cycle_id']);
        if ($f['department_id']) $q->where('owner_unit_id', $f['department_id']);
        return $q->selectRaw("status, COUNT(*) as count, ROUND(AVG(CASE WHEN target_value > 0 THEN current_value::numeric / NULLIF(target_value, 0) * 100 ELSE 0 END)::numeric, 1) as avg_progress")
            ->groupBy('status')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count, 'avg_progress' => (float)$r->avg_progress])->toArray();
    }

    private function topPerformers($from, $to, array $f): array
    {
        $q = DB::table('pa_performance_snapshots as s')
            ->join('hr_people as p', 'p.user_id', '=', 's.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 's.employee_id')
            ->leftJoin('company_units as cu', function ($j) { $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'); })
            ->whereBetween('s.created_at', [$from, $to]);
        $this->applyFilters($q, $f);
        return $q->selectRaw("CONCAT(p.first_name,' ',p.surname) as name, COALESCE(cu.name,'—') as department, ROUND(s.overall_score::numeric,2) as score")
            ->orderByDesc('s.overall_score')->limit(10)
            ->get()->map(fn($r) => (array)$r)->toArray();
    }

    private function pipList($from, $to, array $f): array
    {
        $q = DB::table('pa_pip_plans as pip')
            ->join('hr_people as p', 'p.user_id', '=', 'pip.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'pip.employee_id')
            ->leftJoin('company_units as cu', function ($j) { $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'); })
            ->whereBetween('pip.created_at', [$from, $to]);
        if ($f['department_id']) $q->where('cup.company_id', $f['department_id']);
        return $q->selectRaw("CONCAT(p.first_name,' ',p.surname) as name, COALESCE(cu.name,'—') as department, pip.status, pip.start_date, pip.target_end_date as end_date, pip.reason")
            ->orderByDesc('pip.created_at')->limit(15)
            ->get()->map(fn($r) => (array)$r)->toArray();
    }

    private function probationSummary($from, $to): array
    {
        return DB::table('pa_probation_reviews')->whereBetween('created_at', [$from, $to])
            ->selectRaw("status, COUNT(*) as count")->groupBy('status')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count])->toArray();
    }
}
