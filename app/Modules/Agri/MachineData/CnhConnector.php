<?php

namespace App\Modules\Agri\MachineData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * CNH FieldOps — the CNH Industrial data platform behind Case IH and New Holland (it replaced AFS
 * Connect and PLM Connect). OAuth 2.0 against CNH's identity service with an audience and
 * offline_access; every API call also carries the subscription key the developer portal issues.
 * The grant covers one or more companies; fields carry GeoJSON boundaries, vehicles their serial
 * numbers and latest telemetry, operations their products, rates, yields and (when the display
 * recorded them) as-applied points. Prescriptions are uploaded to the company as ISO-XML task data.
 *
 * Paths and field names follow the FieldOps API as published to registered developers; they sit in
 * config and here only, so the approved app's actual contract is a small change.
 */
class CnhConnector extends MachineConnector
{
    public function provider(): string
    {
        return 'cnh';
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $this->config('authorize_url').'?'.http_build_query(['response_type' => 'code', 'client_id' => $this->config('client_id'),
            'redirect_uri' => $redirectUri, 'scope' => $this->config('scopes'), 'audience' => $this->config('audience'), 'state' => $state]);
    }

    public function organisations(array $tokens): array
    {
        $r = $this->withKey(Http::withToken($tokens['access_token'])->acceptJson()->timeout(20))->get($this->url('companies'));
        $j = $this->ok($r, 'the list of companies');

        return collect($j['data'] ?? $j['companies'] ?? [])->map(fn ($c) => ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? $c['id']), 'needs_access' => false, 'access_url' => null])->values()->all();
    }

    public function fields(): array
    {
        $cid = $this->requireOrg();

        return collect($this->pages("companies/{$cid}/fields", 'the fields'))->map(function ($f) {
            $b = $f['boundary'] ?? null;
            if (($b['type'] ?? null) === 'Feature') {
                $b = $b['geometry'] ?? null;
            }

            return ['id' => (string) $f['id'], 'name' => (string) ($f['name'] ?? $f['id']), 'boundary' => $b ?: null,
                'area_ha' => self::hectares($f['area']['value'] ?? null, $f['area']['unit'] ?? 'ha'), 'modified_at' => $f['modifiedAt'] ?? null];
        })->values()->all();
    }

    public function createField(string $name, array $boundary): string
    {
        $cid = $this->requireOrg();
        $j = $this->ok($this->api()->asJson()->post($this->url("companies/{$cid}/fields"), ['name' => mb_substr($name, 0, 100), 'boundary' => $boundary]), 'the new field');

        return (string) ($j['id'] ?? $j['data']['id'] ?? throw new MachineApiError('CNH FieldOps returned no field id.'));
    }

    public function putBoundary(string $fieldId, string $name, array $boundary): string
    {
        $cid = $this->requireOrg();
        $j = $this->ok($this->api()->asJson()->put($this->url("companies/{$cid}/fields/{$fieldId}/boundary"), ['name' => mb_substr($name, 0, 100), 'boundary' => $boundary]), 'the boundary');

        return (string) ($j['id'] ?? $fieldId);
    }

    public function machines(): array
    {
        $cid = $this->requireOrg();

        return collect($this->pages("companies/{$cid}/vehicles", 'the vehicles'))->map(fn ($v) => [
            'id' => (string) $v['id'], 'name' => (string) ($v['name'] ?? $v['id']), 'serial' => $v['serialNumber'] ?? null, 'vin' => $v['vin'] ?? null,
            'make' => $v['brand'] ?? null, 'model' => $v['model'] ?? null, 'type' => $v['type'] ?? null,
        ])->values()->all();
    }

    public function reading(string $machineId): ?array
    {
        $r = $this->api()->get($this->url("vehicles/{$machineId}/telemetry/latest"));
        if ($r->status() === 404) {
            return null;
        }
        $j = $this->ok($r, 'the telemetry');

        return ['engine_hours' => isset($j['engineHours']) ? (float) $j['engineHours'] : null, 'recorded_at' => $j['timestamp'] ?? null,
            'lat' => isset($j['latitude']) ? (float) $j['latitude'] : null, 'lng' => isset($j['longitude']) ? (float) $j['longitude'] : null];
    }

    public function operations(Carbon $since, array $fieldIds): array
    {
        $cid = $this->requireOrg();
        $want = array_flip($fieldIds);
        $out = [];
        $q = http_build_query(['from' => $since->copy()->utc()->toIso8601ZuluString(), 'types' => 'application,seeding,harvest']);
        foreach ($this->pages("companies/{$cid}/operations?{$q}", 'the operations') as $op) {
            $type = strtolower((string) ($op['type'] ?? ''));
            if (! in_array($type, ['application', 'seeding', 'harvest'], true) || ! isset($want[(string) ($op['fieldId'] ?? '')])) {
                continue;
            }
            $area = self::hectares($op['area']['value'] ?? null, $op['area']['unit'] ?? 'ha');
            $h = $op['harvest'] ?? null;
            $out[] = [
                'id' => (string) $op['id'], 'type' => $type, 'field_id' => (string) $op['fieldId'], 'machine_id' => isset($op['vehicleId']) ? (string) $op['vehicleId'] : null,
                'operator' => $op['operator'] ?? null, 'started_at' => $op['startTime'] ?? null, 'ended_at' => $op['endTime'] ?? null, 'modified_at' => $op['modifiedAt'] ?? null,
                'area_ha' => $area,
                'products' => collect($op['products'] ?? [])->map(fn ($p) => ['name' => (string) ($p['name'] ?? 'Product'),
                    'rate' => self::rate(isset($p['rate']['value']) ? (float) $p['rate']['value'] : null, $p['rate']['unit'] ?? null), 'unit' => self::rateUnit($p['rate']['unit'] ?? null),
                    'total' => self::quantity($p['total']['value'] ?? null, $p['total']['unit'] ?? null), 'total_unit' => $p['total']['unit'] ?? null, 'area_ha' => $area])->values()->all(),
                'harvest' => $h ? ['total' => isset($h['total']) ? (float) $h['total'] : null, 'unit' => (string) ($h['totalUnit'] ?? 't'),
                    'yield_per_ha' => isset($h['yield']) ? (float) $h['yield'] : null, 'moisture_pct' => isset($h['moisture']) ? (float) $h['moisture'] : null,
                    'zones' => collect($h['zones'] ?? [])->map(fn ($z) => ['name' => $z['name'] ?? null, 'geometry' => $z['geometry'] ?? null, 'yield' => isset($z['yield']) ? (float) $z['yield'] : null])->values()->all()] : null,
                'rate_points' => collect($op['asApplied'] ?? [])->filter(fn ($p) => isset($p['lat'], $p['lng'], $p['rate']))
                    ->map(fn ($p) => ['lng' => (float) $p['lng'], 'lat' => (float) $p['lat'], 'rate' => (float) $p['rate']])->values()->all(),
                'raw' => $op,
            ];
        }

        return $out;
    }

    public function uploadPrescription(string $name, string $bytes, string $mime, string $format, ?string $fieldId): array
    {
        $cid = $this->requireOrg();
        $r = $this->withKey($this->http())->attach('file', $bytes, $name, ['Content-Type' => $mime])
            ->post($this->url("companies/{$cid}/prescriptions"), array_filter(['name' => $name, 'format' => $format === 'isoxml' ? 'ISOXML' : strtoupper($format), 'fieldId' => $fieldId]));
        $j = $this->ok($r, 'the prescription');

        return ['id' => (string) ($j['id'] ?? throw new MachineApiError('CNH FieldOps returned no prescription id.')), 'status' => $j['status'] ?? null];
    }

    public function fileStatus(string $id): ?string
    {
        return $this->ok($this->api()->get($this->url("prescriptions/{$id}")), 'the prescription')['status'] ?? null;
    }

    protected function errorMessage(Response $r): string
    {
        $j = $r->json() ?? [];

        return (string) ($j['message'] ?? $j['error']['message'] ?? $j['errors'][0]['detail'] ?? $j['title'] ?? mb_strimwidth($r->body(), 0, 300));
    }

    protected function tokenExtras(): array
    {
        return ['audience' => $this->config('audience')];
    }

    private function api(): PendingRequest
    {
        return $this->withKey($this->http());
    }

    private function withKey(PendingRequest $req): PendingRequest
    {
        $key = $this->config('subscription_key');

        return $key ? $req->withHeaders(['Ocp-Apim-Subscription-Key' => $key]) : $req;
    }

    /** Every page of a FieldOps collection, following links.next. */
    private function pages(string $path, string $what): array
    {
        $out = [];
        $url = $this->url($path);
        for ($i = 0; $url && $i < 50; $i++) {
            $j = $this->ok($this->api()->get($url), $what);
            array_push($out, ...($j['data'] ?? []));
            $url = $j['links']['next'] ?? null;
        }

        return $out;
    }
}
