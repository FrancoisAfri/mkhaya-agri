<?php

namespace App\Modules\Fleet\Integrations\Adapters\Telematics;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use Illuminate\Support\Facades\Http;

/**
 * Mix Telematics API adapter.
 *
 * Mix Telematics uses OAuth2 client_credentials flow.
 * Endpoint: https://api.mixtelematics.com/v1
 * Docs:     https://developer.mixtelematics.com
 */
class MixTelematicsAdapter extends AbstractFleetAdapter
{
    private ?string $token     = null;
    private ?int    $expiresAt = null;

    // ── Auth ──────────────────────────────────────────────────────────────────

    private function authenticate(): string
    {
        if ($this->token && $this->expiresAt && now()->timestamp < $this->expiresAt) {
            return $this->token;
        }

        $response = Http::timeout(15)
            ->post($this->endpoint('/auth/token'), [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->config['api_key'],
                'client_secret' => $this->config['api_secret'],
                'fleet_id'      => $this->config['fleet_id'] ?? '',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Mix Telematics auth failed: ' . $response->body());
        }

        $this->token     = $response->json('access_token')
            ?? throw new \RuntimeException('Mix Telematics: no access_token in response.');
        $this->expiresAt = now()->timestamp + ($response->json('expires_in', 3600) - 60);

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
            $resp = $this->http()->get($this->endpoint('/fleets/' . ($this->config['fleet_id'] ?? '') . '/vehicles'), [
                'page' => 1, 'per_page' => 1,
            ]);

            return $resp->successful()
                ? ['success' => true,  'message' => 'Mix Telematics connection successful.']
                : ['success' => false, 'message' => 'Mix Telematics returned HTTP ' . $resp->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(): array
    {
        $synced = 0; $errors = 0;
        $fleetId = $this->config['fleet_id'] ?? '';

        try {
            // ── 1. Latest vehicle positions ───────────────────────────────────
            $page = 1;
            do {
                $resp     = $this->http()->get($this->endpoint("/fleets/{$fleetId}/vehicles/positions"), [
                    'page' => $page, 'per_page' => 100,
                ]);
                $vehicles = $resp->json('data', []);
                $meta     = $resp->json('meta', []);

                foreach ($vehicles as $v) {
                    $vehicleId = $this->resolveVehicleId($v['registration_number'] ?? $v['licence_plate'] ?? '');
                    if (!$vehicleId) continue;

                    $pos = $v['position'] ?? [];
                    $this->upsertPosition([
                        'vehicle_id'          => $vehicleId,
                        'provider'            => $this->provider,
                        'provider_vehicle_id' => (string) ($v['vehicle_id'] ?? ''),
                        'latitude'            => $pos['latitude'] ?? null,
                        'longitude'           => $pos['longitude'] ?? null,
                        'speed_kmh'           => $pos['speed_kmh'] ?? null,
                        'heading'             => $pos['heading'] ?? null,
                        'odometer_km'         => $v['odometer_km'] ?? null,
                        'ignition_status'     => strtolower($pos['ignition'] ?? 'unknown'),
                        'address'             => $pos['address'] ?? null,
                        'recorded_at'         => $pos['recorded_at'] ?? null,
                    ]);
                    $synced++;
                }

                $page++;
            } while (($meta['current_page'] ?? 1) < ($meta['last_page'] ?? 1));

            // ── 2. Driving events (last 24 h) ─────────────────────────────────
            $events = $this->http()->get($this->endpoint("/fleets/{$fleetId}/events"), [
                'from'        => now()->subHours(24)->toIso8601String(),
                'to'          => now()->toIso8601String(),
                'event_types' => 'harsh_braking,harsh_acceleration,speeding,idle',
                'per_page'    => 200,
            ])->json('data', []);

            foreach ($events as $e) {
                $vehicleId = $this->resolveVehicleId($e['registration_number'] ?? '');
                if (!$vehicleId) continue;

                $this->insertEvent([
                    'vehicle_id'        => $vehicleId,
                    'provider'          => $this->provider,
                    'provider_event_id' => (string) ($e['event_id'] ?? ''),
                    'event_type'        => $e['event_type'] ?? 'unknown',
                    'severity'          => $e['severity'] ?? null,
                    'latitude'          => $e['latitude'] ?? null,
                    'longitude'         => $e['longitude'] ?? null,
                    'speed_kmh'         => $e['speed_kmh'] ?? null,
                    'value'             => $e['g_force'] ?? $e['excess_speed_kmh'] ?? null,
                    'address'           => $e['address'] ?? null,
                    'occurred_at'       => $e['occurred_at'] ?? now(),
                ]);
                $synced++;
            }

            $this->recordSyncResult('success', "Mix Telematics sync complete. {$synced} records updated.");
        } catch (\Throwable $e) {
            $errors++;
            $this->recordSyncResult('error', 'Mix Telematics sync error: ' . $e->getMessage());
            $this->log('Sync error', ['error' => $e->getMessage()]);
        }

        return ['synced' => $synced, 'errors' => $errors, 'message' => "Mix Telematics: {$synced} synced."];
    }
}
