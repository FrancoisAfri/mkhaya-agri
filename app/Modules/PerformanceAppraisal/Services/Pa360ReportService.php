<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\PerformanceAppraisal\Models\Pa360Review;
use App\Modules\PerformanceAppraisal\Models\Pa360Response;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Pa360ReportService
{
    public function __construct(protected AgentRegistry $agents) {}

    /**
     * Aggregate all responses for a review into a structured report.
     */
    public function buildReport(Pa360Review $review): array
    {
        $responses = Pa360Response::where('review_id', $review->id)
            ->join('pa_360_raters as r', 'r.id', '=', 'pa_360_responses.rater_id')
            ->where('r.status', 'submitted')
            ->select('pa_360_responses.*', 'r.relationship')
            ->get();

        $byQuestion = $responses->groupBy('question_snapshot');

        $questions = $byQuestion->map(function (Collection $items, string $question) use ($review) {
            $ratings = $items->whereNotNull('rating_value');
            $texts   = $items->whereNotNull('text_value')->filter(fn($i) => trim($i->text_value) !== '');

            return [
                'question'        => $question,
                'category'        => $items->first()?->category ?? 'general',
                'avg_rating'      => $ratings->count() > 0 ? round($ratings->avg('rating_value'), 1) : null,
                'rating_count'    => $ratings->count(),
                'by_relationship' => $ratings->groupBy('relationship')->map(fn($g) => round($g->avg('rating_value'), 1)),
                'comments'        => $review->require_anonymity
                    ? $texts->pluck('text_value')->values()->toArray()
                    : $texts->map(fn($i) => ['relationship' => $i->relationship, 'comment' => $i->text_value])->values()->toArray(),
            ];
        })->values()->sortBy('category')->values()->toArray();

        $byCategory = collect($questions)
            ->groupBy('category')
            ->map(fn($qs) => round(collect($qs)->whereNotNull('avg_rating')->avg('avg_rating'), 1))
            ->toArray();

        $submittedCount = $review->raters()->where('status', 'submitted')->count();

        return [
            'review_id'       => $review->id,
            'employee_id'     => $review->employee_id,
            'title'           => $review->title,
            'submitted_count' => $submittedCount,
            'completion_rate' => $review->completionRate(),
            'category_scores' => $byCategory,
            'questions'       => $questions,
        ];
    }

    /**
     * Generate an AI-written narrative summary of the 360 results.
     */
    public function generateAiSummary(Pa360Review $review): string
    {
        $report = $this->buildReport($review);

        if ($report['submitted_count'] < 2) {
            return 'Insufficient responses to generate a meaningful summary. At least 2 submissions required.';
        }

        try {
            $prompt = <<<PROMPT
You are an experienced executive coach writing a 360-degree appraisal summary.

Based on the data below, write a professional 4-5 paragraph narrative that:
1. Opens with an overall impression of the employee's performance
2. Highlights their key strengths (supported by ratings and comments)
3. Identifies clear development opportunities
4. Notes any differences across rater groups (managers vs peers vs direct reports)
5. Closes with a recommended development focus

Be balanced, specific, and actionable. Protect anonymity — do not reference specific individuals.
Output plain text paragraphs only.
PROMPT;

            $request = new AgentRequest(
                prompt        : $prompt,
                module        : 'performance_appraisal',
                context       : ['report' => $report],
                systemPrompt  : 'You are an expert HR practitioner and executive coach specialising in 360-degree performance feedback. Be evidence-based and constructive.',
                temperature   : 0.3,
                maxTokens     : 2000,
            );

            $response = $this->agents->for('performance_appraisal')->ask($request);

            if ($response->failed()) {
                throw new \RuntimeException($response->errorMessage);
            }

            $summary = trim($response->content);

            // Persist the summary
            $review->update(['ai_summary' => $summary]);

            return $summary;

        } catch (\Throwable $e) {
            Log::warning('Pa360Report: AI summary failed', ['review_id' => $review->id, 'error' => $e->getMessage()]);

            return $this->ruleBasedSummary($report);
        }
    }

    private function ruleBasedSummary(array $report): string
    {
        $lines = ["360 Degree Appraisal Summary — {$report['title']}"];
        $lines[] = "Based on {$report['submitted_count']} rater submission(s) ({$report['completion_rate']}% completion rate).";

        if (!empty($report['category_scores'])) {
            $scores = collect($report['category_scores'])->sortDesc();
            $topCat = $scores->keys()->first();
            $lowCat = $scores->keys()->last();
            $lines[] = "Highest-rated area: {$topCat} ({$scores->first()}/5). Area for development: {$lowCat} ({$scores->last()}/5).";
        }

        return implode("\n\n", $lines);
    }
}
