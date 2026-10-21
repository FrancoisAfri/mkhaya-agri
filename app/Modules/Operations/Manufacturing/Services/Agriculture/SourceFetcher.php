<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Source;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches a registered source and turns what it publishes into items, by the source's adapter:
 *
 *  - rss  — RSS 2.0 or Atom: notices, funding calls, advisories, prices.
 *  - cap  — the Common Alerting Protocol (an alert, or an Atom/RSS feed of them), which weather and
 *           disaster services publish warnings in; each area's circle or polygon becomes a centre
 *           and radius the regional-warning matcher uses.
 *  - json — any JSON API, through a field map in the source's config ({"list": "data.items",
 *           "map": {"external_id": "id", "title": "headline", ...}}).
 *
 * Each item is then handled as its kind says: a warning goes to the farms inside it, an outbreak to
 * exposure assessment, a funding call to opportunity matching, anything else is published as is.
 * External XML is parsed without network access or entity expansion; everything is size-capped.
 */
class SourceFetcher
{
    public const MAX_BYTES = 5_000_000;

    public function __construct(
        private readonly Sources $sources,
        private readonly RegionalWarnings $warnings,
        private readonly Outbreaks $outbreaks,
        private readonly Opportunities $opportunities,
    ) {}

    /** Fetch every source that is due. @return array<string, array> */
    public function fetchDue(): array
    {
        $out = [];
        foreach ($this->sources->due()->whereNotNull('adapter')->whereNotNull('url') as $source) {
            $out[$source->code] = $this->fetch($source);
        }

        return $out;
    }

    public function fetch(Source $source): array
    {
        return $this->sources->attempt($source, fn (Source $s) => $this->items($s), null, fn (array $item) => $this->handle($source, $item));
    }

    /** @return list<array> */
    public function items(Source $source): array
    {
        $request = Http::timeout(20)->withHeaders(['User-Agent' => 'Mkhaya Agri production intelligence', 'Accept' => '*/*']);
        $auth = $source->auth();
        $query = [];
        match ($auth['type'] ?? null) {
            'header' => $request = $request->withHeaders([$auth['name'] ?? 'Authorization' => $auth['value'] ?? '']),
            'bearer' => $request = $request->withToken($auth['value'] ?? ''),
            'basic' => $request = $request->withBasicAuth($auth['username'] ?? '', $auth['password'] ?? ''),
            'query' => $query = [$auth['name'] ?? 'key' => $auth['value'] ?? ''],
            default => null,
        };
        $response = $request->get($source->url, $query);
        if (! $response->successful()) {
            throw new RuntimeException("{$source->url} answered {$response->status()}.");
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('The feed is larger than '.(self::MAX_BYTES / 1_000_000).' MB.');
        }

        return match ($source->adapter) {
            'rss' => $this->rss($body),
            'cap' => $this->cap($body, (array) ($source->config ?? [])),
            'json' => $this->json($body, (array) ($source->config ?? [])),
            default => throw new RuntimeException("Unknown adapter {$source->adapter}."),
        };
    }

    public function handle(Source $source, array $item): void
    {
        $kind = $item['kind'] ?? $source->item_kind ?? 'notice';
        match ($kind) {
            // The matcher's kind is the weather (heat, frost, storm …); the item's is what to do with it.
            'warning' => isset($item['latitude'], $item['longitude'])
                ? $this->warnings->apply($source, ['kind' => $item['warning_kind'] ?? 'storm', 'message' => $item['message'] ?? $item['body'] ?? $item['title'] ?? ''] + $item
                    + ['radius_km' => 50, 'severity' => 'moderate', 'starts_at' => now()->toIso8601String()])
                : $this->sources->publish($source, ['kind' => 'warning'] + $item),
            'outbreak' => $this->outbreaks->report($source, $item + ['status' => 'suspected', 'disease' => $item['title'] ?? 'Reported disease']),
            'funding' => $this->opportunities->register($source, $item),
            'regulation' => app(RegulatoryNotices::class)->publish($source, $item),
            default => $this->sources->publish($source, $item + ['kind' => $kind]),
        };
    }

    /** @return list<array> */
    public function rss(string $xml): array
    {
        $doc = $this->xml($xml);
        $items = [];
        foreach ($doc->channel->item ?? [] as $i) {                                   // RSS 2.0
            $items[] = ['external_id' => (string) ($i->guid ?: $i->link ?: md5((string) $i->title)), 'title' => (string) $i->title,
                'body' => (string) $i->description, 'url' => (string) $i->link, 'published_at' => $this->date((string) $i->pubDate)];
        }
        foreach ($doc->entry ?? [] as $e) {                                          // Atom
            $link = '';
            foreach ($e->link as $l) {
                $link = $link ?: (string) $l['href'];
            }
            $items[] = ['external_id' => (string) ($e->id ?: $link), 'title' => (string) $e->title, 'body' => (string) ($e->summary ?: $e->content),
                'url' => $link, 'published_at' => $this->date((string) ($e->updated ?: $e->published))];
        }

        return $items;
    }

    /** @return list<array> */
    public function cap(string $xml, array $config = []): array
    {
        $doc = $this->xml($xml);
        $alerts = [];
        if ($doc->getName() === 'alert') {
            $alerts[] = $doc;
        } else {
            // A feed of alerts: the CAP alert is embedded in each entry or item.
            foreach ([...iterator_to_array($doc->entry ?? [], false), ...iterator_to_array($doc->channel->item ?? [], false)] as $entry) {
                foreach ($entry->children('urn:oasis:names:tc:emergency:cap:1.2') as $child) {
                    if ($child->getName() === 'alert') {
                        $alerts[] = $child;
                    }
                }
            }
        }
        $items = [];
        foreach ($alerts as $a) {
            $a = $this->capNs($a);
            if (in_array(strtolower((string) $a->status), ['test', 'exercise', 'draft'], true) || strtolower((string) $a->msgType) === 'cancel') {
                continue;
            }
            foreach ($a->info as $n => $info) {
                [$lat, $lng, $radius] = $this->capArea($info->area) + [null, null, null];
                $event = (string) $info->event;
                $items[] = ['external_id' => (string) $a->identifier.(count($a->info) > 1 ? "#{$n}" : ''), 'kind' => 'warning',
                    'title' => (string) ($info->headline ?: $event), 'message' => trim((string) $info->description.' '.(string) $info->instruction),
                    'body' => (string) $info->description, 'severity' => strtolower((string) $info->severity) ?: 'moderate', 'kind_detail' => $event,
                    'warning_kind' => $this->warningKind($event), 'starts_at' => $this->date((string) ($info->onset ?: $info->effective ?: $a->sent)) ?? now()->toIso8601String(),
                    'ends_at' => $this->date((string) $info->expires), 'latitude' => $lat, 'longitude' => $lng,
                    'radius_km' => $radius ?? (float) ($config['default_radius_km'] ?? 50), 'url' => (string) $info->web];
            }
        }

        return $items;
    }

    /** @return list<array> */
    public function json(string $body, array $config): array
    {
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $list = isset($config['list']) ? data_get($data, $config['list'], []) : $data;
        $map = (array) ($config['map'] ?? []);

        return array_values(array_map(function ($row) use ($map, $config) {
            $item = [];
            foreach ($map as $to => $from) {
                $v = data_get($row, $from);
                if ($v !== null) {
                    Arr::set($item, $to, $v);
                }
            }
            if (isset($config['kind'])) {
                $item['kind'] ??= $config['kind'];
            }
            $item['external_id'] = (string) ($item['external_id'] ?? md5(json_encode($row)));

            return $item;
        }, is_array($list) ? $list : []));
    }

    private function xml(string $xml): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
            if ($doc === false) {
                throw new RuntimeException('The feed is not valid XML.');
            }

            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /** CAP elements may sit in the CAP namespace; read them without it. */
    private function capNs(\SimpleXMLElement $a): \SimpleXMLElement
    {
        $ns = $a->getNamespaces(true);
        $capNs = collect($ns)->first(fn ($u) => str_contains($u, 'emergency:cap'));

        return $capNs && ! isset($a->identifier) ? $a->children($capNs) : $a;
    }

    /** Centre and radius of a CAP area: a circle ("lat,lng radius_km"), or a polygon's centroid and furthest point. */
    private function capArea($areas): array
    {
        foreach ($areas ?? [] as $area) {
            foreach ($area->circle ?? [] as $c) {
                if (preg_match('/(-?[\d.]+)\s*,\s*(-?[\d.]+)\s+([\d.]+)/', (string) $c, $m)) {
                    return [(float) $m[1], (float) $m[2], max(1.0, (float) $m[3])];
                }
            }
            foreach ($area->polygon ?? [] as $p) {
                $pts = array_values(array_filter(array_map(fn ($pair) => array_map('floatval', explode(',', trim($pair))), preg_split('/\s+/', trim((string) $p))), fn ($x) => count($x) === 2));
                if (count($pts) >= 3) {
                    $lat = array_sum(array_column($pts, 0)) / count($pts);
                    $lng = array_sum(array_column($pts, 1)) / count($pts);
                    $r = max(array_map(fn ($x) => Outbreaks::distanceKm($lat, $lng, $x[0], $x[1]), $pts));

                    return [$lat, $lng, max(1.0, round($r, 1))];
                }
            }
        }

        return [];
    }

    private function warningKind(string $event): string
    {
        $e = strtolower($event);

        return match (true) {
            str_contains($e, 'heat') || str_contains($e, 'high temperature') => 'heat',
            str_contains($e, 'frost') || str_contains($e, 'cold') || str_contains($e, 'snow') => 'frost',
            str_contains($e, 'wind') => 'wind',
            str_contains($e, 'rain') || str_contains($e, 'flood') => 'rain',
            str_contains($e, 'fire') => 'fire',
            default => 'storm',
        };
    }

    private function date(string $s): ?string
    {
        $t = strtotime(trim($s));

        return $t ? date(DATE_ATOM, $t) : null;
    }
}
