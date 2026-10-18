<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Log;

class PaFeedbackSummaryService
{
    public function __construct(protected AgentRegistry $agents) {}

    /**
     * Generate an AI-written summary of all feedback received by an employee in a cycle.
     */
    public function generateSummary(int $employeeId, ?int $cycleId = null): string
    {
        $query = PaFeedback::where('employee_id', $employeeId)
            ->where('is_draft', false)
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->with('author')
            ->orderBy('feedback_type')
            ->get();

        if ($query->isEmpty()) {
            return 'No feedback has been submitted yet.';
        }

        $grouped = $query->groupBy('feedback_type')->map(fn($items, $type) =>
            $items->map(fn($f) => [
                'type'    => $type,
                'rating'  => $f->rating,
                'content' => $f->content,
                'author'  => $f->is_anonymous ? 'Anonymous' : ($f->author?->email ?? 'Unknown'),
            ])->values()->toArray()
        )->toArray();

        try {
            $prompt = <<<PROMPT
You are an HR professional writing a performance feedback summary for a manager.

Below is all the feedback received by an employee, grouped by type (manager/peer/upward/self).
Write a balanced, constructive 3-4 paragraph summary that:
1. Highlights key strengths mentioned across feedback sources
2. Identifies development areas
3. Notes any patterns or themes
4. Provides an overall impression

Be professional and specific. Do not mention individual names. Output plain text only — no markdown headers.
PROMPT;

            $request = new AgentRequest(
                prompt        : $prompt,
                module        : 'performance_appraisal',
                context       : ['feedback' => $grouped],
                systemPrompt  : 'You are an expert HR practitioner summarising 360-style performance feedback. Be objective, balanced, and constructive.',
                temperature   : 0.3,
                maxTokens     : 1500,
            );

            $response = $this->agents->for('performance_appraisal')->ask($request);

            if ($response->failed()) {
                throw new \RuntimeException($response->errorMessage);
            }

            return trim($response->content);

        } catch (\Throwable $e) {
            Log::warning('PaFeedbackSummary: AI call failed, using rule-based summary', ['error' => $e->getMessage()]);

            return $this->ruleSummary($grouped);
        }
    }

    private function ruleSummary(array $grouped): string
    {
        $lines = [];
        $totalRatings = collect($grouped)->flatten(1)->whereNotNull('rating');
        $avgRating    = $totalRatings->avg('rating');

        if ($avgRating !== null) {
            $lines[] = "Overall average rating: " . round($avgRating, 1) . "/5.";
        }

        foreach ($grouped as $type => $items) {
            $typeAvg = collect($items)->whereNotNull('rating')->avg('rating');
            $count   = count($items);
            $label   = ucfirst($type);
            $avgStr  = $typeAvg !== null ? ' (avg ' . round($typeAvg, 1) . '/5)' : '';
            $lines[] = "{$label} feedback: {$count} submission(s){$avgStr}.";
        }

        return implode(' ', $lines);
    }
}
