<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Log;

/**
 * AI-drafts a manager's performance-review narrative from the employee's KPI
 * results, received feedback and self-assessment — so the manager edits rather
 * than writes from scratch. Falls back to a structured rule-based draft.
 */
class PaReviewDraftService
{
    public function __construct(protected AgentRegistry $agents) {}

    public function draft(PaPerformanceSnapshot $snapshot): string
    {
        $kpis = PaEmployeeKpi::where('employee_id', $snapshot->employee_id)
            ->where('cycle_id', $snapshot->cycle_id)
            ->with('score')
            ->get()
            ->map(fn ($k) => [
                'title'     => $k->title,
                'target'    => $k->target_value,
                'weighting' => $k->weighting,
                'score_pct' => $k->score?->score_pct,
            ])->toArray();

        $feedback = PaFeedback::where('employee_id', $snapshot->employee_id)
            ->where('cycle_id', $snapshot->cycle_id)
            ->where('is_draft', false)
            ->get(['feedback_type', 'rating', 'content'])
            ->map(fn ($f) => [
                'type'    => $f->feedback_type,
                'rating'  => $f->rating,
                'content' => $f->content,
            ])->toArray();

        $context = [
            'kpi_completion'   => $snapshot->kpi_completion_rate,
            'overall_score'    => $snapshot->final_score ?? $snapshot->overall_score,
            'employee_score'   => $snapshot->employee_score,
            'employee_comments'=> $snapshot->employee_comments,
            'kpis'             => $kpis,
            'feedback'         => $feedback,
        ];

        try {
            $prompt = <<<PROMPT
You are a manager writing a balanced year-end performance review narrative for a
team member. Using the KPI results, peer/360 feedback and the employee's own
self-assessment below, write a professional 3-4 paragraph review that:
1. Summarises overall performance against KPIs and goals
2. Highlights concrete strengths with evidence
3. Identifies development areas constructively
4. Closes with focus areas for next cycle

Write in second person ("You ..."). Plain text only, no markdown headers.
This is a DRAFT for the manager to edit — be specific but not presumptuous.
PROMPT;

            $request = new AgentRequest(
                prompt       : $prompt,
                module       : 'performance_appraisal',
                context      : $context,
                systemPrompt : 'You are an experienced people manager and executive coach writing fair, evidence-based performance reviews.',
                temperature  : 0.4,
                maxTokens    : 1500,
            );

            $response = $this->agents->for('performance_appraisal')->ask($request);
            $text = trim($response->content ?? '');

            return $text !== '' ? $text : $this->ruleDraft($context);
        } catch (\Throwable $e) {
            Log::warning('PA review auto-draft failed, using rule-based fallback', ['error' => $e->getMessage()]);
            return $this->ruleDraft($context);
        }
    }

    private function ruleDraft(array $ctx): string
    {
        $score = $ctx['overall_score'] ?? 0;
        $completion = $ctx['kpi_completion'] ?? 0;
        $strong = collect($ctx['kpis'])->filter(fn ($k) => ($k['score_pct'] ?? 0) >= 80)->pluck('title')->take(3)->implode(', ');
        $weak   = collect($ctx['kpis'])->filter(fn ($k) => ($k['score_pct'] ?? 0) < 50)->pluck('title')->take(3)->implode(', ');

        $p1 = "Over this cycle you achieved an overall performance score of " . round((float) $score, 1) .
              " with " . round((float) $completion) . "% of your KPIs completed.";
        $p2 = $strong ? "You performed particularly well in: {$strong}. These are clear strengths to build on." :
              "Continue to consolidate consistent delivery across your objectives.";
        $p3 = $weak ? "Development focus is needed in: {$weak}. Let's agree a concrete plan to strengthen these next cycle." :
              "No major gaps were flagged; aim to stretch into higher-impact objectives next cycle.";
        $p4 = "For the next cycle, let's set clear targets, schedule regular check-ins, and align your development plan to your growth goals.";

        return implode("\n\n", [$p1, $p2, $p3, $p4]);
    }
}
