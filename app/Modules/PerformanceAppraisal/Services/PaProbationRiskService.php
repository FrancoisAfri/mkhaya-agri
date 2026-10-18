<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\PerformanceAppraisal\Models\PaProbationReview;
use App\Modules\PerformanceAppraisal\Models\PaProbationRiskScore;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Log;

class PaProbationRiskService
{
    public function __construct(protected AgentRegistry $agents) {}

    /**
     * Compute a risk score for a probation review and persist to audit trail.
     * Updates the parent review's risk_score / risk_level / recommendation columns.
     */
    public function calculateRisk(PaProbationReview $review, int $scoredBy): PaProbationRiskScore
    {
        $review->load(['objectives', 'checkins', 'feedback']);

        $factors  = $this->buildFactors($review);
        $score    = $this->computeScore($factors);
        $level    = $this->riskLevel($score);
        $rec      = $this->recommendation($score);
        $prob     = round(max(0.0, 100.0 - $score), 1);

        $riskScore = PaProbationRiskScore::create([
            'review_id'           => $review->id,
            'risk_score'          => $score,
            'risk_level'          => $level,
            'success_probability' => $prob,
            'recommendation'      => $rec,
            'factors'             => $factors,
            'is_ai_generated'     => false,
            'scored_by'           => $scoredBy,
        ]);

        $review->update([
            'risk_score'          => $score,
            'risk_level'          => $level,
            'recommendation'      => $rec,
            'success_probability' => $prob,
        ]);

        return $riskScore;
    }

    /**
     * Generate an AI narrative prediction and persist to review.
     */
    public function generateAiPrediction(PaProbationReview $review, int $scoredBy): string
    {
        $review->load(['objectives', 'checkins', 'feedback']);
        $factors = $this->buildFactors($review);
        $score   = $this->computeScore($factors);

        $prompt = <<<PROMPT
You are an expert HR consultant reviewing a probationary period performance record.

Based on the data below, write a 3–4 paragraph assessment that:
1. Summarises overall probation performance to date
2. Highlights key strengths demonstrated during the probation
3. Identifies specific risks or concerns
4. Provides a clear recommendation: CONFIRM, EXTEND, or TERMINATE, with justification

Be professional, objective, and evidence-based. Output plain paragraphs only.
PROMPT;

        try {
            $request = new AgentRequest(
                prompt       : $prompt,
                module       : 'performance_appraisal',
                context      : [
                    'probation_type'     => $review->probation_type,
                    'start_date'         => $review->start_date->toDateString(),
                    'end_date'           => $review->end_date->toDateString(),
                    'percent_elapsed'    => $review->percentElapsed(),
                    'days_remaining'     => $review->daysRemaining(),
                    'risk_score'         => $score,
                    'recommendation'     => $this->recommendation($score),
                    'factors'            => $factors,
                    'objectives'         => $review->objectives->map(fn($o) => [
                        'title'  => $o->title,
                        'status' => $o->status,
                        'weight' => $o->weight,
                        'achievement_pct' => $o->achievementPct(),
                    ])->toArray(),
                    'feedback_summary'   => $review->feedback()
                        ->where('is_draft', false)
                        ->get()
                        ->groupBy('feedback_type')
                        ->map(fn($items) => [
                            'count'      => $items->count(),
                            'avg_rating' => $items->whereNotNull('rating')->avg('rating'),
                        ])->toArray(),
                ],
                systemPrompt : 'You are a senior HR practitioner assessing an employee\'s probationary period. Be balanced, specific, and constructive.',
                temperature  : 0.3,
                maxTokens    : 1800,
            );

            $response = $this->agents->for('performance_appraisal')->ask($request);

            if ($response->failed()) {
                throw new \RuntimeException($response->errorMessage);
            }

            $text = trim($response->content);

            // Persist as AI-generated score entry
            PaProbationRiskScore::create([
                'review_id'           => $review->id,
                'risk_score'          => $score,
                'risk_level'          => $this->riskLevel($score),
                'success_probability' => round(max(0.0, 100.0 - $score), 1),
                'recommendation'      => $this->recommendation($score),
                'factors'             => $factors,
                'is_ai_generated'     => true,
                'scored_by'           => $scoredBy,
            ]);

            $review->update([
                'ai_prediction' => $text,
                'ai_summary'    => $text,
            ]);

            return $text;

        } catch (\Throwable $e) {
            Log::warning('PaProbationRisk: AI prediction failed', [
                'review_id' => $review->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->ruleBasedNarrative($review, $factors, $score);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildFactors(PaProbationReview $review): array
    {
        $objectives = $review->objectives;
        $feedback   = $review->feedback()->where('is_draft', false)->get();
        $checkins   = $review->checkins;

        // Objectives completion (40% of risk)
        $totalWeight = $objectives->sum('weight') ?: 100;
        $metWeight   = $objectives->where('status', 'met')->sum('weight');
        $objPct      = $objectives->isEmpty() ? 50.0 : round(($metWeight / $totalWeight) * 100, 1);

        // Feedback average rating (30%)
        $ratings   = $feedback->whereNotNull('rating');
        $avgRating = $ratings->isNotEmpty() ? round($ratings->avg('rating'), 2) : null;

        // Checkin completion (15%)
        $totalCi  = $checkins->count();
        $doneCi   = $checkins->where('status', 'completed')->count();
        $ciPct    = $totalCi > 0 ? round(($doneCi / $totalCi) * 100, 1) : null;

        // Time elapsed (15%)
        $pctElapsed = $review->percentElapsed();

        return [
            'objectives_met_pct'  => $objPct,
            'objectives_count'    => $objectives->count(),
            'avg_feedback_rating' => $avgRating,
            'checkin_completion'  => $ciPct,
            'percent_elapsed'     => $pctElapsed,
            'days_remaining'      => $review->daysRemaining(),
        ];
    }

    private function computeScore(array $f): int
    {
        // Objectives risk contribution (40%): low met% = high risk
        $objRisk = (100 - ($f['objectives_met_pct'] ?? 50)) * 0.40;

        // Feedback risk contribution (30%): low rating = high risk
        $fbRisk = isset($f['avg_feedback_rating'])
            ? (1 - ($f['avg_feedback_rating'] / 5)) * 100 * 0.30
            : 15.0; // neutral when no feedback

        // Checkin risk contribution (15%): low completion = higher risk
        $ciRisk = isset($f['checkin_completion'])
            ? (1 - ($f['checkin_completion'] / 100)) * 100 * 0.15
            : 7.5;

        // Time-elapsed contribution (15%): deeper into period with high risk → escalates
        $timeRisk = ($f['percent_elapsed'] / 100) * 15;

        return (int) min(100, round($objRisk + $fbRisk + $ciRisk + $timeRisk));
    }

    private function riskLevel(int $score): string
    {
        return match(true) {
            $score >= 76 => 'critical',
            $score >= 51 => 'high',
            $score >= 26 => 'medium',
            default      => 'low',
        };
    }

    private function recommendation(int $score): string
    {
        return match(true) {
            $score > 70 => 'terminate',
            $score > 40 => 'extend',
            default     => 'confirm',
        };
    }

    private function ruleBasedNarrative(PaProbationReview $review, array $factors, int $score): string
    {
        $rec   = ucfirst($this->recommendation($score));
        $level = ucfirst($this->riskLevel($score));
        $prob  = round(max(0.0, 100.0 - $score), 1);

        $lines = [];
        $lines[] = "Probation Assessment: {$review->probation_type} review ({$review->percentElapsed()}% elapsed, {$review->daysRemaining()} days remaining).";
        $lines[] = "Overall risk level: {$level} (score {$score}/100). Estimated success probability: {$prob}%.";

        if ($factors['objectives_met_pct'] !== null) {
            $lines[] = "Objectives completion rate: {$factors['objectives_met_pct']}% of weighted targets met.";
        }
        if ($factors['avg_feedback_rating'] !== null) {
            $lines[] = "Average feedback rating: {$factors['avg_feedback_rating']}/5.";
        }

        $lines[] = "Recommendation: {$rec}.";

        return implode("\n\n", $lines);
    }
}
