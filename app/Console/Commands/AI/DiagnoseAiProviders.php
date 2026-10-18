<?php

namespace App\Console\Commands\AI;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Which AI providers actually answer, and what the ones that don't are complaining about.
 *
 * The chain is nineteen providers deep so that no single outage stops the product. When every one
 * of them fails at once the design has nothing left to say, and what reaches the user is "The AI
 * writer is unavailable right now" — a sentence that fits a missing API key, an exhausted free
 * tier, a decommissioned model and a DNS failure equally well, while telling them apart is the
 * entire job.
 *
 * The detail does reach the log, but not intact: the chain reports the exception message, and an
 * HTTP exception truncates the response body to its first line. So a real answer like "the model
 * `llama-3.3-70b-versatile` does not exist" arrives as "HTTP request returned status code 404:"
 * and stops. Nineteen of those say only that everything is broken.
 *
 * This asks each provider directly and prints what it says back. On a 404 it goes one step
 * further and lists the models the provider will admit to having, because a 404 from a chat
 * endpoint almost always means the model name has moved on rather than the service being down —
 * and the fix is then a name, which is worth being handed rather than hunted.
 *
 * Read-only, and prints no keys.
 */
class DiagnoseAiProviders extends Command
{
    protected $signature = 'ai:diagnose
        {--provider= : Test one provider by name}
        {--models : List available models for every provider, not only the failing ones}';

    protected $description = 'Test each AI provider in the chain and report exactly why it fails';

    /** Long enough for a slow free tier to answer, short enough to get through nineteen. */
    private const TIMEOUT = 15;

    public function handle(): int
    {
        $cfg = config('ai.chat', []);
        $chain = (array) ($cfg['chain'] ?? []);
        $providers = (array) ($cfg['providers'] ?? []);

        if ($only = $this->option('provider')) {
            $chain = array_values(array_filter($chain, fn ($n) => $n === $only));

            if ($chain === []) {
                $this->error("No provider named [{$only}] in the chain.");

                return self::FAILURE;
            }
        }

        $this->line('');
        $this->info('── AI provider chain ' . str_repeat('─', 41));
        $this->line('');

        $working = [];
        $broken = [];
        $skipped = [];

        foreach ($chain as $name) {
            $p = $providers[$name] ?? null;

            if (! $p) {
                $skipped[$name] = 'not configured at all';

                continue;
            }

            if (! empty($p['retired'])) {
                $skipped[$name] = 'retired by its provider — skipped by the chain';

                continue;
            }

            $keyless = ! empty($p['keyless']);

            if (empty($p['base_url'])) {
                $skipped[$name] = 'no base_url';

                continue;
            }

            // The chain skips these silently, which is right in production and misleading here:
            // a provider with no key never appears in the "tried" list, so its absence from the
            // logs reads as "it worked" rather than "it was never asked".
            if (empty($p['key']) && ! $keyless) {
                $skipped[$name] = 'no API key set';

                continue;
            }

            $result = $this->probe($name, $p);

            if ($result['ok']) {
                $working[] = $name;
                $this->line(sprintf('  <fg=green>✔</> %-13s %s', $name, $result['detail']));

                continue;
            }

            $broken[$name] = $result;
            $this->line(sprintf('  <fg=red>✘</> %-13s %s', $name, $result['detail']));

            if ($result['body'] !== '') {
                $this->line(sprintf('      %s', $result['body']));
            }

            // A 404 from a chat endpoint is nearly always the model name, not the service.
            if ($result['status'] === 404 || $this->option('models')) {
                $models = $this->availableModels($p);

                if ($models !== []) {
                    $this->line(sprintf(
                        '      models it offers: %s%s',
                        implode(', ', array_slice($models, 0, 6)),
                        count($models) > 6 ? ' … (' . count($models) . ' total)' : '',
                    ));
                }
            }
        }

        $this->summarise($chain, $working, $broken, $skipped);

        return $working === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One minimal completion, reporting what came back rather than throwing it away.
     *
     * @return array{ok: bool, status: int|null, detail: string, body: string}
     */
    private function probe(string $name, array $p): array
    {
        $messages = [['role' => 'user', 'content' => 'Reply with the single word: ok']];

        try {
            $started = microtime(true);

            $response = ($p['driver'] ?? 'openai') === 'gemini'
                ? $this->callGemini($p, $messages)
                : $this->callOpenAiCompatible($p, $messages);

            $ms = (int) ((microtime(true) - $started) * 1000);
            $status = $response->status();

            if ($response->successful()) {
                $text = ($p['driver'] ?? 'openai') === 'gemini'
                    ? ($response->json('candidates.0.content.parts.0.text') ?? '')
                    : ($response->json('choices.0.message.content') ?? '');

                // A 200 carrying no text is its own failure, and one the chain treats as a miss
                // without ever logging why.
                if (trim((string) (is_array($text) ? '' : $text)) === '') {
                    return [
                        'ok' => false,
                        'status' => $status,
                        'detail' => "answered {$status} in {$ms}ms but returned no text",
                        'body' => $this->snippet($response->body()),
                    ];
                }

                return [
                    'ok' => true,
                    'status' => $status,
                    'detail' => sprintf('%s — %dms', $p['model'] ?? '?', $ms),
                    'body' => '',
                ];
            }

            return [
                'ok' => false,
                'status' => $status,
                'detail' => sprintf('HTTP %d (%s)', $status, $this->explain($status)),
                'body' => $this->snippet($response->body()),
            ];
        } catch (Throwable $e) {
            // Connection-level: DNS, TLS, timeout. No status and no body to report.
            return [
                'ok' => false,
                'status' => null,
                'detail' => 'could not be reached',
                'body' => $this->snippet($e->getMessage()),
            ];
        }
    }

    private function callOpenAiCompatible(array $p, array $messages)
    {
        $req = Http::timeout(self::TIMEOUT)->acceptJson();
        $req = empty($p['key']) ? $req : $req->withToken($p['key']);

        return $req->post(rtrim($p['base_url'], '/') . '/chat/completions', [
            'model' => $p['model'],
            'messages' => $messages,
            // Not 16. Reasoning models (gpt-oss and friends) spend their budget thinking before
            // they write, and a tight cap returns HTTP 200 with an empty string — which reads as a
            // broken provider when it is a working one that was not given room to speak.
            'max_tokens' => 512,
        ]);
    }

    private function callGemini(array $p, array $messages)
    {
        $url = rtrim($p['base_url'], '/') . "/models/{$p['model']}:generateContent?key=" . urlencode((string) $p['key']);

        return Http::timeout(self::TIMEOUT)->post($url, [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $messages[0]['content']]],
            ]],
        ]);
    }

    /**
     * What the provider says it can run, so a wrong model name becomes a correct one.
     *
     * @return list<string>
     */
    private function availableModels(array $p): array
    {
        try {
            if (($p['driver'] ?? 'openai') === 'gemini') {
                $res = Http::timeout(self::TIMEOUT)
                    ->get(rtrim($p['base_url'], '/') . '/models?key=' . urlencode((string) $p['key']));

                return collect($res->json('models') ?? [])
                    ->pluck('name')
                    ->map(fn ($n) => str_replace('models/', '', (string) $n))
                    ->values()
                    ->all();
            }

            $req = Http::timeout(self::TIMEOUT)->acceptJson();
            $req = empty($p['key']) ? $req : $req->withToken($p['key']);
            $res = $req->get(rtrim($p['base_url'], '/') . '/models');

            return collect($res->json('data') ?? [])->pluck('id')->filter()->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** The plain-language meaning of the statuses this chain actually produces. */
    private function explain(int $status): string
    {
        return match ($status) {
            401 => 'the API key is missing, wrong or revoked',
            402 => 'the account is out of credit',
            403 => 'the key is valid but not allowed to use this model',
            404 => 'the endpoint or, far more often, the model name does not exist',
            429 => 'rate limited or the free quota is spent',
            500, 502, 503 => 'the provider is having problems',
            default => 'unexpected status',
        };
    }

    /** Response bodies are JSON error objects; the message inside is the useful part. */
    private function snippet(string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $message = $decoded['error']['message']
                ?? $decoded['error']
                ?? $decoded['message']
                ?? $decoded['detail']
                ?? null;

            if (is_string($message) && $message !== '') {
                $body = $message;
            }
        }

        $body = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return mb_strlen($body) > 200 ? mb_substr($body, 0, 200) . '…' : $body;
    }

    private function summarise(array $chain, array $working, array $broken, array $skipped): void
    {
        $this->line('');
        $this->info('── Summary ' . str_repeat('─', 51));
        $this->line(sprintf(
            '  %d in the chain: %d working, %d failing, %d never tried',
            count($chain),
            count($working),
            count($broken),
            count($skipped),
        ));

        foreach ($skipped as $name => $why) {
            $this->line(sprintf('    – %-13s %s', $name, $why));
        }

        $this->line('');

        if ($working !== []) {
            $this->info('  Content generation will use: ' . $working[0]);
            $this->line('  (the chain takes the first that answers, so the order in config/ai.php decides)');

            return;
        }

        $this->error('  No provider can answer, so nothing can be generated.');
        $this->line('');

        $byStatus = collect($broken)->groupBy(fn ($r) => $r['status'] ?? 'unreachable');

        foreach ($byStatus as $status => $group) {
            $names = collect($group)->keys()->implode(', ');
            $this->line(sprintf('    %-12s %s', $status, $names));
        }

        $this->line('');
        $this->line('  404 across several providers at once usually means the model names have been');
        $this->line('  retired rather than the services being down — free tiers move fast. The model');
        $this->line('  list printed above each one is what that provider will accept today.');
    }
}
