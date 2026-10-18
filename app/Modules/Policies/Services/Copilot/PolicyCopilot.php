<?php

namespace App\Modules\Policies\Services\Copilot;

use App\Core\AI\Services\ERPCopilotService;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyClause;
use App\Modules\Policies\Models\PolicyRule;
use Illuminate\Support\Facades\Log;

/**
 * PolicyCopilot
 * 
 * Intelligent policy assistant using RAG (Retrieval Augmented Generation):
 * - Answer policy questions with context
 * - Explain policy clauses
 * - Search policies by content
 * - Provide policy recommendations
 */
class PolicyCopilot
{
    private ERPCopilotService $mkhayaCopilot;
    private const MAX_CONTEXT_LENGTH = 8000; // tokens

    public function __construct(ERPCopilotService $mkhayaCopilot)
    {
        $this->mkhayaCopilot = $mkhayaCopilot;
    }

    /**
     * Ask a question about policies
     * 
     * @param string $question
     * @param int|null $policyId
     * @return array
     */
    public function askQuestion(string $question, ?int $policyId = null): array
    {
        try {
            // Retrieve relevant policy context
            $context = $this->retrieveContext($question, $policyId);

            if (empty($context['policies'])) {
                return [
                    'answer' => 'I could not find relevant policies to answer your question.',
                    'confidence' => 0,
                    'references' => [],
                ];
            }

            // Build prompt with policy context and send it to Mkhaya internal bot
            $prompt = $this->buildPrompt($question, $context);

            $response = $this->mkhayaCopilot->chat($prompt);
            $answer = (string) data_get($response, 'message.content', 'I encountered an error processing your question. Please try again.');

            return [
                'answer' => $answer,
                'confidence' => $this->calculateConfidence($answer, $context),
                'references' => $context['references'],
                'policies_consulted' => $context['policy_ids'],
            ];
        } catch (\Exception $e) {
            Log::error('PolicyCopilot error: ' . $e->getMessage());

            return [
                'answer' => 'I encountered an error processing your question. Please try again.',
                'error' => $e->getMessage(),
                'confidence' => 0,
            ];
        }
    }

    /**
     * Get policy information
     * 
     * @param int $policyId
     * @return array
     */
    public function getPolicy(int $policyId): array
    {
        $policy = Policy::with('clauses', 'rules')->findOrFail($policyId);

        return [
            'id' => $policy->id,
            'name' => $policy->name,
            'description' => $policy->description,
            'purpose' => $policy->purpose,
            'effective_date' => $policy->effective_date,
            'clauses' => $policy->clauses->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'content' => $c->content,
            ])->toArray(),
            'rules' => $policy->rules->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'condition' => $r->condition,
                'consequence' => $r->consequence,
            ])->toArray(),
        ];
    }

    /**
     * Search policies by query
     * 
     * @param string $query
     * @return array
     */
    public function searchPolicies(string $query): array
    {
        $policies = Policy::where('name', 'LIKE', "%{$query}%")
            ->orWhere('description', 'LIKE', "%{$query}%")
            ->with('clauses')
            ->limit(10)
            ->get();

        $clauses = PolicyClause::where('title', 'LIKE', "%{$query}%")
            ->orWhere('content', 'LIKE', "%{$query}%")
            ->with('policy')
            ->limit(10)
            ->get();

        return [
            'policies' => $policies->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
            ])->toArray(),
            'clauses' => $clauses->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'policy' => $c->policy->name,
                'policy_id' => $c->policy_id,
                'excerpt' => substr($c->content, 0, 200) . '...',
            ])->toArray(),
            'total' => $policies->count() + $clauses->count(),
        ];
    }

    /**
     * Get policies with clauses that match a topic
     * 
     * @param string $topic
     * @return array
     */
    public function getPoliciesByTopic(string $topic): array
    {
        $policies = Policy::where('name', 'LIKE', "%{$topic}%")
            ->orWhere('purpose', 'LIKE', "%{$topic}%")
            ->orWhereHas('clauses', function ($q) use ($topic) {
                $q->where('title', 'LIKE', "%{$topic}%")
                  ->orWhere('content', 'LIKE', "%{$topic}%");
            })
            ->with('clauses', 'rules')
            ->get();

        return $policies->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'clauses' => $p->clauses->map(fn ($c) => [
                'title' => $c->title,
                'content' => substr($c->content, 0, 300),
            ])->toArray(),
        ])->toArray();
    }

    /**
     * Retrieve relevant context for question
     * 
     * @param string $question
     * @param int|null $policyId
     * @return array
     */
    private function retrieveContext(string $question, ?int $policyId = null): array
    {
        $query = Policy::query();

        if ($policyId) {
            $query->where('id', $policyId);
        }

        // Search for relevant policies
        $policies = $query->with(['clauses', 'rules'])
            ->where(function ($q) use ($question) {
                $q->where('name', 'LIKE', "%{$this->extractKeywords($question)[0]}%")
                  ->orWhere('description', 'LIKE', "%{$this->extractKeywords($question)[0]}%");
            })
            ->limit(5)
            ->get();

        // If no policies found, get top policies
        if ($policies->isEmpty()) {
            $policies = Policy::with('clauses', 'rules')->limit(3)->get();
        }

        // Build context string
        $references = [];
        $context = "POLICY CONTEXT:\n\n";
        $policyIds = [];

        foreach ($policies as $policy) {
            $policyIds[] = $policy->id;
            $context .= "## Policy: {$policy->name}\n";
            $context .= "{$policy->description}\n\n";

            foreach ($policy->clauses as $clause) {
                $context .= "### {$clause->title}\n";
                $context .= "{$clause->content}\n\n";

                $references[] = [
                    'policy' => $policy->name,
                    'clause' => $clause->title,
                    'policy_id' => $policy->id,
                    'clause_id' => $clause->id,
                ];
            }
        }

        return [
            'context' => $context,
            'references' => $references,
            'policies' => $policies,
            'policy_ids' => $policyIds,
        ];
    }

    /**
     * Build prompt for AI
     * 
     * @param string $question
     * @param array $context
     * @return string
     */
    private function buildPrompt(string $question, array $context): string
    {
        return <<<PROMPT
You are a helpful policy advisor. Answer the user's question using ONLY the provided policy context.

{$context['context']}

USER QUESTION: {$question}

Provide a clear, concise answer. If the answer is not in the provided policies, say so. Include relevant policy references.
PROMPT;
    }

    /**
     * Get system prompt for copilot
     * 
     * @return string
     */
    private function getSystemPrompt(): string
    {
        return <<<PROMPT
You are an intelligent policy advisor assistant. Your role is to help users understand company policies clearly and accurately.

Guidelines:
1. Only use information from the provided policy context
2. Be clear and concise
3. Cite specific policy clauses when relevant
4. If something is not covered in policies, acknowledge this
5. For compliance questions, be cautious and recommend consulting HR if unsure
6. Use friendly, professional language
7. Provide actionable guidance when possible

If a user asks about policies you don't have information about, suggest they contact the HR or Compliance department.
PROMPT;
    }

    /**
     * Extract keywords from question
     * 
     * @param string $question
     * @return array
     */
    private function extractKeywords(string $question): array
    {
        $stop_words = ['what', 'is', 'the', 'a', 'an', 'and', 'or', 'can', 'do', 'i', 'my', 'you'];
        $words = explode(' ', strtolower($question));
        $keywords = array_filter($words, fn ($w) => !in_array($w, $stop_words) && strlen($w) > 2);

        return array_values($keywords);
    }

    /**
     * Calculate confidence level
     * 
     * @param string $answer
     * @param array $context
     * @return float
     */
    private function calculateConfidence(string $answer, array $context): float
    {
        // Base confidence on context and answer length
        $contextLength = count($context['references']);
        $answerLength = strlen($answer);

        $confidence = 0.5; // Base 50%
        $confidence += min($contextLength * 0.1, 0.3); // +0.1 per reference, max 0.3
        $confidence += ($answerLength > 200 ? 0.1 : 0); // +0.1 for detailed answer

        // Penalize if answer is vague
        if (strpos($answer, 'not sure') !== false || strpos($answer, 'unclear') !== false) {
            $confidence -= 0.2;
        }

        return min($confidence, 0.95);
    }
}
