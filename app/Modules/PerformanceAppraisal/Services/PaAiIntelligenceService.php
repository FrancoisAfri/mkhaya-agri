<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\PerformanceAppraisal\Models\PaAiInsight;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use App\Modules\PerformanceAppraisal\Models\PaKpiScore;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaAiIntelligenceService
{
    public function __construct(protected AgentRegistry $agents) {}

    /**
     * Run the full analysis engine for a cycle — generates AI insights for all employees.
     */
    public function analyzePerformanceTrends(PaCycle $cycle): array
    {
        $snapshots = PaPerformanceSnapshot::where('cycle_id', $cycle->id)
            ->orderBy('employee_id')
            ->orderBy('snapshot_date')
            ->get();

        if ($snapshots->isEmpty()) {
            return ['insights_generated' => 0, 'message' => 'No snapshots available for this cycle.'];
        }

        $byEmployee = $snapshots->groupBy('employee_id');
        $count = 0;

        foreach ($byEmployee as $employeeId => $employeeSnapshots) {
            $context = $this->buildEmployeeContext($employeeId, $cycle, $employeeSnapshots);

            try {
                $result = $this->askAiForInsights($employeeId, $cycle->id, $context);
                $count += count($result);
            } catch (\Throwable $e) {
                Log::warning("PA AI: failed to analyse employee {$employeeId}", ['error' => $e->getMessage()]);
                // Fall back to rule-based detection
                $count += $this->runRuleBasedDetection($employeeId, $cycle, $employeeSnapshots);
            }
        }

        return ['insights_generated' => $count];
    }

    /**
     * Rule-based detection for declining performance.
     */
    public function detectDecliningPerformance(PaCycle $cycle): int
    {
        $snapshots = PaPerformanceSnapshot::where('cycle_id', $cycle->id)
            ->orderBy('employee_id')
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy('employee_id');

        $count = 0;
        foreach ($snapshots as $employeeId => $snaps) {
            if ($snaps->count() < 2) continue;

            $first = $snaps->first()->overall_score;
            $last  = $snaps->last()->overall_score;

            if ($last !== null && $first !== null && ($first - $last) >= 15) {
                $declineAmt = round($first - $last, 1);
                $this->upsertInsight($employeeId, $cycle->id, 'declining_performance', 'warning',
                    'Performance declining this cycle',
                    "Score dropped from {$first}% to {$last}% — a {$declineAmt}% decline.",
                    ['first_score' => $first, 'last_score' => $last]
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Rule-based detection for exceptional performers.
     */
    public function detectExceptionalPerformance(PaCycle $cycle): int
    {
        $count = 0;

        $topScorers = DB::table('pa_kpi_scores as s')
            ->join('pa_employee_kpis as k', 'k.id', '=', 's.employee_kpi_id')
            ->where('k.cycle_id', $cycle->id)
            ->groupBy('k.employee_id')
            ->having(DB::raw('AVG(s.score_pct)'), '>=', 90)
            ->select('k.employee_id', DB::raw('ROUND(AVG(s.score_pct)::numeric,1) as avg'))
            ->get();

        foreach ($topScorers as $row) {
            $this->upsertInsight($row->employee_id, $cycle->id, 'exceptional_performance', 'info',
                'Exceptional performance this cycle',
                "Average KPI achievement: {$row->avg}% — consistently above 90% threshold.",
                ['avg_score_pct' => (float) $row->avg]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Detect managers whose scoring deviates significantly from the team average.
     */
    public function detectManagerScoringBias(PaCycle $cycle): int
    {
        $count = 0;

        // Get all scored KPIs, grouped by manager (creator)
        $managerScores = DB::table('pa_kpi_scores as s')
            ->join('pa_employee_kpis as k', 'k.id', '=', 's.employee_kpi_id')
            ->where('k.cycle_id', $cycle->id)
            ->whereNotNull('s.scored_by')
            ->groupBy('s.scored_by')
            ->select(
                's.scored_by as manager_id',
                DB::raw('ROUND(AVG(s.score_pct)::numeric,1) as avg_score'),
                DB::raw('ROUND(STDDEV(s.score_pct)::numeric,1) as std_dev'),
                DB::raw('COUNT(*) as total')
            )
            ->get();

        if ($managerScores->count() < 2) return 0;

        $globalAvg = $managerScores->avg('avg_score');

        foreach ($managerScores as $row) {
            $deviation = abs($row->avg_score - $globalAvg);
            if ($deviation >= 20 && $row->total >= 3) {
                $direction = $row->avg_score > $globalAvg ? 'lenient' : 'strict';
                $this->upsertInsight($row->manager_id, $cycle->id, 'scoring_bias', 'warning',
                    "Potential scoring bias detected ({$direction})",
                    "Manager's average score ({$row->avg_score}%) deviates {$deviation}% from organisation average ({$globalAvg}%).",
                    ['manager_avg' => $row->avg_score, 'global_avg' => $globalAvg, 'direction' => $direction]
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Detect inconsistent scoring (high variance for a single manager/employee).
     */
    public function detectInconsistentScoring(PaCycle $cycle): int
    {
        $count = 0;

        $scores = DB::table('pa_kpi_scores as s')
            ->join('pa_employee_kpis as k', 'k.id', '=', 's.employee_kpi_id')
            ->where('k.cycle_id', $cycle->id)
            ->groupBy('k.employee_id')
            ->select(
                'k.employee_id',
                DB::raw('ROUND(STDDEV(s.score_pct)::numeric,1) as std_dev'),
                DB::raw('COUNT(*) as total')
            )
            ->having(DB::raw('STDDEV(s.score_pct)'), '>', 30)
            ->having(DB::raw('COUNT(*)'), '>=', 3)
            ->get();

        foreach ($scores as $row) {
            $this->upsertInsight($row->employee_id, $cycle->id, 'inconsistent_scoring', 'info',
                'High variance in KPI scores',
                "Standard deviation of {$row->std_dev}% across {$row->total} KPIs — scores vary significantly.",
                ['std_dev' => (float) $row->std_dev, 'kpi_count' => (int) $row->total]
            );
            $count++;
        }

        return $count;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildEmployeeContext(int $employeeId, PaCycle $cycle, Collection $snapshots): array
    {
        $kpis = PaEmployeeKpi::where('cycle_id', $cycle->id)
            ->where('employee_id', $employeeId)
            ->with(['score', 'progressLogs'])
            ->get();

        return [
            'employee_id' => $employeeId,
            'cycle'       => ['name' => $cycle->name, 'type' => $cycle->cycle_type],
            'snapshots'   => $snapshots->map(fn($s) => [
                'date'             => $s->snapshot_date,
                'overall_score'    => $s->overall_score,
                'completion_rate'  => $s->kpi_completion_rate,
            ])->values()->toArray(),
            'kpis' => $kpis->map(fn($k) => [
                'title'          => $k->title,
                'target'         => $k->target_value,
                'unit'           => $k->unit,
                'weighting'      => $k->weighting,
                'achieved'       => $k->latestValue(),
                'score_pct'      => $k->score?->score_pct,
            ])->values()->toArray(),
        ];
    }

    private function askAiForInsights(int $employeeId, int $cycleId, array $context): array
    {
        $prompt = <<<PROMPT
Analyse this employee's performance data and return a JSON array of insights.

Each insight must have: type (one of: declining_performance, exceptional_performance, inconsistent_scoring, scoring_bias, kpi_at_risk, trend_analysis), severity (info/warning/critical), title (short), body (explanation, 1-2 sentences), data (supporting metrics as object).

Only include meaningful insights — ignore employees with insufficient data.
Return: {"insights": [...]}
PROMPT;

        $request = new AgentRequest(
            prompt        : $prompt,
            module        : 'performance_appraisal',
            context       : $context,
            systemPrompt  : 'You are an expert HR performance analyst. Identify meaningful patterns in employee KPI data. Be concise and evidence-based. Never fabricate data.',
            temperature   : 0.2,
            maxTokens     : 2048,
            responseFormat: 'json',
        );

        $response = $this->agents->for('performance_appraisal')->ask($request);

        if ($response->failed()) {
            throw new \RuntimeException('AI call failed: ' . $response->errorMessage);
        }

        $parsed = json_decode($response->content, true);
        $insights = $parsed['insights'] ?? [];
        $count = 0;

        foreach ($insights as $insight) {
            $this->upsertInsight(
                $employeeId, $cycleId,
                $insight['type'] ?? 'trend_analysis',
                $insight['severity'] ?? 'info',
                $insight['title'] ?? 'Performance insight',
                $insight['body'] ?? '',
                $insight['data'] ?? []
            );
            $count++;
        }

        return $insights;
    }

    private function runRuleBasedDetection(int $employeeId, PaCycle $cycle, Collection $snapshots): int
    {
        $count = 0;

        if ($snapshots->count() >= 2) {
            $first = $snapshots->first()->overall_score;
            $last  = $snapshots->last()->overall_score;
            $declineAmt = round($first - $last, 1);

            if ($declineAmt >= 15) {
                $this->upsertInsight($employeeId, $cycle->id, 'declining_performance', 'warning',
                    'Performance declining this cycle',
                    "Score dropped from {$first}% to {$last}% — a {$declineAmt}% decline.",
                    ['first_score' => $first, 'last_score' => $last]
                );
                $count++;
            } elseif ($last >= 90) {
                $this->upsertInsight($employeeId, $cycle->id, 'exceptional_performance', 'info',
                    'Strong performance maintained',
                    "Sustained overall score of {$last}% — in the top performance band.",
                    ['score' => $last]
                );
                $count++;
            }
        }

        return $count;
    }

    private function upsertInsight(
        int $employeeId, int $cycleId, string $type, string $severity,
        string $title, string $body, array $data = []
    ): void {
        PaAiInsight::updateOrCreate(
            ['employee_id' => $employeeId, 'cycle_id' => $cycleId, 'insight_type' => $type],
            [
                'severity' => $severity,
                'title'    => $title,
                'body'     => $body,
                'data'     => $data,
                'acknowledged_by' => null,
                'acknowledged_at' => null,
            ]
        );
    }
}
