<?php

namespace App\Modules\Agri\MachineData;

use App\Modules\Agri\Models\MachineConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One machine platform's API behind one shape: OAuth 2.0 authorisation code with refresh; the
 * organisations (Deere) or companies (CNH) the grant covers; fields with their boundaries; machines
 * with their serial numbers and their last engine-hour reading; field operations (applications,
 * seedings, harvests) with their products, rates and yields; and files sent to the machines.
 *
 * Everything comes out normalised — boundaries as GeoJSON on WGS84, areas in hectares, rates as
 * kg_ha / l_ha / seeds_ha — and every operation carries its raw payload for the audit. A connector
 * only speaks the platform's dialect; what the data becomes is MachineDataSync's business. Every
 * refused call throws MachineApiError with the platform's own message.
 */
abstract class MachineConnector
{
    public function __construct(protected MachineConnection $connection) {}

    abstract public function provider(): string;

    abstract public function authorizeUrl(string $state, string $redirectUri): string;

    /** @return list<array{id: string, name: string, needs_access: bool, access_url: ?string}> */
    abstract public function organisations(array $tokens): array;

    /** @return list<array{id: string, name: string, boundary: ?array, area_ha: ?float, modified_at: ?string}> */
    abstract public function fields(): array;

    /** A new field there with its boundary; returns its id. */
    abstract public function createField(string $name, array $boundary): string;

    /** A field's boundary replaced there; returns the boundary's id. */
    abstract public function putBoundary(string $fieldId, string $name, array $boundary): string;

    /** @return list<array{id: string, name: string, serial: ?string, vin: ?string, make: ?string, model: ?string, type: ?string}> */
    abstract public function machines(): array;

    /** @return array{engine_hours: ?float, recorded_at: ?string, lat: ?float, lng: ?float}|null */
    abstract public function reading(string $machineId): ?array;

    /**
     * Operations on the given fields since a moment.
     *
     * @param list<string> $fieldIds
     * @return list<array>
     */
    abstract public function operations(Carbon $since, array $fieldIds): array;

    /** @return array{id: string, status: ?string} */
    abstract public function uploadPrescription(string $name, string $bytes, string $mime, string $format, ?string $fieldId): array;

    /** The platform's status for a file sent earlier, or null if it does not say. */
    abstract public function fileStatus(string $id): ?string;

    abstract protected function errorMessage(Response $r): string;

    public function config(?string $key = null): mixed
    {
        $c = config('agri.machine_data.providers.'.$this->provider(), []);

        return $key ? ($c[$key] ?? null) : $c;
    }

    public function exchange(string $code, string $redirectUri): array
    {
        return $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    public function refreshTokens(string $refreshToken): array
    {
        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** Tell the platform to forget the grant; best effort, a platform that is down must not stop a disconnect. */
    public function revoke(): void
    {
        $t = $this->connection->tokens();
        if (! ($t['refresh_token'] ?? null) || ! $this->config('revoke_url')) {
            return;
        }
        try {
            Http::asForm()->timeout(10)->withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'))
                ->post($this->config('revoke_url'), ['token' => $t['refresh_token'], 'token_type_hint' => 'refresh_token']);
        } catch (\Throwable) {
        }
    }

    /** A live access token, refreshed (and the new pair kept) within a minute of expiry. */
    public function accessToken(): string
    {
        $t = $this->connection->tokens();
        if (! ($t['access_token'] ?? null)) {
            throw new MachineApiError('Not connected: connect '.$this->config('name').' again.');
        }
        if (($t['expires_at'] ?? 0) - 60 <= now()->timestamp) {
            try {
                $new = $this->refreshTokens((string) ($t['refresh_token'] ?? ''));
            } catch (MachineApiError $e) {
                $this->connection->forceFill(['status' => 'error', 'last_error' => 'The connection has expired or was revoked: connect again. ('.$e->getMessage().')'])->save();
                throw $e;
            }
            $this->connection->putTokens($new + ['refresh_token' => $t['refresh_token'] ?? null]);
            $this->connection->save();
            $t = $this->connection->tokens();
        }

        return (string) $t['access_token'];
    }

    protected function tokenRequest(array $form): array
    {
        $r = Http::asForm()->acceptJson()->timeout((int) config('agri.machine_data.timeout', 30))
            ->withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'))
            ->post($this->config('token_url'), $form + $this->tokenExtras());
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new MachineApiError($this->config('name').' refused the token request: '.($r->json('error_description') ?? $r->json('error') ?? $r->status()), $r->status());
        }

        return array_filter(['access_token' => (string) $r->json('access_token'), 'refresh_token' => $r->json('refresh_token'),
            'expires_at' => now()->timestamp + (int) ($r->json('expires_in') ?? 3600), 'scope' => $r->json('scope')], fn ($v) => $v !== null);
    }

    protected function tokenExtras(): array
    {
        return [];
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout((int) config('agri.machine_data.timeout', 30));
    }

    protected function ok(Response $r, string $what): array
    {
        if ($r->successful()) {
            return $r->json() ?? [];
        }

        throw new MachineApiError("{$this->config('name')} refused {$what} ({$r->status()}): {$this->errorMessage($r)}", $r->status());
    }

    protected function requireOrg(): string
    {
        return $this->connection->org_id ?: throw new RuntimeException('Choose which '.$this->config('name').' organisation to use first.');
    }

    protected function url(string $path): string
    {
        return rtrim((string) $this->config('api_url'), '/').'/'.ltrim($path, '/');
    }

    // ── normalising ──────────────────────────────────────────────────────────────────────────

    /** A platform's rate unit as ours: kg_ha, l_ha or seeds_ha (null when it is something else). */
    public static function rateUnit(?string $u): ?string
    {
        $u = self::unitKey($u);

        return match (true) {
            in_array($u, ['kg1ha', 'kgha', 'lb1ac', 'lbs1ac', 't1ha'], true) => 'kg_ha',
            in_array($u, ['l1ha', 'lha', 'liter1ha', 'litre1ha', 'liters1ha', 'litres1ha', 'gal1ac'], true) => 'l_ha',
            in_array($u, ['seeds1ha', 'seedsha', 'seed1ha', 'sds1ha', 'ksds1ha', 'sds1ac', 'seeds1ac', 'ksds1ac'], true) => 'seeds_ha',
            default => null,
        };
    }

    /** A rate in our unit: tonnes, pounds, gallons, thousands of seeds and per-acre figures converted. */
    public static function rate(?float $value, ?string $unit): ?float
    {
        if ($value === null) {
            return null;
        }
        $u = self::unitKey($unit);
        $v = $value;
        if (str_starts_with($u, 'ksds')) {
            $v *= 1000;
        } elseif (str_starts_with($u, 't1')) {
            $v *= 1000;
        } elseif (str_starts_with($u, 'lb')) {
            $v *= 0.45359237;
        } elseif (str_starts_with($u, 'gal')) {
            $v *= 3.785411784;
        }

        return round(str_ends_with($u, '1ac') ? $v / 0.40468564224 : $v, 4);
    }

    /** A total in the base unit of our rate (kg, l, seeds). */
    public static function quantity(mixed $value, ?string $unit): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $u = self::unitKey($unit);

        return round((float) $value * match (true) {
            str_starts_with($u, 'ksds') => 1000,
            in_array($u, ['t', 'tonne', 'tonnes', 'mt'], true) => 1000,
            str_starts_with($u, 'lb') => 0.45359237,
            str_starts_with($u, 'gal') => 3.785411784,
            default => 1,
        }, 4);
    }

    private static function unitKey(?string $u): string
    {
        return strtolower(str_replace([' ', '/', '_'], ['', '1', '1'], (string) $u));
    }

    /** An area in hectares from a value and a unit (ha, ac, m2). */
    public static function hectares(mixed $value, ?string $unit): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $u = strtolower((string) $unit);

        return round(match (true) {
            in_array($u, ['ac', 'acre', 'acres'], true) => (float) $value * 0.40468564224,
            in_array($u, ['m2', 'sqm', 'squaremeters'], true) => (float) $value / 10000,
            default => (float) $value,
        }, 4);
    }

    /** A Deere-style multipolygon list ({rings: [{type, points: [{lat, lon}]}]}) as GeoJSON. */
    public static function fromMultipolygons(array $multipolygons): ?array
    {
        $polys = [];
        foreach ($multipolygons as $mp) {
            $outer = null;
            $holes = [];
            foreach ($mp['rings'] ?? [] as $ring) {
                $pts = array_map(fn ($p) => [(float) ($p['lon'] ?? $p['lng'] ?? 0), (float) ($p['lat'] ?? 0)], $ring['points'] ?? []);
                if (count($pts) < 3) {
                    continue;
                }
                if ($pts[0] !== end($pts)) {
                    $pts[] = $pts[0];
                }
                strtolower((string) ($ring['type'] ?? 'exterior')) === 'interior' ? $holes[] = $pts : ($outer === null ? $outer = $pts : $polys[] = [$pts]);
            }
            if ($outer) {
                $polys[] = array_merge([$outer], $holes);
            }
        }
        if (! $polys) {
            return null;
        }

        return count($polys) === 1 ? ['type' => 'Polygon', 'coordinates' => $polys[0]] : ['type' => 'MultiPolygon', 'coordinates' => $polys];
    }

    /** GeoJSON as Deere-style multipolygons. */
    public static function toMultipolygons(array $g): array
    {
        $polys = ($g['type'] ?? '') === 'MultiPolygon' ? $g['coordinates'] : [$g['coordinates'] ?? []];

        return array_map(fn ($rings) => ['rings' => array_map(fn ($ring, $i) => ['type' => $i === 0 ? 'exterior' : 'interior', 'passable' => true,
            'points' => array_map(fn ($c) => ['@type' => 'Point', 'lat' => (float) $c[1], 'lon' => (float) $c[0]], $ring)], $rings, array_keys($rings))], $polys);
    }
}
