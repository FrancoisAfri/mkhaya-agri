<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use App\Modules\PerformanceAppraisal\Models\PaKpiScore;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaKpiScoreService
{
    /**
     * Calculate and persist score for a single KPI using its latest progress log value.
     */
    public function calculateKpiScore(PaEmployeeKpi $kpi, int $scoredBy): PaKpiScore
    {
        $achievedValue = $kpi->latestValue();

        $scorePct = null;
        $weightedScore = null;

        if ($achievedValue !== null && $kpi->target_value && $kpi->target_value > 0) {
            $scorePct      = min(100, round(($achievedValue / $kpi->target_value) * 100, 2));
            $weightedScore = round($scorePct * ($kpi->weighting / 100), 2);
        }

        return PaKpiScore::updateOrCreate(
            ['employee_kpi_id' => $kpi->id],
            [
                'achieved_value' => $achievedValue,
                'score_pct'      => $scorePct,
                'weighted_score' => $weightedScore,
                'scored_by'      => $scoredBy,
                'scored_at'      => now(),
            ]
        );
    }

    /**
     * Score all KPIs for an employee in a cycle.
     */
    public function scoreEmployeeCycle(int $employeeId, PaCycle $cycle, int $scoredBy): array
    {
        $kpis = PaEmployeeKpi::where('cycle_id', $cycle->id)
            ->where('employee_id', $employeeId)
            ->get();

        $scores = $kpis->map(fn($kpi) => $this->calculateKpiScore($kpi, $scoredBy));

        $this->takeSnapshot($employeeId, $cycle);

        return [
            'kpi_count'     => $kpis->count(),
            'overall_score' => round($scores->sum('weighted_score'), 2),
        ];
    }

    /**
     * Take a performance snapshot for an employee in a cycle.
     */
    public function takeSnapshot(int $employeeId, PaCycle $cycle): PaPerformanceSnapshot
    {
        $kpis = PaEmployeeKpi::where('cycle_id', $cycle->id)
            ->where('employee_id', $employeeId)
            ->with(['score', 'progressLogs'])
            ->get();

        $withValues = $kpis->filter(fn($k) => $k->latestValue() !== null);
        $completionRate = $kpis->count() > 0
            ? round(($withValues->count() / $kpis->count()) * 100, 1)
            : 0;

        $overallScore = $kpis->sum(fn($k) => $k->score?->weighted_score ?? 0);

        $breakdown = $kpis->map(fn($k) => [
            'kpi_id'         => $k->id,
            'title'          => $k->title,
            'target'         => $k->target_value,
            'achieved'       => $k->latestValue(),
            'score_pct'      => $k->score?->score_pct,
            'weighted_score' => $k->score?->weighted_score,
            'weighting'      => $k->weighting,
        ])->values()->toArray();

        return PaPerformanceSnapshot::updateOrCreate(
            ['employee_id' => $employeeId, 'cycle_id' => $cycle->id, 'snapshot_date' => now()->toDateString()],
            [
                'overall_score'       => round($overallScore, 2),
                'kpi_completion_rate' => $completionRate,
                'data'                => $breakdown,
            ]
        );
    }

    /**
     * Generate a KPI completion report for a cycle.
     */
    public function generateKpiCompletionReport(PaCycle $cycle): array
    {
        return DB::table('pa_employee_kpis as k')
            ->leftJoin('pa_kpi_scores as s', 's.employee_kpi_id', '=', 'k.id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'k.employee_id')
            ->where('k.cycle_id', $cycle->id)
            ->select(
                'k.employee_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(p.first_name,' ',p.surname)),''), CAST(k.employee_id AS TEXT)) as employee_name"),
                DB::raw('COUNT(k.id) as total_kpis'),
                DB::raw('COUNT(s.id) as scored_kpis'),
                DB::raw('ROUND(AVG(s.score_pct)::numeric, 1) as avg_score_pct'),
                DB::raw('ROUND(SUM(s.weighted_score)::numeric, 2) as total_weighted_score')
            )
            ->groupBy('k.employee_id', 'p.first_name', 'p.surname')
            ->orderByDesc('total_weighted_score')
            ->get()
            ->map(fn($r) => [
                'employee_id'          => $r->employee_id,
                'employee_name'        => $r->employee_name,
                'total_kpis'           => (int) $r->total_kpis,
                'scored_kpis'          => (int) $r->scored_kpis,
                'avg_score_pct'        => (float) $r->avg_score_pct,
                'total_weighted_score' => (float) $r->total_weighted_score,
            ])
            ->toArray();
    }
}
