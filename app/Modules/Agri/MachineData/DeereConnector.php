<?php

namespace App\Modules\Agri\MachineData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * John Deere Operations Center (the Deere platform API, application/vnd.deere.axiom.v3+json).
 *
 * OAuth 2.0 against Deere's sign-in server, with offline_access for a refresh token. Signing in is
 * not enough: each organisation must also grant the app access (the organisation-level step), and
 * until it does, /organizations lists it with a "connections" link that takes a person there.
 * Fields come with their active boundary (multipolygons of {lat, lon} points); machines with VIN
 * and their last engine-hour and location readings; field operations per field with the
 * measurement types Deere computes for them (ApplicationRateResult, SeedingRateResult,
 * HarvestYieldResult, HarvestMoistureResult). Prescriptions go up as files: the file is created,
 * then its bytes are PUT to it, and it appears in the organisation's Files for the display.
 */
class DeereConnector extends MachineConnector
{
    /** Their operation types we import, and the measurement each one reads. */
    private const MEASUREMENTS = ['application' => 'ApplicationRateResult', 'seeding' => 'SeedingRateResult', 'harvest' => 'HarvestYieldResult'];

    public function provider(): string
    {
        return 'deere';
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $this->config('authorize_url').'?'.http_build_query(['response_type' => 'code', 'client_id' => $this->config('client_id'),
            'redirect_uri' => $redirectUri, 'scope' => $this->config('scopes'), 'state' => $state]);
    }

    /** The organisation-level access grant page, coming back to the given address. */
    public function accessGrantUrl(string $returnTo): string
    {
        return str_replace('{client_id}', (string) $this->config('client_id'), (string) $this->config('connections_url')).'?'.http_build_query(['redirect_uri' => $returnTo]);
    }

    public function organisations(array $tokens): array
    {
        $req = Http::withToken($tokens['access_token'])->accept((string) $this->config('accept'))->timeout(20);
        $out = [];
        foreach ($this->pages($req, $this->url('organizations'), 'the list of organisations') as $o) {
            $grant = collect($o['links'] ?? [])->firstWhere('rel', 'connections');
            $out[] = ['id' => (string) $o['id'], 'name' => (string) ($o['name'] ?? $o['id']), 'needs_access' => (bool) $grant, 'access_url' => $grant['uri'] ?? null];
        }

        return $out;
    }

    public function fields(): array
    {
        $org = $this->requireOrg();
        $out = [];
        foreach ($this->pages($this->api(), $this->url("organizations/{$org}/fields?embed=activeBoundary"), 'the fields') as $f) {
            if ($f['archived'] ?? false) {
                continue;
            }
            $b = $f['activeBoundary'] ?? collect($f['boundaries'] ?? [])->firstWhere('active', true) ?? null;
            $out[] = ['id' => (string) $f['id'], 'name' => (string) ($f['name'] ?? $f['id']),
                'boundary' => $b ? self::fromMultipolygons($b['multipolygons'] ?? []) : null,
                'area_ha' => $b ? self::hectares($b['area']['valueAsDouble'] ?? $b['area']['value'] ?? null, $b['area']['unit'] ?? 'ha') : null,
                'modified_at' => $b['lastModifiedTime'] ?? $f['lastModifiedTime'] ?? null];
        }

        return $out;
    }

    public function createField(string $name, array $boundary): string
    {
        $org = $this->requireOrg();
        $r = $this->api()->asJson()->post($this->url("organizations/{$org}/fields"), ['name' => mb_substr($name, 0, 100)]);
        $id = $this->createdId($r, 'the new field');
        $this->putBoundary($id, $name, $boundary);

        return $id;
    }

    public function putBoundary(string $fieldId, string $name, array $boundary): string
    {
        $org = $this->requireOrg();
        $r = $this->api()->asJson()->post($this->url("organizations/{$org}/fields/{$fieldId}/boundaries"),
            ['name' => mb_substr($name, 0, 100), 'active' => true, 'multipolygons' => self::toMultipolygons($boundary)]);

        return $this->createdId($r, 'the boundary');
    }

    public function machines(): array
    {
        $org = $this->requireOrg();

        return collect($this->pages($this->api(), $this->url("organizations/{$org}/machines"), 'the machines'))->map(fn ($m) => [
            'id' => (string) $m['id'], 'name' => (string) ($m['name'] ?? $m['id']), 'serial' => $m['serialNumber'] ?? null, 'vin' => $m['vin'] ?? null,
            'make' => $m['equipmentMake']['name'] ?? 'John Deere', 'model' => $m['equipmentModel']['name'] ?? $m['modelName'] ?? null, 'type' => $m['equipmentType']['name'] ?? null,
        ])->values()->all();
    }

    public function reading(string $machineId): ?array
    {
        $h = $this->ok($this->api()->get($this->url("machines/{$machineId}/engineHours"), ['lastKnown' => 'true']), 'the engine hours')['values'][0] ?? null;
        $loc = null;
        $r = $this->api()->get($this->url("machines/{$machineId}/locationHistory"), ['lastKnown' => 'true']);
        if ($r->successful()) {
            $loc = $r->json('values.0');
        }
        if (! $h && ! $loc) {
            return null;
        }

        return ['engine_hours' => isset($h['reading']) ? (float) ($h['reading']['valueAsDouble'] ?? $h['reading']['value'] ?? 0) : null,
            'recorded_at' => $h['reportTime'] ?? $loc['eventTimestamp'] ?? null,
            'lat' => isset($loc['point']['lat']) ? (float) $loc['point']['lat'] : null, 'lng' => isset($loc['point']['lon']) ? (float) $loc['point']['lon'] : null];
    }

    public function operations(Carbon $since, array $fieldIds): array
    {
        $org = $this->requireOrg();
        $out = [];
        foreach ($fieldIds as $fid) {
            $ops = $this->pages($this->api(), $this->url("organizations/{$org}/fields/{$fid}/fieldOperations?".http_build_query(['startDate' => $since->copy()->utc()->format('Y-m-d\TH:i:s.v\Z')])), 'the field operations');
            foreach ($ops as $op) {
                $type = strtolower((string) ($op['fieldOperationType'] ?? ''));
                if (! isset(self::MEASUREMENTS[$type])) {
                    continue;
                }
                $m = $this->ok($this->api()->get($this->url("fieldOperations/{$op['id']}/measurementTypes/".self::MEASUREMENTS[$type])), 'the operation measurements');
                $moisture = $type === 'harvest' ? $this->api()->get($this->url("fieldOperations/{$op['id']}/measurementTypes/HarvestMoistureResult")) : null;
                $moisture = $moisture?->successful() ? $moisture->json() : null;
                $out[] = $this->normalise($op, $type, (string) $fid, $m, $moisture);
            }
        }

        return $out;
    }

    public function uploadPrescription(string $name, string $bytes, string $mime, string $format, ?string $fieldId): array
    {
        $org = $this->requireOrg();
        $r = $this->api()->asJson()->post($this->url("organizations/{$org}/files"), ['name' => $name]);
        $id = $this->createdId($r, 'the new file');
        $put = $this->http()->withHeaders(['Accept' => (string) $this->config('accept')])->withBody($bytes, 'application/octet-stream')->put($this->url("files/{$id}"));
        $this->ok($put, 'the file contents');

        return ['id' => $id, 'status' => 'uploaded'];
    }

    public function fileStatus(string $id): ?string
    {
        $j = $this->ok($this->api()->get($this->url("files/{$id}")), 'the file');

        return $j['status'] ?? $j['transferPending'] ?? null;
    }

    protected function errorMessage(Response $r): string
    {
        $j = $r->json() ?? [];

        return (string) ($j['errors'][0]['message'] ?? $j['message'] ?? $j['error_description'] ?? mb_strimwidth($r->body(), 0, 300));
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->accessToken())->timeout((int) config('agri.machine_data.timeout', 30));
    }

    private function api(): PendingRequest
    {
        return $this->http()->accept((string) $this->config('accept'));
    }

    /** Every page of a Deere collection, following its nextPage links. */
    private function pages(PendingRequest $req, string $url, string $what): array
    {
        $out = [];
        for ($i = 0; $url && $i < 50; $i++) {
            $j = $this->ok($req->get($url), $what);
            array_push($out, ...($j['values'] ?? []));
            $url = collect($j['links'] ?? [])->firstWhere('rel', 'nextPage')['uri'] ?? null;
        }

        return $out;
    }

    /** The id of what a POST created: from the Location header, or the body. */
    private function createdId(Response $r, string $what): string
    {
        $this->ok($r, $what);
        $loc = $r->header('Location');
        if ($loc) {
            return basename(parse_url($loc, PHP_URL_PATH) ?: $loc);
        }

        return (string) ($r->json('id') ?? throw new MachineApiError("{$this->config('name')} returned no id for {$what}."));
    }

    private function normalise(array $op, string $type, string $fieldId, array $m, ?array $moisture): array
    {
        $area = self::hectares($m['area']['value'] ?? null, $m['area']['unit'] ?? 'ha');
        $products = collect($m['productTotals'] ?? [])->map(fn ($p) => [
            'name' => (string) ($p['name'] ?? 'Product'),
            'rate' => self::rate(isset($p['averageMaterial']['value']) ? (float) $p['averageMaterial']['value'] : null, $p['averageMaterial']['unit'] ?? null),
            'unit' => self::rateUnit($p['averageMaterial']['unit'] ?? null),
            'total' => self::quantity($p['totalMaterial']['value'] ?? null, $p['totalMaterial']['unit'] ?? null), 'total_unit' => $p['totalMaterial']['unit'] ?? null,
            'area_ha' => self::hectares($p['area']['value'] ?? null, $p['area']['unit'] ?? 'ha') ?? $area,
        ])->values()->all();
        if (! $products && $type !== 'harvest' && isset($m['averageMaterial'])) {
            $products[] = ['name' => (string) ($op['products'][0]['name'] ?? $op['cropName'] ?? 'Product'), 'rate' => self::rate((float) $m['averageMaterial']['value'], $m['averageMaterial']['unit'] ?? null),
                'unit' => self::rateUnit($m['averageMaterial']['unit'] ?? null), 'total' => self::quantity($m['totalMaterial']['value'] ?? null, $m['totalMaterial']['unit'] ?? null),
                'total_unit' => $m['totalMaterial']['unit'] ?? null, 'area_ha' => $area];
        }
        $harvest = null;
        if ($type === 'harvest') {
            $yu = (string) ($m['averageYield']['unit'] ?? 't1ha');
            $harvest = ['total' => isset($m['totalYield']['value']) ? (float) $m['totalYield']['value'] : null, 'unit' => self::massUnit($m['totalYield']['unit'] ?? 't'),
                'yield_per_ha' => isset($m['averageYield']['value']) ? round((float) $m['averageYield']['value'] / (str_ends_with($yu, '1ac') ? 0.40468564224 : 1), 4) : null,
                'moisture_pct' => isset($moisture['averageMoisture']['value']) ? (float) $moisture['averageMoisture']['value'] : null,
                'zones' => collect($m['yieldZones'] ?? [])->map(fn ($z) => ['name' => $z['name'] ?? null, 'geometry' => isset($z['multipolygons']) ? self::fromMultipolygons($z['multipolygons']) : null,
                    'yield' => isset($z['averageYield']['value']) ? (float) $z['averageYield']['value'] : null])->values()->all()];
        }

        return [
            'id' => (string) $op['id'], 'type' => $type, 'field_id' => $fieldId,
            'machine_id' => isset($op['machines'][0]['id']) ? (string) $op['machines'][0]['id'] : null,
            'operator' => $op['operators'][0]['name'] ?? null,
            'started_at' => $op['startDate'] ?? null, 'ended_at' => $op['endDate'] ?? null, 'modified_at' => $op['modifiedTime'] ?? null,
            'area_ha' => $area, 'products' => $products, 'harvest' => $harvest,
            'rate_points' => [],
            'raw' => ['operation' => $op, 'measurement' => $m, 'moisture' => $moisture],
        ];
    }

    private static function massUnit(string $u): string
    {
        return match (strtolower($u)) {
            't', 'tonne', 'tonnes', 'mt' => 't',
            'kg' => 'kg',
            'bu' => 'bu',
            default => $u,
        };
    }
}
