<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\PerformanceAppraisal\Models\PaDevelopmentPlan;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeSkill;
use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use App\Modules\PerformanceAppraisal\Models\PaHighPotential;
use App\Modules\PerformanceAppraisal\Models\PaLearningRecommendation;
use App\Modules\PerformanceAppraisal\Models\PaTalentProfile;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Log;

class PaTalentGrowthService
{
    public function buildTalentProfile(int $employeeId, ?int $cycleId = null): PaTalentProfile
    {
        // KPI composite — average of actual_value across all KPIs for this cycle
        $kpis     = PaEmployeeKpi::where('employee_id', $employeeId)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->get();
        $kpiScore = $kpis->isEmpty() ? null : round((float) $kpis->avg('actual_value'), 2);

        // Skill metrics from pa_employee_skills
        $skills       = PaEmployeeSkill::where('employee_id', $employeeId)->get();
        $skillBreadth = $skills->count();
        $skillDepthAvg = $skills->isEmpty() ? 0.0 : round((float) $skills->avg('current_level'), 2);

        // Feedback — avg rating as percentage of max 5
        $feedbackAvg = (float) PaFeedback::where('employee_id', $employeeId)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->whereNotNull('rating')
            ->avg('rating');
        $feedbackPct = round(($feedbackAvg / 5) * 100, 2);

        // High-potential composite from previous run
        $hp      = PaHighPotential::where('employee_id', $employeeId)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->latest()
            ->first();
        $hpScore = $hp ? (float) $hp->composite_score : 0.0;

        // Promotion readiness: KPI 35% + skills 25% + feedback 20% + HP 20%
        $kpiW   = $kpiScore !== null ? ($kpiScore / 100) * 35 : 0;
        $skillW = $skillBreadth > 0 ? (($skillDepthAvg / 5) * 100 * 0.25) : 0;
        $fbW    = $feedbackPct * 0.20;
        $hpW    = $hpScore * 0.20;
        $promoScore = round($kpiW + $skillW + $fbW + $hpW, 2);

        // Leadership: feedback 35% + skill breadth (capped at 10 skills = 100) 25% + HP 40%
        $leadershipScore = round(
            ($feedbackPct * 0.35) + (min($skillBreadth / 10, 1.0) * 100 * 0.25) + ($hpScore * 0.40),
            2
        );

        // Composite: promotion 60% + leadership 40%
        $composite = round($promoScore * 0.60 + $leadershipScore * 0.40, 2);

        return PaTalentProfile::updateOrCreate(
            ['employee_id' => $employeeId, 'cycle_id' => $cycleId],
            [
                'promotion_readiness_score' => $promoScore,
                'promotion_readiness_level' => PaTalentProfile::readinessLevelFromScore($promoScore),
                'leadership_score'          => $leadershipScore,
                'is_leadership_pipeline'    => $leadershipScore >= 65,
                'talent_tier'               => PaTalentProfile::tierFromScore($composite),
                'kpi_composite_score'       => $kpiScore,
                'skill_breadth'             => $skillBreadth,
                'skill_depth_avg'           => $skillDepthAvg,
                'composite_score'           => $composite,
                'assessed_at'               => now(),
            ]
        );
    }

    /** Return skill gaps (gap > 0) sorted by gap descending */
    public function detectSkillGaps(int $employeeId): array
    {
        return PaEmployeeSkill::with('skill')
            ->where('employee_id', $employeeId)
            ->get()
            ->map(fn($es) => [
                'skill_id'      => $es->skill_id,
                'skill_name'    => $es->skill->name ?? 'Unknown',
                'category'      => $es->skill->category ?? '',
                'current_level' => $es->current_level,
                'target_level'  => $es->target_level,
                'gap'           => max(0, $es->target_level - $es->current_level),
            ])
            ->filter(fn($s) => $s['gap'] > 0)
            ->sortByDesc('gap')
            ->values()
            ->toArray();
    }

 public function generateLearningRecommendations(
    int $employeeId,
    ?int $planId = null,
    ?int $cycleId = null
): array {
    $gaps = $this->detectSkillGaps($employeeId);

    $kpis = PaEmployeeKpi::with(['progressLogs' => fn($q) => $q->latest('log_date')->limit(1)])
        ->where('employee_id', $employeeId)
        ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
        ->get(['id', 'title', 'target_value'])
        ->map(fn($k) => [
            'title'        => $k->title,
            'actual_value' => $k->latestValue(),
            'target_value' => $k->target_value,
        ]);

    $plan = $planId ? PaDevelopmentPlan::find($planId) : null;

    $context = [
        'employee_id'  => $employeeId,
        'skill_gaps'   => array_slice($gaps, 0, 10),
        'kpi_snapshot' => $kpis->toArray(),
        'career_goal'  => $plan?->career_goal,
    ];

    try {
        $request = new AgentRequest(
            prompt: 'Generate 5–8 specific, actionable learning recommendations for this employee. For each provide: recommendation_type (course/certification/workshop/book/mentoring/project/conference), title, description (2 sentences), provider, estimated_hours (integer), priority (low/medium/high/critical), skill_name.',
            module: 'performance_appraisal',
            context: $context,
            systemPrompt: 'You are a Talent Development expert. Return a JSON array of recommendation objects.',
            temperature: 0.5,
            maxTokens: 2000,
            responseFormat: 'json',
        );

        $response = AgentRegistry::for('performance_appraisal')->ask($request);
        $recs     = is_array($response) ? $response : json_decode($response, true);
    } catch (\Throwable $e) {
        Log::warning('TalentGrowth AI learning recs failed', ['error' => $e->getMessage()]);
        $recs = $this->ruleLearningRecommendations($gaps);
    }

    $saved = [];
    foreach (($recs ?? []) as $rec) {
        $saved[] = PaLearningRecommendation::create([
            'employee_id'         => $employeeId,
            'development_plan_id' => $planId,
            'cycle_id'            => $cycleId,
            'recommendation_type' => $rec['recommendation_type'] ?? 'course',
            'title'               => $rec['title']              ?? 'Learning Activity',
            'description'         => $rec['description']        ?? null,
            'provider'            => $rec['provider']           ?? null,
            'estimated_hours'     => isset($rec['estimated_hours']) ? (int) $rec['estimated_hours'] : null,
            'priority'            => $rec['priority']           ?? 'medium',
            'ai_generated'        => true,
            'status'              => 'pending',
        ]);
    }

    return $saved;
}

    public function generateSuccessionRecommendation(int $employeeId, ?int $cycleId = null): string
    {
        $profile = PaTalentProfile::where('employee_id', $employeeId)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->latest()
            ->first();

        $gaps    = $this->detectSkillGaps($employeeId);

        $context = [
            'employee_id'               => $employeeId,
            'composite_score'           => $profile?->composite_score,
            'promotion_readiness_level' => $profile?->promotion_readiness_level,
            'leadership_score'          => $profile?->leadership_score,
            'talent_tier'               => $profile?->talent_tier,
            'top_skill_gaps'            => array_slice($gaps, 0, 5),
        ];

        try {
            $request = new AgentRequest(
                prompt: 'Based on this talent profile, generate a succession recommendation: which roles this employee could be a candidate for, their readiness timeline, and what development is needed to accelerate readiness.',
                module: 'performance_appraisal',
                context: $context,
                systemPrompt: 'You are a Succession Planning expert. Write a concise professional recommendation (3–4 paragraphs).',
                temperature: 0.4,
                maxTokens: 1000,
            );

            return AgentRegistry::for('performance_appraisal')->ask($request);
        } catch (\Throwable $e) {
            Log::warning('Succession recommendation AI failed', ['error' => $e->getMessage()]);
            $tier     = $profile?->talent_tier               ?? 'contributor';
            $readiness = $profile?->promotion_readiness_level ?? 'not_ready';
            $gapCount = count($gaps);
            return "Employee is classified as {$tier} with promotion readiness: {$readiness}. "
                . "{$gapCount} skill gap(s) identified. Recommend focused development before succession consideration.";
        }
    }

    // ── Rule-based fallback ───────────────────────────────────────────────────

    private function ruleLearningRecommendations(array $gaps): array
    {
        $typeMap = [
            'technical'  => ['recommendation_type' => 'course',        'provider' => 'Coursera / Udemy',   'estimated_hours' => 40, 'priority' => 'high'],
            'soft'       => ['recommendation_type' => 'workshop',       'provider' => 'Internal L&D',       'estimated_hours' => 16, 'priority' => 'medium'],
            'leadership' => ['recommendation_type' => 'mentoring',      'provider' => 'Leadership Programme','estimated_hours' => 24, 'priority' => 'high'],
            'domain'     => ['recommendation_type' => 'certification',  'provider' => 'Industry Body',      'estimated_hours' => 60, 'priority' => 'high'],
            'compliance' => ['recommendation_type' => 'course',         'provider' => 'Compliance Team',    'estimated_hours' => 8,  'priority' => 'critical'],
        ];

        return array_map(function (array $gap) use ($typeMap): array {
            $defaults = $typeMap[$gap['category']] ?? [
                'recommendation_type' => 'course', 'provider' => null, 'estimated_hours' => 20, 'priority' => 'medium',
            ];
            return array_merge($defaults, [
                'title'       => "Develop: {$gap['skill_name']}",
                'description' => "Current level {$gap['current_level']}/5, target {$gap['target_level']}/5. "
                    . "A gap of {$gap['gap']} requires focused development.",
                'skill_name'  => $gap['skill_name'],
            ]);
        }, array_slice($gaps, 0, 5));
    }
}
