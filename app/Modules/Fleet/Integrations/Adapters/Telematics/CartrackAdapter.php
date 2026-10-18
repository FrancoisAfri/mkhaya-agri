<?php

namespace App\Modules\Fleet\Integrations\Adapters\Telematics;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use Illuminate\Support\Facades\Http;

/**
 * Cartrack Fleet API adapter.
 *
 * Cartrack uses HTTP Basic auth (username:password) or a Bearer token.
 * Endpoint: https://api.cartrack.com  (or ZA: https://za.api.cartrack.com)
 * Docs:     https://developer.cartrack.com
 */
class CartrackAdapter extends AbstractFleetAdapter
{
    private ?string $token = null;

    // ── Auth ──────────────────────────────────────────────────────────────────

    private function authenticate(): string
    {
        if ($this->token) return $this->token;

        $response = Http::timeout(15)
            ->withBasicAuth($this->config['api_key'], $this->config['api_secret'])
            ->post($this->endpoint('/v2/auth/token'), [
                'grant_type' => 'client_credentials',
                'account_id' => $this->config['account_id'] ?? '',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Cartrack auth failed: ' . $response->body());
        }

        $this->token = $response->json('access_token')
            ?? throw new \RuntimeException('Cartrack: no access_token in response.');

        return $this->token;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(30)
            ->withToken($this->authenticate())
            ->acceptJson();
    }

    // ── Contract ──────────────────────────────────────────────────────────────

    public function testConnection(): array
    {
        try {
            $this->authenticate();
            $resp = $this->http()->get($this->endpoint('/v2/vehicles'), ['limit' => 1]);

            return $resp->successful()
                ? ['success' => true,  'message' => 'Cartrack connection successful.']
                : ['success' => false, 'message' => 'Cartrack returned HTTP ' . $resp->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(): array
    {
        $synced = 0; $errors = 0;

        try {
            // ── 1. Vehicle positions ──────────────────────────────────────────
            $vehicles = $this->http()->get($this->endpoint('/v2/vehicles'))->json('data', []);

            foreach ($vehicles as $v) {
                $vehicleId = $this->resolveVehicleId($v['registration'] ?? $v['license_plate'] ?? '');
                if (!$vehicleId) continue;

                $this->upsertPosition([
                    'vehicle_id'          => $vehicleId,
                    'provider'            => $this->provider,
                    'provider_vehicle_id' => $v['vehicle_id'] ?? null,
                    'latitude'            => $v['last_position']['lat'] ?? null,
                    'longitude'           => $v['last_position']['lng'] ?? null,
                    'speed_kmh'           => $v['last_position']['speed'] ?? null,
                    'heading'             => $v['last_position']['heading'] ?? null,
                    'odometer_km'         => $v['odometer_km'] ?? null,
                    'ignition_status'     => ($v['ignition'] ?? false) ? 'on' : 'off',
                    'address'             => $v['last_position']['address'] ?? null,
                    'recorded_at'         => isset($v['last_position']['timestamp'])
                        ? date('Y-m-d H:i:s', $v['last_position']['timestamp'])
                        : null,
                ]);
                $synced++;
            }

            // ── 2. Driving events (last 24 h) ─────────────────────────────────
            $since  = now()->subHours(24)->toIso8601String();
            $events = $this->http()->get($this->endpoint('/v2/events'), [
                'from'  => $since,
                'types' => 'harsh_braking,harsh_acceleration,speeding,idle',
            ])->json('data', []);

            foreach ($events as $e) {
                $vehicleId = $this->resolveVehicleId($e['registration'] ?? '');
                if (!$vehicleId) continue;

                $this->insertEvent([
                    'vehicle_id'       => $vehicleId,
                    'provider'         => $this->provider,
                    'provider_event_id'=> (string) ($e['event_id'] ?? ''),
                    'event_type'       => $this->mapEventType($e['event_type'] ?? ''),
                    'severity'         => $e['severity'] ?? null,
                    'latitude'         => $e['lat'] ?? null,
                    'longitude'        => $e['lng'] ?? null,
                    'speed_kmh'        => $e['speed'] ?? null,
                    'value'            => $e['value'] ?? null,
                    'address'          => $e['address'] ?? null,
                    'occurred_at'      => $e['timestamp'] ?? now(),
                ]);
                $synced++;
            }

            $this->recordSyncResult('success', "Cartrack sync complete. {$synced} records updated.");
        } catch (\Throwable $e) {
            $errors++;
            $this->recordSyncResult('error', 'Cartrack sync error: ' . $e->getMessage());
            $this->log('Sync error', ['error' => $e->getMessage()]);
        }

        return ['synced' => $synced, 'errors' => $errors, 'message' => "Cartrack: {$synced} synced, {$errors} errors."];
    }

    private function mapEventType(string $raw): string
    {
        return match (strtolower($raw)) {
            'harshbraking', 'harsh_braking'           => 'harsh_braking',
            'harshacceleration', 'harsh_acceleration'  => 'harsh_acceleration',
            'speeding', 'overspeed'                    => 'speeding',
            'idle', 'idling'                           => 'idle',
            'geofenceenter', 'geofence_enter'          => 'geofence_enter',
            'geofenceexit',  'geofence_exit'           => 'geofence_exit',
            default                                    => strtolower($raw),
        };
    }
}
