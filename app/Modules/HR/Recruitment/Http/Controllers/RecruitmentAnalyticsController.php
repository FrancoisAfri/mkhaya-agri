<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RecruitmentAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $filters = [
            'department_id' => $request->input('department_id'),
            'source'        => $request->input('source'),
            'employment_type'      => $request->input('employment_type'),
            'status'        => $request->input('status'),
        ];

        $filterOptions = [
            'departments' => DB::table('company_units')->where('type', 'department')->select('id', 'name')->orderBy('name')
                ->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
            'sources'     => DB::table('recruitment_applications')->whereNotNull('source')->distinct()->pluck('source')->sort()->values()->toArray(),
            'employment_types'   => DB::table('recruitment_jobs')->whereNotNull('employment_type')->distinct()->pluck('employment_type')->sort()->values()->toArray(),
            'statuses'    => [['value' => '1', 'label' => 'Active'], ['value' => '0', 'label' => 'Closed']],
        ];

        return Inertia::render('Modules/Recruitment/Analytics/Index', [
            'period'              => $request->input('period', 'year'),
            'dateFrom'            => $request->input('date_from'),
            'dateTo'              => $request->input('date_to'),
            'filters'             => $filters,
            'filterOptions'       => $filterOptions,
            'kpis'                => $this->kpis($from, $to, $filters),
            'applicationTrend'    => $this->applicationTrend($from, $to, $filters),
            'funnelConversion'    => $this->funnelConversion($from, $to, $filters),
            'sourceEffectiveness' => $this->sourceEffectiveness($from, $to, $filters),
            'jobsByStatus'        => $this->jobsByStatus($from, $to, $filters),
            'timeToFillByDept'    => $this->timeToFillByDept($from, $to),
            'monthlyHires'        => $this->monthlyHires($from, $to, $filters),
            'offerConversion'     => $this->offerConversion($from, $to, $filters),
            'topOpenPositions'    => $this->topOpenPositions($filters),
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

    private function applyJobFilters($q, array $f, string $alias = 'j'): void
    {
        if ($f['department_id'])  $q->where("{$alias}.company_unit_id", $f['department_id']);
        if ($f['employment_type']) $q->where("{$alias}.employment_type", $f['employment_type']);
        if ($f['status'] !== null && $f['status'] !== '') $q->where("{$alias}.is_active", $f['status']);
    }

    private function kpis($from, $to, array $f): array
    {
        $jobQ = DB::table('recruitment_jobs as j')->whereBetween('j.created_at', [$from, $to]);
        $this->applyJobFilters($jobQ, $f);

        $totalJobs = (clone $jobQ)->count();
        $openJobs  = (clone $jobQ)->where('j.is_active', 1)->count();
        $filledJobs= (clone $jobQ)->where('j.is_active', 0)->count();

        $appQ = DB::table('recruitment_applications as a')
            ->join('recruitment_jobs as j', 'j.id', '=', 'a.job_id')
            ->whereBetween('a.created_at', [$from, $to]);
        $this->applyJobFilters($appQ, $f);
        if ($f['source']) $appQ->where('a.source', $f['source']);

        $totalApps = (clone $appQ)->count();
        $hired     = (clone $appQ)->where('a.status', 'hired')->count();
        $hireRate  = $totalApps > 0 ? round($hired / $totalApps * 100, 1) : 0;

        $avgTTF = DB::table('recruitment_jobs as j')
            ->whereBetween('j.created_at', [$from, $to])
            ->where('j.is_active', 0)->whereNotNull('j.closes_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (j.closes_at::timestamp - j.created_at))/86400) as avg_days')
            ->value('avg_days') ?? 0;

        $offerQ = DB::table('recruitment_offers as o')
            ->join('recruitment_applications as a', 'a.id', '=', 'o.application_id')
            ->join('recruitment_jobs as j', 'j.id', '=', 'a.job_id')
            ->whereBetween('o.created_at', [$from, $to]);
        $this->applyJobFilters($offerQ, $f);
        $totalOffers   = (clone $offerQ)->count();
        $acceptedOffers= (clone $offerQ)->where('o.status', 'accepted')->count();
        $offerAcceptRate = $totalOffers > 0 ? round($acceptedOffers / $totalOffers * 100, 1) : 0;

        // Avg time-to-hire: applied_at → stage_moved_at when hired
        $avgTTH = DB::table('recruitment_applications as a')
            ->join('recruitment_jobs as j', 'j.id', '=', 'a.job_id')
            ->where('a.status', 'hired')
            ->whereBetween('a.stage_moved_at', [$from, $to])
            ->whereNotNull('a.applied_at')
            ->whereNotNull('a.stage_moved_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (a.stage_moved_at - a.applied_at))/86400) as avg_days')
            ->value('avg_days') ?? 0;

        return [
            'total_jobs'         => $totalJobs,
            'open_jobs'          => $openJobs,
            'filled_jobs'        => $filledJobs,
            'total_applications' => $totalApps,
            'hired'              => $hired,
            'hire_rate_pct'      => $hireRate,
            'avg_days_to_fill'   => round($avgTTF, 0),
            'avg_days_to_hire'   => round($avgTTH, 0),
            'offer_accept_rate'  => $offerAcceptRate,
        ];
    }

    private function applicationTrend($from, $to, array $f): array
    {
        $q = DB::table('recruitment_applications as a')
            ->join('recruitment_jobs as j', 'j.id', '=', 'a.job_id')
            ->whereBetween('a.created_at', [$from, $to]);
        $this->applyJobFilters($q, $f);
        if ($f['source']) $q->where('a.source', $f['source']);
        return $q->selectRaw("TO_CHAR(a.created_at,'YYYY-MM') as month, COUNT(*) as total, SUM(CASE WHEN a.status='hired' THEN 1 ELSE 0 END) as hired")
            ->groupByRaw("TO_CHAR(a.created_at,'YYYY-MM')")->orderByRaw("TO_CHAR(a.created_at,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'total' => (int)$r->total, 'hired' => (int)$r->hired])->toArray();
    }

    private function funnelConversion($from, $to, array $f): array
    {
        $stages = ['applied','screening','interview','offer','hired'];
        $q = DB::table('recruitment_applications as a')
            ->join('recruitment_jobs as j', 'j.id', '=', 'a.job_id')
            ->whereBetween('a.created_at', [$from, $to]);
        $this->applyJobFilters($q, $f);
        if ($f['source']) $q->where('a.source', $f['source']);

        $counts = (clone $q)->selectRaw('status, COUNT(*) as count')->groupBy('status')
            ->get()->keyBy('status');

        return collect($stages)->map(fn($s) => ['stage' => $s, 'count' => (int)($counts[$s]->count ?? 0)])->toArray();
    }

    private function sourceEffectiveness($from, $to, array $f): array
    {
        $q = DB::table('recruitment_applications as a')
            ->join('recruitment_jobs as j', 'j.id', '=', 'a.job_id')
            ->whereBetween('a.created_at', [$from, $to])->whereNotNull('a.source');
        $this->applyJobFilters($q, $f);
        return $q->selectRaw("a.source, COUNT(*) as total, SUM(CASE WHEN a.status='hired' THEN 1 ELSE 0 END) as hired, ROUND(SUM(CASE WHEN a.status='hired' THEN 1 ELSE 0 END)::numeric / NULLIF(COUNT(*),0) * 100, 1) as hire_rate")
            ->groupBy('a.source')->orderByDesc('total')->limit(10)
            ->get()->map(fn($r) => ['source' => $r->source, 'total' => (int)$r->total, 'hired' => (int)$r->hired, 'hire_rate' => (float)$r->hire_rate])->toArray();
    }

    private function jobsByStatus($from, $to, array $f): array
    {
        $q = DB::table('recruitment_jobs as j')->whereBetween('j.created_at', [$from, $to]);
        $this->applyJobFilters($q, $f);
        return $q->selectRaw("CASE WHEN j.is_active THEN 'Active' ELSE 'Closed' END as status, COUNT(*) as count")
            ->groupByRaw('j.is_active')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count])->toArray();
    }

    private function timeToFillByDept($from, $to): array
    {
        return DB::table('recruitment_jobs as j')
            ->join('company_units as cu', 'cu.id', '=', 'j.company_unit_id')
            ->whereBetween('j.created_at', [$from, $to])->where('j.is_active', 0)->whereNotNull('j.closes_at')
            ->selectRaw("cu.name as department, ROUND(AVG(EXTRACT(EPOCH FROM (j.closes_at::timestamp - j.created_at))/86400)::numeric, 0) as avg_days, COUNT(*) as filled")
            ->groupBy('cu.name')->orderByDesc('avg_days')->limit(10)
            ->get()->map(fn($r) => ['department' => $r->department, 'avg_days' => (float)$r->avg_days, 'filled' => (int)$r->filled])->toArray();
    }

    private function monthlyHires($from, $to, array $f): array
    {
        $q = DB::table('recruitment_applications as a')
            ->join('recruitment_jobs as j', 'j.id', '=', 'a.job_id')
            ->where('a.status', 'hired')->whereBetween('a.updated_at', [$from, $to]);
        $this->applyJobFilters($q, $f);
        return $q->selectRaw("TO_CHAR(a.updated_at,'YYYY-MM') as month, COUNT(*) as hires")
            ->groupByRaw("TO_CHAR(a.updated_at,'YYYY-MM')")->orderByRaw("TO_CHAR(a.updated_at,'YYYY-MM')")
            ->get()->map(fn($r) => ['month' => $r->month, 'hires' => (int)$r->hires])->toArray();
    }

    private function offerConversion($from, $to, array $f): array
    {
        $q = DB::table('recruitment_offers as o')
            ->join('recruitment_applications as a', 'a.id', '=', 'o.application_id')
            ->join('recruitment_jobs as j', 'j.id', '=', 'a.job_id')
            ->whereBetween('o.created_at', [$from, $to]);
        $this->applyJobFilters($q, $f);
        return $q->selectRaw("o.status, COUNT(*) as count")
            ->groupBy('o.status')->orderByDesc('count')
            ->get()->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count])->toArray();
    }

    private function topOpenPositions(array $f): array
    {
        $q = DB::table('recruitment_jobs as j')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'j.company_unit_id')
            ->where('j.is_active', 1);
        $this->applyJobFilters($q, $f);
        return $q->selectRaw("j.id, j.title, COALESCE(cu.name,'—') as department, j.employment_type, j.created_at, (SELECT COUNT(*) FROM recruitment_applications WHERE job_id = j.id) as applications")
            ->orderByDesc('applications')->limit(10)
            ->get()->map(fn($r) => (array)$r)->toArray();
    }
}
