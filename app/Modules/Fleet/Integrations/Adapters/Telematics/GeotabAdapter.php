<?php

namespace App\Modules\Fleet\Integrations\Adapters\Telematics;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use Illuminate\Support\Facades\Http;

/**
 * Geotab MyGeotab API adapter.
 *
 * Geotab uses a JSON-RPC 2.0 API with session-based authentication.
 * A single POST to /apiv1 creates a session, subsequent calls use the session.
 * Docs: https://developers.geotab.com/myGeotab/apiReference
 */
class GeotabAdapter extends AbstractFleetAdapter
{
    private ?array $credentials = null; // { userName, sessionId, database }

    // ── Auth ──────────────────────────────────────────────────────────────────

    private function authenticate(): array
    {
        if ($this->credentials) return $this->credentials;

        $response = $this->rpc('Authenticate', [
            'userName' => $this->config['api_key'],
            'password' => $this->config['api_secret'],
            'database' => $this->config['database'] ?? '',
        ]);

        $result = $response['result'] ?? null;
        if (!$result) {
            throw new \RuntimeException('Geotab auth failed: ' . json_encode($response));
        }

        $this->credentials = [
            'userName'  => $result['credentials']['userName'],
            'sessionId' => $result['credentials']['sessionId'],
            'database'  => $result['credentials']['database'],
        ];

        return $this->credentials;
    }

    private function rpc(string $method, array $params = []): array
    {
        $response = Http::timeout(30)
            ->post($this->endpoint(), [
                'method' => $method,
                'params' => $params,
                'id'     => 1,
            ]);

        return $response->json() ?? [];
    }

    private function call(string $method, array $params = []): array
    {
        $creds = $this->authenticate();

        $result = $this->rpc($method, array_merge($params, ['credentials' => $creds]));

        if (isset($result['error'])) {
            throw new \RuntimeException("Geotab {$method} error: " . ($result['error']['message'] ?? 'unknown'));
        }

        return $result['result'] ?? [];
    }

    // ── Contract ──────────────────────────────────────────────────────────────

    public function testConnection(): array
    {
        try {
            $this->authenticate();
            $devices = $this->call('Get', ['typeName' => 'Device', 'resultsLimit' => 1]);

            return [
                'success' => true,
                'message' => 'Geotab connection successful. Database: ' . ($this->credentials['database'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(): array
    {
        $synced = 0; $errors = 0;

        try {
            // ── 1. Device list (vehicles) ──────────────────────────────────────
            $devices = $this->call('Get', ['typeName' => 'Device']);

            $deviceMap = []; // geotab device id => local vehicle_id
            foreach ($devices as $d) {
                $vehicleId = $this->resolveVehicleId($d['licensePlate'] ?? $d['name'] ?? '');
                if ($vehicleId) {
                    $deviceMap[$d['id']] = $vehicleId;
                }
            }

            // ── 2. Latest GPS status ───────────────────────────────────────────
            $statuses = $this->call('Get', [
                'typeName'    => 'DeviceStatusInfo',
                'resultsLimit' => 10000,
            ]);

            foreach ($statuses as $s) {
                $vehicleId = $deviceMap[$s['device']['id'] ?? ''] ?? null;
                if (!$vehicleId) continue;

                $this->upsertPosition([
                    'vehicle_id'          => $vehicleId,
                    'provider'            => $this->provider,
                    'provider_vehicle_id' => $s['device']['id'] ?? null,
                    'latitude'            => $s['latitude'] ?? null,
                    'longitude'           => $s['longitude'] ?? null,
                    'speed_kmh'           => $s['speed'] ?? null,
                    'heading'             => $s['bearing'] ?? null,
                    'odometer_km'         => isset($s['currentStateDuration'])
                        ? null : null, // odometer from LogRecord below
                    'ignition_status'     => ($s['isDeviceCommunicating'] ?? false) ? 'on' : 'unknown',
                    'recorded_at'         => $s['dateTime'] ?? null,
                ]);
                $synced++;
            }

            // ── 3. Exception events (harsh braking/speeding) last 24 h ─────────
            $fromDate = now()->subHours(24)->toIso8601String();
            $toDate   = now()->toIso8601String();

            $exceptions = $this->call('Get', [
                'typeName'    => 'ExceptionEvent',
                'search'      => ['fromDate' => $fromDate, 'toDate' => $toDate],
                'resultsLimit' => 1000,
            ]);

            foreach ($exceptions as $ex) {
                $vehicleId = $deviceMap[$ex['device']['id'] ?? ''] ?? null;
                if (!$vehicleId) continue;

                $this->insertEvent([
                    'vehicle_id'        => $vehicleId,
                    'provider'          => $this->provider,
                    'provider_event_id' => $ex['id'] ?? null,
                    'event_type'        => $this->mapExceptionType($ex['rule']['name'] ?? ''),
                    'severity'          => 'medium',
                    'latitude'          => $ex['location']['x'] ?? null,
                    'longitude'         => $ex['location']['y'] ?? null,
                    'speed_kmh'         => null,
                    'occurred_at'       => $ex['activeFrom'] ?? now(),
                ]);
                $synced++;
            }

            $this->recordSyncResult('success', "Geotab sync complete. {$synced} records updated.");
        } catch (\Throwable $e) {
            $errors++;
            $this->recordSyncResult('error', 'Geotab sync error: ' . $e->getMessage());
            $this->log('Sync error', ['error' => $e->getMessage()]);
        }

        return ['synced' => $synced, 'errors' => $errors, 'message' => "Geotab: {$synced} synced, {$errors} errors."];
    }

    private function mapExceptionType(string $ruleName): string
    {
        $name = strtolower($ruleName);
        if (str_contains($name, 'harsh') && str_contains($name, 'brak'))  return 'harsh_braking';
        if (str_contains($name, 'harsh') && str_contains($name, 'accel')) return 'harsh_acceleration';
        if (str_contains($name, 'speed'))                                  return 'speeding';
        if (str_contains($name, 'idle'))                                   return 'idle';
        return strtolower(str_replace(' ', '_', $ruleName));
    }
}
