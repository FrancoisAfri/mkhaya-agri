<?php

namespace App\Modules\Agri\Satellite;

use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Support\Geo;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Copernicus Data Space Ecosystem (dataspace.copernicus.eu): Sentinel-2 L2A through the Sentinel
 * Hub APIs it hosts, with the organisation's own OAuth client.
 *
 *  - Token: OAuth2 client credentials from the CDSE identity service, kept until shortly before
 *    it expires (it lasts ten minutes).
 *  - Statistics: the Statistical API, one request per field (and one per zone) over the whole date
 *    window, daily intervals. Pixels the scene classification (SCL) marks as no data, saturated,
 *    cloud shadow, medium or high cloud or cirrus are masked; the masked share of the field is its
 *    cloud cover, and a day above the organisation's limit is kept as "too cloudy" and never flags.
 *    NDVI, NDRE (B05) and NDWI (Gao, B08/B11) come from the same request at no extra cost.
 *  - Picture: the Process API, NDVI on a red→green ramp clipped to the field (transparent outside
 *    it and under cloud), as a PNG.
 */
class CopernicusProvider implements ImageryProvider
{
    public const CRS84 = 'http://www.opengis.net/def/crs/OGC/1.3/CRS84';

    /** SCL classes masked out: 0 no data, 1 saturated/defective, 3 cloud shadow, 8 cloud medium, 9 cloud high, 10 thin cirrus. */
    public const MASKED_SCL = [0, 1, 3, 8, 9, 10];

    /** The NDVI colour ramp of the picture; the map legend uses the same stops. */
    public const RAMP = [[-0.2, '#a50026'], [0.0, '#d73027'], [0.2, '#fdae61'], [0.4, '#fee08b'], [0.6, '#a6d96a'], [0.8, '#1a9850'], [1.0, '#006837']];

    public function __construct(private readonly array $config, private readonly array $credentials, private readonly ?SatelliteBudget $budget = null) {}

    public function check(): array
    {
        if (blank($this->credentials['client_id'] ?? null) || blank($this->credentials['client_secret'] ?? null)) {
            return ['ok' => false, 'message' => 'Enter the OAuth client id and secret from your Copernicus Data Space dashboard.'];
        }
        Cache::forget($this->tokenKey());
        try {
            $this->token();

            return ['ok' => true, 'message' => 'Connected: the Copernicus identity service issued a token for this client.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function scenes(FarmField $field, array $geometry, Collection $zones, string $from, string $to, float $maxCloudPct, array $known): array
    {
        $days = collect($this->statistics($geometry, $from, $to))->reject(fn ($d, $date) => in_array($date, $known, true));
        $clear = $days->filter(fn ($d) => $d['status'] === 'stored');
        $byZone = [];
        if ($clear->isNotEmpty()) {
            foreach ($zones as $z) {
                $zg = Geo::polygon($z->boundary);
                if (! $zg) {
                    continue;
                }
                // Only the window of the clear days, so the request covers no more than it must.
                $byZone[$z->uuid] = $this->statistics($zg, $clear->keys()->min(), $clear->keys()->max());
            }
        }
        $out = [];
        foreach ($days as $date => $d) {
            if ($d['status'] === 'stored' && $d['cloud_pct'] !== null && $d['cloud_pct'] > $maxCloudPct) {
                $d['status'] = 'too_cloudy';
            }
            $zonesOut = [];
            if ($d['status'] === 'stored') {
                foreach ($byZone as $uuid => $zdays) {
                    if (($zdays[$date]['status'] ?? null) === 'stored') {
                        $zonesOut[$uuid] = array_map(fn ($s) => $s['mean'], $zdays[$date]['indices']);
                    }
                }
            }
            $out[] = ['date' => $date, 'ref' => 'S2L2A:'.$date, 'cloud_pct' => $d['cloud_pct'], 'status' => $d['status'],
                'indices' => $d['status'] === 'stored' ? $d['indices'] : [], 'zones' => $zonesOut, 'image' => null];
        }

        return $out;
    }

    public function image(FarmField $field, array $geometry, array $scene): ?string
    {
        $b = Geo::bounds($geometry);
        if (! $b) {
            return null;
        }
        [$w, $h] = $this->size($b);
        $r = $this->post($this->config['process_url'], [
            'input' => [
                'bounds' => ['geometry' => $geometry, 'properties' => ['crs' => self::CRS84]],
                'data' => [['type' => $this->config['collection'] ?? 'sentinel-2-l2a', 'dataFilter' => [
                    'timeRange' => ['from' => "{$scene['date']}T00:00:00Z", 'to' => "{$scene['date']}T23:59:59Z"], 'mosaickingOrder' => 'leastCC']]],
            ],
            'output' => ['width' => $w, 'height' => $h, 'responses' => [['identifier' => 'default', 'format' => ['type' => 'image/png']]]],
            'evalscript' => $this->imageScript(),
        ], 'image/png');
        $png = $r->body();

        return str_starts_with($png, "\x89PNG") ? $png : throw new RuntimeException('Copernicus Process API did not return a PNG.');
    }

    // ── requests ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{status: string, cloud_pct: ?float, indices: array}> keyed by date */
    private function statistics(array $geometry, string $from, string $to): array
    {
        $res = (float) ($this->config['resolution_deg'] ?? 0.0001);
        $r = $this->post($this->config['statistics_url'], [
            'input' => [
                'bounds' => ['geometry' => $geometry, 'properties' => ['crs' => self::CRS84]],
                'data' => [['type' => $this->config['collection'] ?? 'sentinel-2-l2a', 'dataFilter' => ['maxCloudCoverage' => (int) ($this->config['tile_max_cloud'] ?? 80)]]],
            ],
            'aggregation' => [
                'timeRange' => ['from' => "{$from}T00:00:00Z", 'to' => "{$to}T23:59:59Z"],
                'aggregationInterval' => ['of' => 'P1D'],
                'evalscript' => $this->statisticsScript(),
                'resx' => $res, 'resy' => $res,
            ],
            'calculations' => ['default' => new \stdClass],
        ]);
        $out = [];
        foreach ((array) $r->json('data', []) as $row) {
            $date = substr((string) ($row['interval']['from'] ?? ''), 0, 10);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            if (isset($row['error'])) {
                $out[$date] = ['status' => 'no_data', 'cloud_pct' => null, 'indices' => []];

                continue;
            }
            $indices = [];
            $cloud = null;
            foreach (['ndvi', 'ndre', 'ndwi'] as $ix) {
                $s = $row['outputs'][$ix]['bands']['B0']['stats'] ?? null;
                if (! $s) {
                    continue;
                }
                $samples = (int) ($s['sampleCount'] ?? 0);
                if ($cloud === null && $samples > 0) {
                    $cloud = round(min(100, (int) ($s['noDataCount'] ?? 0) / $samples * 100), 2);
                }
                if (is_numeric($s['mean'] ?? null)) {
                    $indices[$ix] = ['mean' => round((float) $s['mean'], 4), 'min' => is_numeric($s['min'] ?? null) ? round(max(-1, (float) $s['min']), 4) : null,
                        'max' => is_numeric($s['max'] ?? null) ? round(min(1, (float) $s['max']), 4) : null];
                }
            }
            // Every pixel masked: the satellite passed over, but the field was under cloud.
            $out[$date] = isset($indices['ndvi'])
                ? ['status' => 'stored', 'cloud_pct' => $cloud, 'indices' => $indices]
                : ['status' => $cloud !== null ? 'too_cloudy' : 'no_data', 'cloud_pct' => $cloud ?? null, 'indices' => []];
        }
        ksort($out);

        return $out;
    }

    private function post(string $url, array $body, string $accept = 'application/json'): Response
    {
        $this->budget?->spend();
        $r = Http::withToken($this->token())->accept($accept)->timeout((int) config('agri.satellite.timeout', 30))->post($url, $body);
        if ($r->status() === 401) {
            Cache::forget($this->tokenKey());
        }
        if (! $r->successful()) {
            throw new RuntimeException('Copernicus '.(str_contains($url, 'statistics') ? 'Statistical' : 'Process').' API failed: HTTP '.$r->status().' '.mb_strimwidth(trim(strip_tags($r->body())), 0, 300));
        }

        return $r;
    }

    private function token(): string
    {
        $key = $this->tokenKey();
        if ($t = Cache::get($key)) {
            return $t;
        }
        $r = Http::asForm()->timeout(20)->post($this->config['token_url'], [
            'grant_type' => 'client_credentials', 'client_id' => $this->credentials['client_id'] ?? '', 'client_secret' => $this->credentials['client_secret'] ?? '',
        ]);
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new RuntimeException('Copernicus refused the client credentials: HTTP '.$r->status().($r->json('error_description') ? ' — '.$r->json('error_description') : ''));
        }
        Cache::put($key, $r->json('access_token'), max(30, (int) $r->json('expires_in', 600) - 60));

        return $r->json('access_token');
    }

    private function tokenKey(): string
    {
        return 'agri:cdse-token:'.sha1((string) ($this->credentials['client_id'] ?? '').'|'.(string) ($this->credentials['client_secret'] ?? ''));
    }

    /** Picture size in pixels: about 10 m a pixel, the longer side capped. */
    private function size(array $b): array
    {
        $k = cos(deg2rad(($b[1] + $b[3]) / 2));
        $wm = max(1, ($b[2] - $b[0]) * 111320 * $k);
        $hm = max(1, ($b[3] - $b[1]) * 110574);
        $max = (int) ($this->config['image_max_px'] ?? 512);
        $scale = min($max / max($wm, $hm), 0.1) ?: 0.1;   // ≤ 1 px per 10 m

        return [max(16, (int) round($wm * $scale)), max(16, (int) round($hm * $scale))];
    }

    private function statisticsScript(): string
    {
        $masked = json_encode(self::MASKED_SCL);

        return <<<JS
//VERSION=3
function setup() {
  return {
    input: [{ bands: ["B04", "B05", "B08", "B11", "SCL", "dataMask"] }],
    output: [
      { id: "ndvi", bands: 1, sampleType: "FLOAT32" },
      { id: "ndre", bands: 1, sampleType: "FLOAT32" },
      { id: "ndwi", bands: 1, sampleType: "FLOAT32" },
      { id: "dataMask", bands: 1 }
    ]
  };
}
function evaluatePixel(s) {
  var clear = s.dataMask === 1 && {$masked}.indexOf(s.SCL) < 0 ? 1 : 0;
  return { ndvi: [index(s.B08, s.B04)], ndre: [index(s.B08, s.B05)], ndwi: [index(s.B08, s.B11)], dataMask: [clear] };
}
JS;
    }

    private function imageScript(): string
    {
        $masked = json_encode(self::MASKED_SCL);
        $ramp = implode(', ', array_map(fn ($s) => '['.$s[0].', 0x'.ltrim($s[1], '#').']', self::RAMP));

        return <<<JS
//VERSION=3
function setup() {
  return { input: ["B04", "B08", "SCL", "dataMask"], output: { bands: 4 } };
}
var viz = new ColorRampVisualizer([{$ramp}]);
function evaluatePixel(s) {
  var clear = s.dataMask === 1 && {$masked}.indexOf(s.SCL) < 0;
  var c = viz.process(index(s.B08, s.B04));
  return [c[0], c[1], c[2], clear ? 1 : 0];
}
JS;
    }
}
