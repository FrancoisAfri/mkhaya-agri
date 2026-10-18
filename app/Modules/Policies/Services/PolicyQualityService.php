<?php

namespace App\Modules\Policies\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyQualityAnalysis;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Log;

class PolicyQualityService
{
    public function __construct(private readonly AgentRegistry $agents) {}

    /**
     * Run AI quality analysis for a single policy.
     * Creates (or replaces) a PolicyQualityAnalysis record and returns it.
     */
    public function analyse(Policy $policy, int $userId): PolicyQualityAnalysis
    {
        $policy->load(['clauses', 'rules.conditions']);

        $analysis = PolicyQualityAnalysis::create([
            'policy_id'   => $policy->id,
            'analysed_by' => $userId,
            'status'      => 'pending',
        ]);

        try {
            $prompt = $this->buildPrompt($policy);

            $response = $this->agents->for('policies')->ask(new AgentRequest(
                prompt        : $prompt,
                module        : 'policies.quality-analysis',
                systemPrompt  : 'You are a senior legal and compliance editor. Return ONLY valid JSON matching the schema provided. No markdown, no prose outside JSON.',
                temperature   : 0.2,
                maxTokens     : 3500,
                responseFormat: 'json',
            ));

            if ($response->failed()) {
                throw new \RuntimeException($response->errorMessage ?? 'AI agent returned a failure response.');
            }

            $payload = $response->toArray();

            $suggestions = $this->normaliseSuggestions($payload['suggestions'] ?? []);
            $summary     = $payload['summary'] ?? '';

            $analysis->update([
                'status'       => 'completed',
                'suggestions'  => $suggestions,
                'summary'      => $summary,
                'issue_count'  => count($suggestions),
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('PolicyQualityService failed', [
                'policy_id' => $policy->id,
                'error'     => $e->getMessage(),
            ]);

            $analysis->update([
                'status'      => 'failed',
                'suggestions' => [],
                'summary'     => 'Analysis failed: ' . $e->getMessage(),
            ]);
        }

        return $analysis->fresh();
    }

    // ── Prompt construction ───────────────────────────────────────────────────

    private function buildPrompt(Policy $policy): string
    {
        $clauses = $policy->clauses->map(fn($c) =>
            "[{$c->clause_reference}] ({$c->type}): " . mb_substr($c->content, 0, 400)
        )->implode("\n");

        $rules = $policy->rules->map(fn($r) =>
            "Rule \"{$r->name}\" (severity: {$r->severity}, breach action: {$r->action_on_breach}): " . mb_substr($r->description ?? $r->natural_language_input ?? '', 0, 300)
        )->implode("\n");

        $clauseBlock = $clauses ?: '(no clauses extracted yet)';
        $ruleBlock   = $rules   ?: '(no rules defined yet)';

        return <<<PROMPT
You are reviewing the policy "{$policy->name}" for quality issues.

## Policy Description
{$policy->description}

## Extracted Clauses
{$clauseBlock}

## Defined Rules
{$ruleBlock}

## Task
Analyse this policy for the following issues and return a JSON object:

1. **Ambiguous clauses** – clauses that are vague, use undefined terms, or leave too much to interpretation.
2. **Better wording** – clauses or rules that could be rewritten for clarity, active voice, or brevity.
3. **Missing rules** – obvious enforcement rules that should exist given the clauses but are absent.
4. **Redundant content** – clauses or rules that repeat the same requirement unnecessarily.
5. **Weak enforcement** – rules where the breach action (warn/log_only) seems insufficient given the severity.

## Required JSON Schema
```json
{
  "summary": "A 2-3 sentence executive summary of the policy's quality.",
  "suggestions": [
    {
      "type": "ambiguous_clause | better_wording | missing_rule | redundant_content | weak_enforcement",
      "severity": "low | medium | high",
      "clause_reference": "e.g. 3.1 or Rule: Overtime Limit",
      "description": "Clear description of the issue.",
      "suggested_text": "Rewritten version or recommended addition (leave empty if not applicable)."
    }
  ]
}
```

Return only the JSON object. No markdown fences. No extra text.
PROMPT;
    }

    // ── Normalisation ─────────────────────────────────────────────────────────

    private function normaliseSuggestions(array $raw): array
    {
        $validTypes      = ['ambiguous_clause', 'better_wording', 'missing_rule', 'redundant_content', 'weak_enforcement'];
        $validSeverities = ['low', 'medium', 'high'];

        return collect($raw)
            ->filter(fn($s) => is_array($s) && ! empty($s['description']))
            ->map(fn($s) => [
                'type'             => in_array($s['type'] ?? '', $validTypes) ? $s['type'] : 'ambiguous_clause',
                'severity'         => in_array($s['severity'] ?? '', $validSeverities) ? $s['severity'] : 'medium',
                'clause_reference' => trim((string) ($s['clause_reference'] ?? '')),
                'description'      => trim((string) ($s['description'] ?? '')),
                'suggested_text'   => trim((string) ($s['suggested_text'] ?? '')),
            ])
            ->values()
            ->toArray();
    }
}
