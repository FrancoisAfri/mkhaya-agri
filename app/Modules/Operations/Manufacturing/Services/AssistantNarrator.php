<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Tenancy\Exceptions\AiQuotaExceededException;
use App\Modules\Tenancy\Services\AiQuota;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Words an assistant answer (MFG-019) -- and nothing more.
 *
 * The model is given the computed answer and told to use only its figures. What comes back is
 * checked: if it contains any number that is not in the answer, it is discarded and the plain
 * summary is used instead, so a model can never put an invented figure in front of a user. The
 * call goes through the company's AI allowance; when that is spent or the providers fail, the
 * plain summary is used and the answer is no less correct.
 */
class AssistantNarrator
{
    private const SYSTEM = <<<'TXT'
You are the manufacturing assistant of a company's ERP. You are given an answer that has already been
computed from the company's records, as JSON. Write a short plain-English reply (at most 120 words) for a
production manager. Use ONLY numbers that appear in the JSON, written exactly as they appear there. Do not
calculate new numbers, percentages or totals. Say which period the figures cover. Keep "actual",
"forecast", "estimate" and "recommendation" distinct. Mention every data warning. Do not claim any saving
that the JSON does not state as realised. If the JSON has no figures, say so plainly.
TXT;

    public function __construct(private readonly AiQuota $quota, private readonly AgentRegistry $agents) {}

    /** @return array{text: string, source: string} source MODEL or TEMPLATE */
    public function narrate(array $answer): array
    {
        $plain = ['text' => $this->template($answer), 'source' => 'TEMPLATE'];
        if ($answer['refused'] || $answer['topic'] === null || ! config('manufacturing.assistant_narration', true)) {
            return $plain;
        }

        $facts = json_encode(array_intersect_key($answer, array_flip(['title', 'period', 'columns', 'sources', 'flags', 'summary']))
            + ['rows' => array_map(fn ($r) => array_diff_key($r, array_flip(['url', 'records', 'item_id', 'order_id', 'centre_id', 'supplier_id', 'stock_item_id', 'short'])), array_slice($answer['rows'], 0, 10)),
                'recommendations' => array_column($answer['recommendations'], 'text')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $this->quota->ensureWithinQuota();
            $response = $this->agents->forTask('manufacturing', 'assistant')->ask(new AgentRequest(
                prompt: $facts, module: 'manufacturing.assistant', systemPrompt: self::SYSTEM, temperature: 0.1, maxTokens: 400,
                userId: (string) Auth::id(),
            ));
        } catch (AiQuotaExceededException) {
            return $plain;
        } catch (\Throwable) {
            return $plain;
        }

        if ($response->failed() || trim($response->content) === '' || ! $this->grounded($response->content, $facts)) {
            return $plain;
        }

        return ['text' => trim($response->content), 'source' => 'MODEL'];
    }

    /** Every number in the wording must be one of the numbers in the facts. */
    public function grounded(string $text, string $facts): bool
    {
        $known = [];
        preg_match_all('/-?\d[\d,]*(?:\.\d+)?/', $facts, $m);
        foreach ($m[0] as $n) {
            $known[] = (float) str_replace(',', '', $n);
        }
        preg_match_all('/-?\d[\d,]*(?:\.\d+)?/', $text, $m);
        foreach ($m[0] as $n) {
            $v = (float) str_replace(',', '', $n);
            $hit = false;
            foreach ($known as $k) {
                if (abs($k - $v) < 0.005 || abs(abs($k) - abs($v)) < 0.005) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    public function template(array $answer): string
    {
        $parts = [$answer['summary']];
        if ($answer['period']) {
            $parts[] = "Period: {$answer['period']['from']} to {$answer['period']['to']}.";
        }
        foreach ($answer['flags'] as $f) {
            $parts[] = ucfirst(strtolower($f['level'])).': '.$f['message'];
        }

        return implode(' ', $parts);
    }
}
