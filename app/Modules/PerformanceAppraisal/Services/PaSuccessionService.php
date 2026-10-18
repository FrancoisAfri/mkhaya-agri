<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Core\User\Models\User;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeSkill;
use App\Modules\PerformanceAppraisal\Models\PaHighPotential;
use App\Modules\PerformanceAppraisal\Models\PaSuccessionCandidate;
use App\Modules\PerformanceAppraisal\Models\PaSuccessionPlan;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaSuccessionService
{
    public function __construct(protected AgentRegistry $agents) {}

    /**
     * Score a succession candidate using AI and update their record.
     */
    public function scoreCandidate(PaSuccessionCandidate $candidate, int $scoredBy): PaSuccessionCandidate
    {
        $candidate->load(['employee', 'plan']);
        $factors = $this->buildCandidateFactors($candidate);
        $score   = $this->computeCandidateScore($factors);

        try {
            $request = new AgentRequest(
                prompt       : 'You are a succession planning specialist. Based on the candidate data below, write a concise 2-paragraph readiness assessment that: (1) evaluates the candidate\'s strengths and gaps relative to the position, (2) gives a specific recommendation on readiness timeline and key development priorities. Be objective and evidence-based. Plain text only.',
                module       : 'performance_appraisal',
                context      : ['factors' => $factors, 'readiness_score' => $score, 'position' => $candidate->plan->position_title],
                systemPrompt : 'You are an expert in succession planning and talent assessment.',
                temperature  : 0.3,
                maxTokens    : 600,
            );

            $response = $this->agents->for('performance_appraisal')->ask($request);
            $aiRec = $response->failed() ? null : trim($response->content);

        } catch (\Throwable $e) {
            Log::warning('PaSuccession: AI scoring failed', ['error' => $e->getMessage()]);
            $aiRec = null;
        }

        $candidate->update([
            'ai_readiness_score'  => $score,
            'ai_recommendation'   => $aiRec,
        ]);

        return $candidate;
    }

    /**
     * Compute bench strength for a succession plan (0–100) and persist.
     */
    public function computeBenchStrength(PaSuccessionPlan $plan): int
    {
        $candidates = $plan->candidates;
        if ($candidates->isEmpty()) return 0;

        $readyNow  = $candidates->where('readiness_level', 'ready_now')->count();
        $oneYear   = $candidates->where('readiness_level', '1_year')->count();
        $twoYears  = $candidates->where('readiness_level', '2_years')->count();

        $score = min(100, (int) (
            $readyNow * 40 +
            $oneYear  * 20 +
            $twoYears * 10
        ));

        $plan->update(['bench_strength' => $score]);
        return $score;
    }

    /**
     * Identify high-potential employees across a cycle.
     */
    public function identifyHighPotentials(PaCycle $cycle, int $identifiedBy): array
    {
        $employees = User::pluck('id');
        $results   = [];

        foreach ($employees as $empId) {
            $factors = $this->buildHighPotentialFactors($empId, $cycle->id);
            $score   = $this->computeHighPotentialScore($factors);
            if ($score < 60) continue;

            $band = match(true) {
                $score >= 85 => 'exceptional',
                $score >= 72 => 'high',
                default      => 'emerging',
            };

            $results[] = PaHighPotential::updateOrCreate(
                ['employee_id' => $empId, 'cycle_id' => $cycle->id],
                ['composite_score' => $score, 'potential_band' => $band, 'factors' => $factors, 'is_ai_generated' => false, 'identified_by' => $identifiedBy]
            );
        }

        return $results;
    }

    /**
     * Generate AI succession analysis for a plan.
     */
    public function generatePlanAnalysis(PaSuccessionPlan $plan): string
    {
        $plan->load(['candidates.employee', 'incumbent']);
        $candidates = $plan->candidates->map(fn($c) => [
            'name'             => $c->employee->email,
            'readiness_level'  => $c->readiness_level,
            'potential_rating' => $c->potential_rating,
            'risk_of_loss'     => $c->risk_of_loss,
            'ai_score'         => $c->ai_readiness_score,
        ])->toArray();

        try {
            $request = new AgentRequest(
                prompt       : 'You are a succession planning expert. Write a 3-paragraph succession analysis for the critical role described below that: (1) assesses bench strength, (2) identifies the top succession candidate and their readiness, (3) highlights risks and recommended actions. Be strategic and specific. Plain text only.',
                module       : 'performance_appraisal',
                context      : [
                    'position'       => $plan->position_title,
                    'department'     => $plan->department,
                    'is_critical'    => $plan->is_critical_role,
                    'bench_strength' => $plan->bench_strength,
                    'candidates'     => $candidates,
                ],
                systemPrompt : 'You are a senior talent management specialist with expertise in succession planning.',
                temperature  : 0.3,
                maxTokens    : 900,
            );

            $response = $this->agents->for('performance_appraisal')->ask($request);
            if ($response->failed()) throw new \RuntimeException($response->errorMessage);

            $text = trim($response->content);
            $plan->update(['ai_analysis' => $text]);
            return $text;

        } catch (\Throwable $e) {
            Log::warning('PaSuccession: AI plan analysis failed', ['plan_id' => $plan->id, 'error' => $e->getMessage()]);
            $bench = $plan->bench_strength ?? 0;
            $ready = $plan->readyNowCount();
            return "Succession Plan: {$plan->position_title}\n\nBench strength: {$bench}/100. Ready-now candidates: {$ready}.\n\n" . ($candidates ? 'Candidate pipeline exists.' : 'No candidates identified — immediate action required.');
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function buildCandidateFactors(PaSuccessionCandidate $c): array
    {
        $kpiAvg = DB::table('pa_employee_kpis as k')
            ->join('pa_kpi_scores as s', 's.employee_kpi_id', '=', 'k.id')
            ->where('k.employee_id', $c->employee_id)
            ->avg('s.score_pct');

        $skillsAvg = PaEmployeeSkill::where('employee_id', $c->employee_id)->avg('current_level');

        return [
            'kpi_avg'          => $kpiAvg !== null ? round($kpiAvg, 1) : null,
            'skills_avg_level' => $skillsAvg !== null ? round($skillsAvg, 1) : null,
            'potential_rating' => $c->potential_rating,
            'readiness_level'  => $c->readiness_level,
            'risk_of_loss'     => $c->risk_of_loss,
        ];
    }

    private function computeCandidateScore(array $f): float
    {
        $kpi      = ($f['kpi_avg']          ?? 60) * 0.35;
        $skills   = (isset($f['skills_avg_level']) ? ($f['skills_avg_level'] / 5) * 100 : 60) * 0.25;
        $potential= ($f['potential_rating'] / 5)    * 100 * 0.25;
        $readiness= match($f['readiness_level']) {
            'ready_now' => 100, '1_year' => 70, '2_years' => 40, default => 20,
        } * 0.15;

        return round($kpi + $skills + $potential + $readiness, 2);
    }

    private function buildHighPotentialFactors(int $empId, int $cycleId): array
    {
        $kpiAvg = DB::table('pa_employee_kpis as k')
            ->join('pa_kpi_scores as s', 's.employee_kpi_id', '=', 'k.id')
            ->where('k.employee_id', $empId)
            ->where('k.cycle_id', $cycleId)
            ->avg('s.score_pct');

        $skillCount = PaEmployeeSkill::where('employee_id', $empId)->count();
        $skillsAvg  = PaEmployeeSkill::where('employee_id', $empId)->avg('current_level');

        $feedbackAvg = DB::table('pa_feedback')
            ->where('employee_id', $empId)
            ->where('is_draft', false)
            ->where('cycle_id', $cycleId)
            ->avg('rating');

        return [
            'kpi_avg'      => $kpiAvg !== null ? round($kpiAvg, 1) : null,
            'skill_count'  => $skillCount,
            'skills_avg'   => $skillsAvg !== null ? round($skillsAvg, 1) : null,
            'feedback_avg' => $feedbackAvg !== null ? round($feedbackAvg, 1) : null,
        ];
    }

    private function computeHighPotentialScore(array $f): float
    {
        $kpi      = ($f['kpi_avg']      ?? 0) * 0.40;
        $skills   = (isset($f['skills_avg']) ? ($f['skills_avg'] / 5) * 100 : 0) * 0.30;
        $breadth  = min(100, ($f['skill_count'] / 10) * 100)                * 0.10;
        $feedback = (isset($f['feedback_avg']) ? ($f['feedback_avg'] / 5) * 100 : 0) * 0.20;

        return round($kpi + $skills + $breadth + $feedback, 2);
    }
}
