<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AssetAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $filters = [
            'category_id' => $request->input('category_id'),
            'location_id' => $request->input('location_id'),
            'status'      => $request->input('status'),
        ];

        $filterOptions = [
            'categories' => DB::table('asset_categories')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'locations'  => DB::table('asset_locations')->select('id', 'name')->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'statuses'   => DB::table('assets')->whereNull('deleted_at')->distinct()->pluck('asset_status')->filter()->sort()->values()->toArray(),
        ];

        return Inertia::render('Modules/Assets/Analytics/Index', [
            'period'        => $request->input('period', 'year'),
            'dateFrom'      => $request->input('date_from'),
            'dateTo'        => $request->input('date_to'),
            'filters'       => $filters,
            'filterOptions' => $filterOptions,
            'kpis'          => $this->kpis($from, $to, $filters),
            'byCategory'    => $this->byCategory($filters),
            'byStatus'      => $this->byStatus($filters),
            'byLocation'    => $this->byLocation($filters),
            'valueTrend'    => $this->valueTrend($from, $to, $filters),
            'maintenanceCostTrend' => $this->maintenanceCostTrend($from, $to, $filters),
            'maintenanceByType'   => $this->maintenanceByType($from, $to, $filters),
            'incidentsByType'     => $this->incidentsByType($from, $to, $filters),
            'depreciationByCategory' => $this->depreciationByCategory($filters),
            'assignmentTrend'     => $this->assignmentTrend($from, $to),
            'topValueAssets'      => $this->topValueAssets($filters),
            'mostMaintained'      => $this->mostMaintained($from, $to, $filters),
        ]);
    }

    // ── Date range ────────────────────────────────────────────────────────────

    private function dateRange(Request $request): array
    {
        if ($request->filled('date_from') && $request->filled('date_to')) {
            return [
                now()->parse($request->input('date_from'))->startOfDay(),
                now()->parse($request->input('date_to'))->endOfDay(),
            ];
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

    private function applyFilters($query, array $f, string $alias = ''): void
    {
        $col = $alias ? "{$alias}." : '';
        if ($f['category_id']) $query->where("{$col}asset_category_id", $f['category_id']);
        if ($f['location_id']) $query->where("{$col}location_id", $f['location_id']);
        if ($f['status'])      $query->where("{$col}asset_status", $f['status']);
    }

    // ── KPIs ─────────────────────────────────────────────────────────────────

    private function kpis($from, $to, array $f): array
    {
        $base = DB::table('assets')->whereNull('deleted_at');
        $this->applyFilters($base, $f);

        $total        = (clone $base)->count();
        $totalValue   = (clone $base)->sum('purchase_cost') ?? 0;
        $bookValue    = (clone $base)->sum('current_value') ?? 0;
        $assigned     = (clone $base)->where('asset_status', 'assigned')->count();
        $avgAge       = (clone $base)->whereNotNull('purchase_date')
            ->selectRaw("AVG(EXTRACT(YEAR FROM AGE(purchase_date::date))) as avg_age")->value('avg_age') ?? 0;

        $maintBase = DB::table('asset_maintenances')->whereBetween('created_at', [$from, $to]);
        if ($f['category_id']) $maintBase->whereIn('asset_id', DB::table('assets')->where('asset_category_id', $f['category_id'])->pluck('id'));
        $maintCost  = $maintBase->sum('cost') ?? 0;
        $maintCount = $maintBase->count();

        $incBase = DB::table('asset_incidents')->whereBetween('created_at', [$from, $to]);
        if ($f['category_id']) $incBase->whereIn('asset_id', DB::table('assets')->where('asset_category_id', $f['category_id'])->pluck('id'));
        $incidents  = $incBase->count();

        return [
            'total_assets'    => $total,
            'total_value'     => round($totalValue, 2),
            'book_value'      => round($bookValue, 2),
            'assigned_count'  => $assigned,
            'utilization_pct' => $total > 0 ? round($assigned / $total * 100, 1) : 0,
            'avg_age_years'   => round($avgAge, 1),
            'maintenance_cost'=> round($maintCost, 2),
            'maintenance_count'=> $maintCount,
            'incidents'       => $incidents,
        ];
    }

    private function byCategory(array $f): array
    {
        $q = DB::table('assets as a')->join('asset_categories as c', 'c.id', '=', 'a.asset_category_id')
            ->whereNull('a.deleted_at');
        if ($f['location_id']) $q->where('a.location_id', $f['location_id']);
        if ($f['status'])      $q->where('a.asset_status', $f['status']);
        return $q->selectRaw('c.name as category, COUNT(a.id) as count, ROUND(SUM(a.purchase_cost)::numeric,2) as total_value')
            ->groupBy('c.name')->orderByDesc('count')->limit(12)
            ->get()->map(fn($r) => ['category' => $r->category, 'count' => (int)$r->count, 'total_value' => (float)$r->total_value])->toArray();
    }

    private function byStatus(array $f): array
    {
        $q = DB::table('assets')->whereNull('deleted_at');
        $this->applyFilters($q, $f);
        return $q->selectRaw('asset_status as status, COUNT(*) as count')->groupBy('asset_status')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count])->toArray();
    }

    private function byLocation(array $f): array
    {
        $q = DB::table('assets as a')->leftJoin('asset_locations as l', 'l.id', '=', 'a.location_id')
            ->whereNull('a.deleted_at');
        if ($f['category_id']) $q->where('a.asset_category_id', $f['category_id']);
        if ($f['status'])      $q->where('a.asset_status', $f['status']);
        return $q->selectRaw("COALESCE(l.name, 'Unassigned') as location, COUNT(a.id) as count")
            ->groupBy('l.name')->orderByDesc('count')->limit(10)
            ->get()->map(fn($r) => ['location' => $r->location, 'count' => (int)$r->count])->toArray();
    }

    private function valueTrend($from, $to, array $f): array
    {
        $q = DB::table('assets')->whereNull('deleted_at')->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyFilters($q, $f);
        return $q->selectRaw("TO_CHAR(purchase_date, 'YYYY-MM') as month, COUNT(*) as count, ROUND(SUM(purchase_cost)::numeric,2) as value")
            ->groupByRaw("TO_CHAR(purchase_date, 'YYYY-MM')")->orderByRaw("TO_CHAR(purchase_date, 'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'count' => (int)$r->count, 'value' => (float)$r->value])->toArray();
    }

    private function maintenanceCostTrend($from, $to, array $f): array
    {
        $q = DB::table('asset_maintenances as m')->join('assets as a', 'a.id', '=', 'm.asset_id')
            ->whereNull('a.deleted_at')->whereBetween('m.created_at', [$from, $to]);
        if ($f['category_id']) $q->where('a.asset_category_id', $f['category_id']);
        if ($f['location_id']) $q->where('a.location_id', $f['location_id']);
        return $q->selectRaw("TO_CHAR(m.created_at, 'YYYY-MM') as month, COUNT(m.id) as jobs, ROUND(SUM(m.cost)::numeric,2) as cost")
            ->groupByRaw("TO_CHAR(m.created_at, 'YYYY-MM')")->orderByRaw("TO_CHAR(m.created_at, 'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'jobs' => (int)$r->jobs, 'cost' => (float)$r->cost])->toArray();
    }

    private function maintenanceByType($from, $to, array $f): array
    {
        $q = DB::table('asset_maintenances as m')->join('assets as a', 'a.id', '=', 'm.asset_id')
            ->whereNull('a.deleted_at')->whereBetween('m.created_at', [$from, $to]);
        if ($f['category_id']) $q->where('a.asset_category_id', $f['category_id']);
        return $q->selectRaw("COALESCE(m.maintenance_type, 'other') as type, COUNT(*) as count, ROUND(SUM(m.cost)::numeric,2) as cost")
            ->groupBy(DB::raw("COALESCE(m.maintenance_type, 'other')"))->orderByDesc('cost')
            ->get()->map(fn($r) => ['type' => $r->type, 'count' => (int)$r->count, 'cost' => (float)$r->cost])->toArray();
    }

    private function incidentsByType($from, $to, array $f): array
    {
        $q = DB::table('asset_incidents as i')->join('assets as a', 'a.id', '=', 'i.asset_id')
            ->whereNull('a.deleted_at')->whereBetween('i.created_at', [$from, $to]);
        if ($f['category_id']) $q->where('a.asset_category_id', $f['category_id']);
        return $q->selectRaw("i.title as type, i.severity, COUNT(*) as count")
            ->groupBy('i.title', 'i.severity')->orderByDesc('count')
            ->get()->map(fn($r) => ['type' => $r->type, 'severity' => $r->severity, 'count' => (int)$r->count])->toArray();
    }

    private function depreciationByCategory(array $f): array
    {
        $q = DB::table('assets as a')->join('asset_categories as c', 'c.id', '=', 'a.asset_category_id')
            ->whereNull('a.deleted_at')->whereNotNull('a.purchase_cost')->whereNotNull('a.current_value');
        if ($f['location_id']) $q->where('a.location_id', $f['location_id']);
        if ($f['status'])      $q->where('a.asset_status', $f['status']);
        return $q->selectRaw('c.name as category, ROUND(SUM(a.purchase_cost)::numeric,2) as original_value, ROUND(SUM(a.current_value)::numeric,2) as book_value, ROUND((1 - SUM(a.current_value)/NULLIF(SUM(a.purchase_cost),0))*100, 1) as depreciation_pct')
            ->groupBy('c.name')->orderByDesc('original_value')->limit(10)
            ->get()->map(fn($r) => ['category' => $r->category, 'original_value' => (float)$r->original_value, 'book_value' => (float)$r->book_value, 'depreciation_pct' => (float)$r->depreciation_pct])->toArray();
    }

    private function assignmentTrend($from, $to): array
    {
        return DB::table('asset_assignments')->whereBetween('created_at', [$from, $to])
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, COUNT(*) as assignments")
            ->groupByRaw("TO_CHAR(created_at, 'YYYY-MM')")->orderByRaw("TO_CHAR(created_at, 'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'assignments' => (int)$r->assignments])->toArray();
    }

    private function topValueAssets(array $f): array
    {
        $q = DB::table('assets as a')->leftJoin('asset_categories as c', 'c.id', '=', 'a.asset_category_id')
            ->whereNull('a.deleted_at')->whereNotNull('a.purchase_cost');
        $this->applyFilters($q, $f, 'a');
        return $q->selectRaw('a.id, a.asset_number, a.name, c.name as category, a.asset_status as status, ROUND(a.purchase_cost::numeric,2) as purchase_cost, ROUND(a.current_value::numeric,2) as current_value')
            ->orderByDesc('a.purchase_cost')->limit(10)
            ->get()->map(fn($r) => (array)$r)->toArray();
    }

    private function mostMaintained($from, $to, array $f): array
    {
        $q = DB::table('asset_maintenances as m')->join('assets as a', 'a.id', '=', 'm.asset_id')
            ->whereNull('a.deleted_at')->whereBetween('m.created_at', [$from, $to]);
        if ($f['category_id']) $q->where('a.asset_category_id', $f['category_id']);
        return $q->selectRaw('a.asset_number, a.name, COUNT(m.id) as jobs, ROUND(SUM(m.cost)::numeric,2) as total_cost')
            ->groupBy('a.asset_number', 'a.name')->orderByDesc('total_cost')->limit(10)
            ->get()->map(fn($r) => ['asset_number' => $r->asset_number, 'name' => $r->name, 'jobs' => (int)$r->jobs, 'total_cost' => (float)$r->total_cost])->toArray();
    }
}
