<?php
namespace App\Modules\Policies\Actions\PolicyRule;
use App\Core\AI\Registry\AgentRegistry;
use App\Core\User\Models\User;
use App\Modules\Policies\Models\NlRuleDraft;
use Illuminate\Support\Arr;
use App\Shared\DTOs\AgentRequest;
use RuntimeException;
class ParseNaturalLanguageRuleAction {
    public function execute(int $policyId, string $input, int $userId, ?int $clauseId = null, ?AgentRegistry $agents = null): NlRuleDraft {
        $agents ??= app(AgentRegistry::class);

        $systemPrompt = 'You are a policy rule parser. Convert natural language policy rules into structured JSON only. Return this exact shape: {"name":"...","description":"...","severity":"low|medium|high","action_on_breach":"block|warn|escalate|log_only","requires_exception_approval":true|false,"conditions":[{"type":"numeric|role|date|status|department|custom","field":"...","operator":"eq|neq|gt|gte|lt|lte|in|not_in|between|contains","value":"...","value_unit":"ZAR|days|hours|null","logical_operator":"AND|OR"}],"actions":[{"type":"block|allow_with_warning|require_approval|send_notification|escalate_to_role|log_violation|trigger_workflow","message_template":"...","config":{}}]}';

        $response = $agents->for('policies')->ask(new AgentRequest(
            prompt: "Parse this policy rule into structured JSON: {$input}",
            module: 'policies.parse-rule',
            context: [
                'policy_id' => $policyId,
                'clause_id' => $clauseId,
                'input' => $input,
            ],
            systemPrompt: $systemPrompt,
            temperature: 0.1,
            maxTokens: 1800,
            responseFormat: 'json',
            userId: (string) (User::query()->whereKey($userId)->value('uuid') ?? ''),
        ));

        if ($response->failed()) {
            throw new RuntimeException($response->errorMessage ?: 'AI parser failed.');
        }

        $text = trim($response->content);

        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed)) {
            throw new RuntimeException('AI parser returned invalid JSON.');
        }

        // Enforce the minimum structure expected by downstream rule creation.
        if (!isset($parsed['name'], $parsed['severity'], $parsed['action_on_breach'])) {
            throw new RuntimeException('AI parser response is missing required keys.');
        }

        $parsed['description'] = $parsed['description'] ?? null;
        $parsed['requires_exception_approval'] = (bool) ($parsed['requires_exception_approval'] ?? false);
        $parsed['conditions'] = array_values(Arr::wrap($parsed['conditions'] ?? []));
        $parsed['actions'] = array_values(Arr::wrap($parsed['actions'] ?? []));

        $parsed['conditions'] = array_map(function (array $condition) {
            $condition['type'] = in_array($condition['type'] ?? 'custom', ['numeric','role','date','status','department','custom'], true)
                ? $condition['type']
                : 'custom';
            $condition['operator'] = in_array($condition['operator'] ?? 'eq', ['eq','neq','gt','gte','lt','lte','in','not_in','between','contains'], true)
                ? $condition['operator']
                : 'eq';
            $condition['logical_operator'] = in_array($condition['logical_operator'] ?? 'AND', ['AND','OR'], true)
                ? $condition['logical_operator']
                : 'AND';
            return $condition;
        }, $parsed['conditions']);

        $parsed['actions'] = array_map(function (array $action) {
            $action['type'] = in_array($action['type'] ?? 'log_violation', ['block','allow_with_warning','require_approval','send_notification','escalate_to_role','log_violation','trigger_workflow'], true)
                ? $action['type']
                : 'log_violation';
            $action['config'] = is_array($action['config'] ?? null) ? $action['config'] : [];
            return $action;
        }, $parsed['actions']);

        return NlRuleDraft::create([
            'policy_id'              => $policyId,
            'clause_id'              => $clauseId,
            'created_by'             => $userId,
            'natural_language_input' => $input,
            'ai_parsed_rule'         => $parsed,
            'status'                 => 'pending_review',
        ]);
    }
}
