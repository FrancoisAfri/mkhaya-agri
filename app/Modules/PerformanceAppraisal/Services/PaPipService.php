<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Core\AI\DTOs\AgentRequest;
use App\Modules\PerformanceAppraisal\Models\PaPipPlan;
use Illuminate\Support\Facades\Log;

class PaPipService
{
    public function generateCoachingRecommendation(PaPipPlan $plan): string
    {
        $plan->load(['objectives', 'progressLogs', 'reviews']);

        $context = [
            'reason'          => $plan->reason,
            'status'          => $plan->status,
            'start_date'      => $plan->start_date->toDateString(),
            'target_end_date' => $plan->target_end_date->toDateString(),
            'days_remaining'  => $plan->daysRemaining(),
            'overall_progress'=> $plan->overallProgress(),
            'objectives'      => $plan->objectives->map(fn($o) => [
                'title'        => $o->title,
                'metric'       => $o->metric,
                'target_value' => $o->target_value,
                'status'       => $o->status,
                'weight'       => $o->weight,
            ])->toArray(),
            'progress_logs'   => $plan->progressLogs->take(5)->map(fn($l) => [
                'progress_pct' => $l->progress_pct,
                'notes'        => $l->notes,
                'logged_at'    => $l->logged_at->toDateString(),
            ])->toArray(),
            'reviews'         => $plan->reviews->map(fn($r) => [
                'outcome'        => $r->outcome,
                'scheduled_date' => $r->scheduled_date->toDateString(),
                'status'         => $r->status,
                'notes'          => $r->notes,
            ])->toArray(),
        ];

        try {
            $request = new AgentRequest(
                prompt: 'Based on this PIP plan, generate specific, actionable coaching recommendations for the manager. Address: (1) how to support the employee through each objective, (2) communication strategies, (3) early warning signs to watch for, (4) resources or interventions to consider.',
                module: 'performance_appraisal',
                context: $context,
                systemPrompt: 'You are an expert HR coach. Write a structured coaching guide (4–6 paragraphs) for the manager managing this Performance Improvement Plan.',
                temperature: 0.4,
                maxTokens: 1500,
            );

            return AgentRegistry::for('performance_appraisal')->ask($request);
        } catch (\Throwable $e) {
            Log::warning('PIP coaching AI failed', ['error' => $e->getMessage()]);
            return $this->ruleBasedCoaching($plan);
        }
    }

    public function recommendPIPOutcome(PaPipPlan $plan): string
    {
        $plan->load(['objectives', 'progressLogs', 'reviews']);

        $context = [
            'overall_progress' => $plan->overallProgress(),
            'days_remaining'   => $plan->daysRemaining(),
            'objectives_met'   => $plan->objectives->where('status', 'met')->count(),
            'objectives_total' => $plan->objectives->count(),
            'last_review_outcome' => $plan->reviews->last()?->outcome,
            'status'           => $plan->status,
        ];

        try {
            $request = new AgentRequest(
                prompt: 'Based on this PIP progress data, recommend one of: (1) close_improved — employee has met objectives; (2) extend — employee is progressing but needs more time; (3) escalate — no meaningful improvement detected. Justify your recommendation with 2–3 sentences.',
                module: 'performance_appraisal',
                context: $context,
                systemPrompt: 'You are an HR expert advising on PIP outcomes. Be direct and objective.',
                temperature: 0.3,
                maxTokens: 600,
            );

            return AgentRegistry::for('performance_appraisal')->ask($request);
        } catch (\Throwable $e) {
            Log::warning('PIP outcome AI failed', ['error' => $e->getMessage()]);
            return $this->ruleBasedOutcome($plan);
        }
    }

    // ── Rule-based fallbacks ──────────────────────────────────────────────────

    private function ruleBasedCoaching(PaPipPlan $plan): string
    {
        $progress = $plan->overallProgress();
        $days     = $plan->daysRemaining();

        $level = match (true) {
            $progress >= 75 => 'strong',
            $progress >= 40 => 'moderate',
            default          => 'low',
        };

        return match ($level) {
            'strong' => "Employee is showing strong progress ({$progress}% overall). "
                . "Continue weekly check-ins and positive reinforcement. "
                . "Prepare transition plan for closing the PIP successfully. "
                . "{$days} days remaining — focus on consolidating improvements.",

            'moderate' => "Employee has made moderate progress ({$progress}%). "
                . "Increase check-in frequency to bi-weekly. "
                . "Identify specific blockers for the objectives not yet met. "
                . "Consider additional training or mentoring support. "
                . "{$days} days remaining — acceleration needed.",

            default => "Progress is low ({$progress}%). "
                . "Schedule urgent one-on-one to identify root causes. "
                . "Review whether objectives are realistic and achievable. "
                . "Consider external HR involvement if no improvement in the next two weeks. "
                . "{$days} days remaining — escalation may be required.",
        };
    }

    private function ruleBasedOutcome(PaPipPlan $plan): string
    {
        $progress = $plan->overallProgress();
        $metCount = $plan->objectives->where('status', 'met')->count();
        $total    = max($plan->objectives->count(), 1);
        $metPct   = round(($metCount / $total) * 100);

        if ($progress >= 80 && $metPct >= 70) {
            return "Recommendation: close_improved. Employee has met {$metCount}/{$total} objectives with {$progress}% overall progress. "
                . "Performance improvements are evident and the PIP objectives have been substantially achieved.";
        }

        if ($progress >= 40 && $plan->daysRemaining() > 0) {
            return "Recommendation: extend. Employee is at {$progress}% progress with {$plan->daysRemaining()} days remaining. "
                . "Some objectives are on track but others require additional time. A 30-day extension is advised.";
        }

        return "Recommendation: escalate. Overall progress is {$progress}% with {$metCount}/{$total} objectives met. "
            . "Insufficient improvement has been demonstrated. HR and leadership involvement is recommended.";
    }
}
