<?php

namespace App\Modules\Agri\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Operations\Manufacturing\Services\AssistantNarrator;
use App\Modules\Tenancy\Services\AiQuota;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Words an assistant answer for a farmer — and nothing more. The same guard as the manufacturing
 * assistant (it reuses its grounding check): a reply containing any number not in the computed
 * answer is discarded for the plain summary. Goes through the organisation's AI allowance; when
 * that is spent or no provider answers, the plain summary is used and the answer is no less right.
 */
class AgriNarrator
{
    private const SYSTEM = <<<'TXT'
You are the farm assistant of an agricultural ERP. You are given an answer already computed from the
farm's own records, as JSON. Write a short, plain-English reply (at most 110 words) for a farmer or farm
manager. Use ONLY numbers that appear in the JSON, exactly as written. Do not calculate new numbers.
Mention every data warning. You cannot change records: if action is needed, say who should do what on
which screen. If the JSON has no rows, say so plainly.
TXT;

    public function __construct(private readonly AiQuota $quota, private readonly AgentRegistry $agents, private readonly AssistantNarrator $guard) {}

    /** @return array{text: string, source: string} */
    public function narrate(array $answer): array
    {
        $plain = ['text' => $this->template($answer), 'source' => 'TEMPLATE'];
        if ($answer['refused'] || $answer['topic'] === null || empty($answer['rows']) || ! config('agri.assistant_narration', true)) {
            return $plain;
        }

        $facts = json_encode(array_intersect_key($answer, array_flip(['title', 'period', 'columns', 'sources', 'flags', 'summary', 'farms']))
            + ['rows' => array_map(fn ($r) => array_diff_key($r, ['url' => 1]), array_slice($answer['rows'], 0, 12))],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $this->quota->ensureWithinQuota();
            $response = $this->agents->forTask('agri', 'assistant')->ask(new AgentRequest(
                prompt: $facts, module: 'agri.assistant', systemPrompt: self::SYSTEM, temperature: 0.1, maxTokens: 350,
                userId: (string) Auth::id(),
            ));
        } catch (\Throwable) {
            return $plain;
        }

        if ($response->failed() || trim($response->content) === '' || ! $this->guard->grounded($response->content, $facts)) {
            return $plain;
        }

        return ['text' => trim($response->content), 'source' => 'MODEL'];
    }

    public function template(array $answer): string
    {
        $parts = [$answer['summary']];
        foreach ($answer['flags'] ?? [] as $f) {
            $parts[] = ucfirst(strtolower($f['level'])).': '.$f['message'];
        }

        return implode(' ', $parts);
    }
}
