<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DEI / fairness analytics — distribution of performance outcomes across
 * demographic cohorts (gender, ethnicity, disability) to surface potential
 * rating inequity. Complements the existing AI scoring-bias detection.
 */
class PaDeiController extends Controller
{
    public function index(Request $request): Response
    {
        $cycles  = PaCycle::orderByDesc('start_date')->get(['id', 'name']);
        $cycleId = (int) ($request->query('cycle_id') ?: $cycles->first()?->id);

        return Inertia::render('Modules/PerformanceAppraisal/Dei/Index', [
            'cycles'   => $cycles,
            'cycleId'  => $cycleId,
            'cohorts'  => [
                'gender'    => $this->cohort('gender', $cycleId),
                'ethnicity' => $this->cohort('ethnicity', $cycleId),
                'disability'=> $this->disabilityCohort($cycleId),
            ],
            'promotion' => $this->promotionEquity($cycleId),
        ]);
    }

    /** Average score + headcount + high/low-performer rate by a demographic column. */
    private function cohort(string $column, int $cycleId): array
    {
        $rows = DB::table('pa_performance_snapshots as s')
            ->join('hr_people as p', 'p.user_id', '=', 's.employee_id')
            ->where('s.cycle_id', $cycleId)
            ->groupBy("p.{$column}")
            ->select(
                "p.{$column} as cohort",
                DB::raw('count(*) as headcount'),
                DB::raw('avg(COALESCE(s.final_score, s.overall_score)) as avg_score'),
                DB::raw("sum(case when COALESCE(s.final_score, s.overall_score) >= 4 then 1 else 0 end) as high_count"),
                DB::raw("sum(case when COALESCE(s.final_score, s.overall_score) < 2 then 1 else 0 end) as low_count"),
            )
            ->get();

        return $rows->map(fn ($r) => [
            'cohort'    => $r->cohort ?: 'Not specified',
            'headcount' => (int) $r->headcount,
            'avg_score' => round((float) $r->avg_score, 2),
            'high_rate' => $r->headcount ? round($r->high_count / $r->headcount * 100, 1) : 0,
            'low_rate'  => $r->headcount ? round($r->low_count / $r->headcount * 100, 1) : 0,
        ])->values()->toArray();
    }

    private function disabilityCohort(int $cycleId): array
    {
        $rows = DB::table('pa_performance_snapshots as s')
            ->join('hr_people as p', 'p.user_id', '=', 's.employee_id')
            ->where('s.cycle_id', $cycleId)
            ->groupBy('p.disabled')
            ->select(
                'p.disabled as disabled',
                DB::raw('count(*) as headcount'),
                DB::raw('avg(COALESCE(s.final_score, s.overall_score)) as avg_score'),
            )
            ->get();

        return $rows->map(fn ($r) => [
            'cohort'    => $r->disabled ? 'Living with disability' : 'No disability',
            'headcount' => (int) $r->headcount,
            'avg_score' => round((float) $r->avg_score, 2),
        ])->values()->toArray();
    }

    /** Promotion-readiness representation by gender — equity of advancement. */
    private function promotionEquity(int $cycleId): array
    {
        return DB::table('pa_talent_profiles as t')
            ->join('hr_people as p', 'p.user_id', '=', 't.employee_id')
            ->where('t.cycle_id', $cycleId)
            ->groupBy('p.gender')
            ->select(
                'p.gender as cohort',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when t.promotion_readiness_level in ('ready_now','ready') then 1 else 0 end) as ready"),
            )
            ->get()
            ->map(fn ($r) => [
                'cohort'     => $r->cohort ?: 'Not specified',
                'total'      => (int) $r->total,
                'ready'      => (int) $r->ready,
                'ready_rate' => $r->total ? round($r->ready / $r->total * 100, 1) : 0,
            ])->values()->toArray();
    }
}
