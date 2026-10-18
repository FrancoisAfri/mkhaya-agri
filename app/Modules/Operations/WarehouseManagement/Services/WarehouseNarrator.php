<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Operations\Manufacturing\Services\AssistantNarrator;
use App\Modules\Tenancy\Services\AiQuota;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Words a warehouse answer, through the company's AI allowance -- and nothing more. The grounding
 * check is the manufacturing assistant's: any number in the wording that is not in the computed
 * answer discards the wording, and the plain summary is shown instead.
 */
class WarehouseNarrator
{
    private const SYSTEM = <<<'TXT'
You are the warehouse assistant of a company's ERP. You are given an answer already computed from the
company's records, as JSON. Write a short plain-English reply (at most 120 words) for a warehouse manager.
Use ONLY numbers that appear in the JSON, written exactly as they appear. Do not calculate new numbers.
Say which period and warehouse the figures cover. Keep facts, estimates and recommendations distinct.
Mention every data warning. Never name or blame a person. If there are no figures, say so plainly.
TXT;

    public function __construct(private readonly AiQuota $quota, private readonly AgentRegistry $agents, private readonly AssistantNarrator $checks) {}

    /** @return array{text: string, source: string, tokens: int, cost: float} */
    public function narrate(array $answer): array
    {
        $plain = ['text' => $this->checks->template($answer + ['flags' => [], 'period' => null]), 'source' => 'TEMPLATE', 'tokens' => 0, 'cost' => 0.0];
        if ($answer['refused'] || $answer['topic'] === null || ! config('warehouse.assistant_narration', true)) {
            return $plain;
        }
        $facts = json_encode(array_intersect_key($answer, array_flip(['title', 'period', 'warehouse', 'columns', 'sources', 'flags', 'summary']))
            + ['rows' => array_map(fn ($r) => array_diff_key($r, ['url' => 1]), array_slice($answer['rows'], 0, 10)),
                'recommendations' => array_column($answer['recommendations'], 'text')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $this->quota->ensureWithinQuota();
            $response = $this->agents->forTask('warehouse', 'assistant')->ask(new AgentRequest(
                prompt: $facts, module: 'warehouse.assistant', systemPrompt: self::SYSTEM, temperature: 0.1, maxTokens: 400, userId: (string) Auth::id(),
            ));
        } catch (\Throwable) {
            return $plain;
        }
        if ($response->failed() || trim($response->content) === '' || ! $this->checks->grounded($response->content, $facts)) {
            return $plain + ['tokens' => $response->totalTokens ?? 0];
        }

        return ['text' => trim($response->content), 'source' => 'MODEL', 'tokens' => $response->totalTokens, 'cost' => $this->quota->randCost($response->totalTokens)];
    }
}
