<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Publication;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Source;
use Illuminate\Support\Carbon;

/**
 * The registry of outside information — weather services, veterinary and plant-health
 * authorities, government and NGO programmes, markets — and the way anything from them is let in.
 *
 * A fetch that fails is retried with a growing wait (2, 4, 8 … minutes, at most six hours) and
 * the source is marked failing; what it published before stays, labelled with how old it is.
 * Nothing is deleted because a provider is down.
 *
 * What a source publishes is data, never instructions. It is reduced to plain text — markup,
 * scripts and control characters removed — and checked for text that tries to instruct a reader
 * or an AI ("ignore previous instructions", "open the valve"). Such an item is kept, flagged and
 * shown as flagged; no agent acts on it.
 */
class Sources
{
    public const INJECTION = [
        '/ignore (all |any )?(previous|prior|above) (instructions|prompts?)/i',
        '/disregard (the )?(system|previous) (prompt|instructions)/i',
        '/you are now/i',
        '/\b(system|assistant)\s*:/i',
        '/(open|close|shut|turn (on|off)) (the )?(valve|fan|heater|feeder|water|lights?)/i',
        '/(send|execute|run) (a |the )?command/i',
        '/<\s*(script|iframe|object|embed)/i',
        '/javascript:/i',
    ];

    /**
     * Run a fetch for a source and record how it went.
     *
     * @param  callable(Source): iterable<array>  $fetch
     * @return array{ok: bool, items: int, error?: string}
     */
    public function attempt(Source $source, callable $fetch, ?Carbon $now = null): array
    {
        $now ??= now();
        if ($source->status === 'paused') {
            return ['ok' => false, 'items' => 0, 'error' => 'Source is paused.'];
        }
        $source->update(['last_attempt_at' => $now]);
        try {
            $items = 0;
            foreach ($fetch($source) as $item) {
                $this->publish($source, $item);
                $items++;
            }
            $source->update(['status' => 'active', 'last_success_at' => $now, 'failure_count' => 0, 'last_error' => null,
                'next_attempt_at' => $now->copy()->addMinutes(max(1, $source->refresh_minutes))]);

            return ['ok' => true, 'items' => $items];
        } catch (\Throwable $e) {
            $failures = $source->failure_count + 1;
            $wait = min(360, 2 ** min($failures, 9));
            $source->update(['failure_count' => $failures, 'last_error' => mb_strimwidth($e->getMessage(), 0, 250),
                'status' => 'failing', 'next_attempt_at' => $now->copy()->addMinutes($wait)]);

            return ['ok' => false, 'items' => 0, 'error' => $e->getMessage()];
        }
    }

    /** Sources due for a fetch: never fetched, or past their next attempt. */
    public function due(?Carbon $now = null)
    {
        $now ??= now();

        return Source::query()->where('status', '!=', 'paused')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))->get();
    }

    /** One published item, stored once per external id; a changed item is a new revision of the same row. */
    public function publish(Source $source, array $item): Publication
    {
        $title = $this->clean((string) ($item['title'] ?? ''), 255);
        $body = $this->clean((string) ($item['body'] ?? ''), 20000);
        $flags = $this->flags(($item['title'] ?? '').' '.($item['body'] ?? ''));
        $hash = hash('sha256', $title."\n".$body);
        $url = filter_var($item['url'] ?? null, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', (string) $item['url']) ? mb_strimwidth($item['url'], 0, 500) : null;
        $existing = Publication::query()->where('source_id', $source->id)->where('external_id', (string) $item['external_id'])->first();
        if ($existing) {
            if ($existing->content_hash !== $hash) {
                $existing->update(['title' => $title, 'body' => $body, 'url' => $url, 'content_hash' => $hash, 'revision' => $existing->revision + 1,
                    'flagged' => (bool) $flags, 'flags' => $flags ?: null, 'fetched_at' => now()]);
            } else {
                $existing->update(['fetched_at' => now()]);
            }

            return $existing->fresh();
        }

        return Publication::create(['company_id' => $source->company_id, 'source_id' => $source->id, 'external_id' => (string) $item['external_id'],
            'kind' => $item['kind'] ?? 'notice', 'title' => $title ?: '(untitled)', 'body' => $body, 'url' => $url,
            'published_at' => isset($item['published_at']) ? Carbon::parse($item['published_at']) : null, 'fetched_at' => now(),
            'content_hash' => $hash, 'flagged' => (bool) $flags, 'flags' => $flags ?: null]);
    }

    /** Plain text: tags and their script or style content removed, entities decoded, control characters gone. */
    public function clean(string $text, int $max): string
    {
        $text = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is', ' ', $text) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\p{Cf}/u', '', $text) ?? '';
        $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');

        return mb_strimwidth($text, 0, $max);
    }

    /** @return list<string> what in the text looks like an instruction rather than information */
    public function flags(string $raw): array
    {
        $out = [];
        foreach (self::INJECTION as $pattern) {
            if (preg_match($pattern, $raw, $m)) {
                $out[] = 'Contains "'.mb_strimwidth(trim($m[0]), 0, 60).'" — treated as text, not an instruction.';
            }
        }

        return array_values(array_unique($out));
    }
}
