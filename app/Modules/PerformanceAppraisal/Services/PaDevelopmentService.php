<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentPlan;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeSkill;
use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaDevelopmentService
{
    public function __construct(protected AgentRegistry $agents) {}

    /**
     * Generate AI-powered development recommendations for an IDP.
     */
    public function generateRecommendations(PaDevelopmentPlan $plan): string
    {
        $plan->load(['employee', 'activities']);

        // Collect skill gaps
        $skillGaps = PaEmployeeSkill::where('employee_id', $plan->employee_id)
            ->with('skill')
            ->get()
            ->filter(fn($es) => $es->gapLevel() > 0)
            ->map(fn($es) => [
                'skill'         => $es->skill->name,
                'category'      => $es->skill->category,
                'current_level' => $es->current_level,
                'target_level'  => $es->target_level,
                'gap'           => $es->gapLevel(),
            ])->values()->toArray();

        // KPI performance
        $kpiPerf = DB::table('pa_employee_kpis as k')
            ->join('pa_kpi_scores as s', 's.employee_kpi_id', '=', 'k.id')
            ->where('k.employee_id', $plan->employee_id)
            ->when($plan->cycle_id, fn($q) => $q->where('k.cycle_id', $plan->cycle_id))
            ->select('k.id', DB::raw('avg(s.score_pct) as avg_score'))
            ->groupBy('k.id')
            ->get();

        $avgKpiScore = $kpiPerf->avg('avg_score');

        $prompt = <<<PROMPT
You are a senior L&D (Learning & Development) specialist creating a personalised development plan.

Based on the employee data below, generate specific, actionable development activity recommendations. For each recommendation, provide:
- Activity title
- Activity type (training/mentoring/project/certification/reading/shadowing/coaching)
- Why this addresses a specific gap
- Expected timeline (weeks)

Output a numbered list of 4–6 concrete recommendations. Plain text only.
PROMPT;

        try {
            $request = new AgentRequest(
                prompt       : $prompt,
                module       : 'performance_appraisal',
                context      : [
                    'career_goal'         => $plan->career_goal,
                    'development_areas'   => $plan->development_areas,
                    'skill_gaps'          => $skillGaps,
                    'avg_kpi_score'       => $avgKpiScore !== null ? round($avgKpiScore, 1) : null,
                    'existing_activities' => $plan->activities->pluck('title')->toArray(),
                ],
                systemPrompt : 'You are an expert L&D consultant specialising in performance-driven development plans. Recommendations should be specific, measurable, and directly tied to identified gaps.',
                temperature  : 0.4,
                maxTokens    : 1200,
            );

            $response = $this->agents->for('performance_appraisal')->ask($request);

            if ($response->failed()) throw new \RuntimeException($response->errorMessage);

            $text = trim($response->content);
            $plan->update(['ai_recommendations' => $text]);
            return $text;

        } catch (\Throwable $e) {
            Log::warning('PaDevelopment: AI recommendations failed', ['plan_id' => $plan->id, 'error' => $e->getMessage()]);
            return $this->ruleBasedRecommendations($skillGaps);
        }
    }

    /**
     * Compute and sync overall_progress for a plan based on activity completion.
     */
    public function syncProgress(PaDevelopmentPlan $plan): void
    {
        $plan->load('activities');
        $progress = $plan->computeProgress();
        $plan->update(['overall_progress' => $progress]);
    }

    /**
     * Build a skill gap matrix for an employee (skills with gap > 0).
     */
    public function skillGapMatrix(int $employeeId): array
    {
        return PaEmployeeSkill::where('employee_id', $employeeId)
            ->with('skill')
            ->get()
            ->map(fn($es) => [
                'skill_id'      => $es->skill_id,
                'skill_name'    => $es->skill->name,
                'category'      => $es->skill->category,
                'current_level' => $es->current_level,
                'target_level'  => $es->target_level,
                'gap'           => $es->gapLevel(),
                'self_rated'    => $es->self_rated,
                'verified'      => $es->verified_at !== null,
            ])
            ->sortByDesc('gap')
            ->values()
            ->toArray();
    }

    private function ruleBasedRecommendations(array $skillGaps): string
    {
        if (empty($skillGaps)) {
            return "No specific skill gaps identified. Focus on deepening expertise in your current role and seeking stretch assignments.";
        }

        $lines = ["Based on identified skill gaps, the following development activities are recommended:"];
        foreach (array_slice($skillGaps, 0, 4) as $i => $gap) {
            $n = $i + 1;
            $lines[] = "{$n}. {$gap['skill']} ({$gap['category']}): Current level {$gap['current_level']}, target {$gap['target_level']}. Recommended: targeted training or mentoring to bridge a {$gap['gap']}-level gap.";
        }

        return implode("\n", $lines);
    }
}
