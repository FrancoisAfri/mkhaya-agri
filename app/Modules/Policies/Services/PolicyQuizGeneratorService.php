<?php

namespace App\Modules\Policies\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyDocument;
use App\Modules\Policies\Models\PolicyQuestion;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PolicyQuizGeneratorService
{
    public function __construct(protected AgentRegistry $agents) {}

    /**
     * Generate quiz questions for a policy from its extracted text or clauses.
     *
     * @return PolicyQuestion[]  List of newly-created question models.
     */
    public function generate(Policy $policy, int $count = 10): array
    {
        $text = $this->collectPolicyText($policy);

        if (empty($text)) {
            throw new \RuntimeException(
                'Cannot generate questions: this policy has no extracted text or clauses. ' .
                'Please upload and extract a document first.'
            );
        }

        $prompt = $this->buildPrompt($text, $count, $policy->name);

        $request = new AgentRequest(
            prompt        : $prompt,
            module        : 'policy_quiz',
            context       : [],
            systemPrompt  : $this->systemPrompt(),
            temperature   : 0.4,
            maxTokens     : 4096,
            responseFormat: 'json',
            userId        : (string) Auth::id(),
        );

        $response = $this->agents->for('policy_quiz')->ask($request);

        if ($response->failed()) {
            Log::error('PolicyQuizGenerator: AI call failed', [
                'policy_id' => $policy->id,
                'error'     => $response->errorMessage,
            ]);
            throw new \RuntimeException('AI question generation failed: ' . $response->errorMessage);
        }

        $parsed = json_decode($response->content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! isset($parsed['questions'])) {
            Log::warning('PolicyQuizGenerator: unexpected AI response format', [
                'policy_id' => $policy->id,
                'content'   => substr($response->content, 0, 500),
            ]);
            throw new \RuntimeException('AI returned an unrecognised response format. Please try again.');
        }

        $saved   = [];
        $maxOrder = PolicyQuestion::where('policy_id', $policy->id)->max('sort_order') ?? 0;
        $userId   = Auth::id();

        foreach ($parsed['questions'] as $index => $q) {
            if (! $this->questionIsValid($q)) {
                continue;
            }

            $question = PolicyQuestion::create([
                'policy_id'     => $policy->id,
                'question_text' => $q['question_text'],
                'type'          => $q['type'],
                'options'       => $q['options'] ?? null,
                'correct_answer'=> (string) ($q['correct_answer'] ?? ''),
                'explanation'   => $q['explanation'] ?? null,
                'sort_order'    => $maxOrder + $index + 1,
                'points'        => max(1, (int) ($q['points'] ?? 1)),
                'ai_generated'  => true,
                'created_by'    => $userId,
            ]);

            $saved[] = $question;
        }

        return $saved;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function collectPolicyText(Policy $policy): string
    {
        // Prefer latest document extracted_text
        $doc = PolicyDocument::where('policy_id', $policy->id)
            ->whereNotNull('extracted_text')
            ->latest()
            ->first();

        if ($doc && ! empty($doc->extracted_text)) {
            return substr($doc->extracted_text, 0, 12_000);
        }

        // Fall back to assembled clause text
        $clauses = $policy->clauses()->orderBy('order')->pluck('content')->implode("\n\n");

        return substr($clauses, 0, 12_000);
    }

    private function buildPrompt(string $text, int $count, string $policyName): string
    {
        return <<<PROMPT
You are a compliance training expert. Based on the following policy document content, generate exactly {$count} quiz questions that test employee understanding of the policy \"{$policyName}\".

Return your response as a JSON object with a single key "questions" containing an array of question objects. Each question object must have:
- "question_text": The full question (string)
- "type": One of "multiple_choice", "true_false", or "short_answer"
- "options": For multiple_choice only — an array of objects with "label" (e.g. "A", "B", "C", "D") and "value" (the option text). Omit for other types.
- "correct_answer": The correct answer. For multiple_choice use the label (e.g. "A"). For true_false use "true" or "false". For short_answer provide a concise key phrase.
- "explanation": A brief explanation of why the answer is correct (1-2 sentences).
- "points": Integer point value (1 to 3, based on difficulty).

Distribution: At least 40% should be multiple_choice, at least 20% true_false, and the rest short_answer.
Make the questions practical and scenario-based where possible.

POLICY CONTENT:
{$text}
PROMPT;
    }

    private function systemPrompt(): string
    {
        return 'You are a compliance training expert who creates clear, fair, unambiguous quiz questions ' .
               'that accurately test employee understanding of company policies. ' .
               'Always return valid JSON matching the requested schema exactly.';
    }

    private function questionIsValid(mixed $q): bool
    {
        return is_array($q)
            && ! empty($q['question_text'])
            && in_array($q['type'] ?? '', ['multiple_choice', 'true_false', 'short_answer'], true)
            && isset($q['correct_answer']);
    }
}
