<?php

namespace App\Modules\Agri\Satellite;

use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldZone;
use App\Modules\Agri\Models\SatellitePolygon;
use App\Modules\Agri\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AgroMonitoring (OpenWeather Agro API, agromonitoring.com), the simpler alternative: each field
 * (and zone) is registered once as a polygon — again only when its boundary changes — then the
 * provider lists the scenes over it with their cloud and coverage, and gives NDVI statistics and an
 * NDVI picture per scene. One request per polygon search, one per statistics, one per picture.
 */
class AgroMonitoringProvider implements ImageryProvider
{
    public const KEY = 'agromonitoring';

    public function __construct(private readonly array $config, private readonly array $credentials, private readonly ?SatelliteBudget $budget = null) {}

    public function check(): array
    {
        if (blank($this->credentials['api_key'] ?? null)) {
            return ['ok' => false, 'message' => 'Enter the API key from your AgroMonitoring account.'];
        }
        try {
            $r = Http::timeout(20)->acceptJson()->get($this->url('/polygons'), ['appid' => $this->credentials['api_key']]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'AgroMonitoring could not be reached: '.$e->getMessage()];
        }
        if ($r->status() === 401) {
            return ['ok' => false, 'message' => 'AgroMonitoring refused the API key (HTTP 401).'];
        }
        if (! $r->successful()) {
            return ['ok' => false, 'message' => 'AgroMonitoring answered HTTP '.$r->status().'.'];
        }

        return ['ok' => true, 'message' => 'Connected: '.count((array) $r->json()).' polygon(s) registered on this account.'];
    }

    public function scenes(FarmField $field, array $geometry, Collection $zones, string $from, string $to, float $maxCloudPct, array $known): array
    {
        $poly = $this->polygonId($field, null, $geometry);
        $minCover = (float) ($this->config['min_coverage_pct'] ?? 80);
        $days = $this->search($poly, $from, $to)->reject(fn ($s, $date) => in_array($date, $known, true));
        $out = [];
        $clear = [];
        foreach ($days as $date => $s) {
            $cloud = is_numeric($s['cl'] ?? null) ? round((float) $s['cl'], 2) : null;
            $status = (float) ($s['dc'] ?? 100) < $minCover ? 'no_data' : ($cloud !== null && $cloud > $maxCloudPct ? 'too_cloudy' : 'stored');
            $indices = [];
            if ($status === 'stored') {
                if ($st = $this->stats($s['stats']['ndvi'] ?? null)) {
                    $indices['ndvi'] = $st;
                } else {
                    $status = 'no_data';
                }
            }
            if ($status === 'stored') {
                $clear[] = $date;
            }
            $out[$date] = ['date' => $date, 'ref' => (($s['type'] ?? 'scene').':'.($s['dt'] ?? $date)), 'cloud_pct' => $cloud, 'status' => $status,
                'indices' => $indices, 'zones' => [], 'image' => $status === 'stored' ? ($s['image']['ndvi'] ?? null) : null];
        }
        if ($clear) {
            foreach ($zones as $z) {
                $zg = Geo::polygon($z->boundary);
                if (! $zg) {
                    continue;
                }
                $zpoly = $this->polygonId($field, $z, $zg);
                if (! $zpoly) {
                    continue;   // the provider would not take this zone (too small): the field's own figures still come
                }
                $zdays = $this->search($zpoly, min($clear), max($clear));
                foreach ($clear as $date) {
                    if (isset($zdays[$date]) && ($st = $this->stats($zdays[$date]['stats']['ndvi'] ?? null))) {
                        $out[$date]['zones'][$z->uuid] = ['ndvi' => $st['mean']];
                    }
                }
            }
        }
        ksort($out);

        return array_values($out);
    }

    public function image(FarmField $field, array $geometry, array $scene): ?string
    {
        if (blank($scene['image'] ?? null)) {
            return null;
        }
        $this->budget?->spend();
        $r = Http::timeout((int) config('agri.satellite.timeout', 30))->get($this->signed($scene['image']));
        if (! $r->successful()) {
            throw new RuntimeException('AgroMonitoring NDVI picture failed: HTTP '.$r->status());
        }

        return str_starts_with($r->body(), "\x89PNG") ? $r->body() : null;
    }

    // ── requests ─────────────────────────────────────────────────────────────────────────────

    /** The polygon id of a field (or zone), registering it when it is new or its boundary has changed. */
    private function polygonId(FarmField $field, ?FieldZone $zone, array $geometry): ?string
    {
        $outline = Geo::polygons($geometry)[0] ?? null;   // the API takes one Polygon: the first part
        if (! $outline) {
            throw new RuntimeException("{$field->code} has no outline to register.");
        }
        $g = ['type' => 'Polygon', 'coordinates' => $outline];
        $hash = hash('sha256', json_encode($g));
        $row = SatellitePolygon::withoutGlobalScopes()->where('company_id', $field->company_id)->where('provider', self::KEY)
            ->where('field_id', $field->id)->where('zone_id', $zone?->id)->where('boundary_hash', $hash)->first();
        if ($row) {
            return $row->external_id;
        }
        $this->budget?->spend();
        $r = Http::timeout((int) config('agri.satellite.timeout', 30))->acceptJson()
            ->post($this->url('/polygons').'?'.http_build_query(['appid' => $this->credentials['api_key'] ?? '', 'duplicated' => 'true']), [
                'name' => mb_strimwidth($field->code.($zone ? ' · '.$zone->name : ''), 0, 60), 'geo_json' => ['type' => 'Feature', 'properties' => new \stdClass, 'geometry' => $g],
            ]);
        if (! $r->successful() || ! $r->json('id')) {
            if ($zone) {
                return null;
            }
            throw new RuntimeException("AgroMonitoring would not register {$field->code}'s polygon: HTTP {$r->status()} ".mb_strimwidth((string) ($r->json('message') ?? $r->body()), 0, 200));
        }
        SatellitePolygon::withoutGlobalScopes()->create(['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'field_id' => $field->id, 'zone_id' => $zone?->id,
            'provider' => self::KEY, 'external_id' => (string) $r->json('id'), 'boundary_hash' => $hash]);

        return (string) $r->json('id');
    }

    /** The scenes over a polygon, the clearest one per day. @return Collection<string, array> keyed by date */
    private function search(string $polyId, string $from, string $to): Collection
    {
        $this->budget?->spend();
        $r = Http::timeout((int) config('agri.satellite.timeout', 30))->acceptJson()->get($this->url('/image/search'), [
            'start' => strtotime("{$from} 00:00:00 UTC"), 'end' => strtotime("{$to} 23:59:59 UTC"), 'polyid' => $polyId, 'appid' => $this->credentials['api_key'] ?? '',
        ]);
        if (! $r->successful()) {
            throw new RuntimeException('AgroMonitoring image search failed: HTTP '.$r->status().' '.mb_strimwidth((string) ($r->json('message') ?? ''), 0, 200));
        }

        return collect((array) $r->json())->filter(fn ($s) => is_array($s) && isset($s['dt']))
            ->groupBy(fn ($s) => gmdate('Y-m-d', (int) $s['dt']))
            ->map(fn ($g) => $g->sortBy(fn ($s) => [(float) ($s['cl'] ?? 100), ($s['type'] ?? '') === 'Sentinel-2' ? 0 : 1])->first())
            ->sortKeys();
    }

    /** @return array{mean: float, min: ?float, max: ?float}|null */
    private function stats(?string $url): ?array
    {
        if (blank($url)) {
            return null;
        }
        $this->budget?->spend();
        $r = Http::timeout((int) config('agri.satellite.timeout', 30))->acceptJson()->get($this->signed($url));
        if (! $r->successful()) {
            throw new RuntimeException('AgroMonitoring NDVI statistics failed: HTTP '.$r->status());
        }
        $j = $r->json();

        return is_numeric($j['mean'] ?? null) ? ['mean' => round((float) $j['mean'], 4), 'min' => is_numeric($j['min'] ?? null) ? round((float) $j['min'], 4) : null,
            'max' => is_numeric($j['max'] ?? null) ? round((float) $j['max'], 4) : null] : null;
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'] ?? 'https://api.agromonitoring.com/agro/1.0', '/').$path;
    }

    /** The links the API hands back, over https and carrying the key. */
    private function signed(string $url): string
    {
        $url = preg_replace('#^http://#', 'https://', $url);

        return str_contains($url, 'appid=') ? $url : $url.(str_contains($url, '?') ? '&' : '?').'appid='.urlencode($this->credentials['api_key'] ?? '');
    }
}
