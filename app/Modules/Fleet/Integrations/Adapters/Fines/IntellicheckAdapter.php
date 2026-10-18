<?php

namespace App\Modules\Fleet\Integrations\Adapters\Fines;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use Illuminate\Support\Facades\Http;

/**
 * Intellicheck AARTO / Traffic Fine API adapter.
 *
 * Authentication: POST /api/v1/auth/login  { username, password }
 *                 → { token }  (Bearer)
 *
 * Fines endpoint: GET /api/v1/fines?page=1&per_page=100&from_date=YYYY-MM-DD
 *                 → { data: [...], meta: { current_page, last_page } }
 *
 * Each fine object:
 *   id, registration_number, fine_number, offence_code, offence_description,
 *   offence_date (ISO8601), location, amount, outstanding_amount,
 *   issuing_authority, status (unpaid|paid|cancelled), due_date,
 *   aarto_points, notice_url
 */
class IntellicheckAdapter extends AbstractFleetAdapter
{
    private ?string $token = null;

    public function testConnection(): array
    {
        try {
            $tok = $this->authenticate();
            if (!$tok) return ['success' => false, 'message' => 'Authentication failed — check credentials.'];
            return ['success' => true, 'message' => 'Intellicheck connected successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(): array
    {
        try {
            $token = $this->authenticate();
            if (!$token) {
                $this->recordSyncResult('error', 'Authentication failed.');
                return ['provider' => 'intellicheck', 'synced' => 0, 'errors' => 0, 'message' => 'Auth failed.'];
            }

            $synced  = 0;
            $errors  = 0;
            $page    = 1;
            $from    = now()->subDays(30)->toDateString();

            do {
                $response = Http::withToken($token)
                    ->timeout(30)
                    ->get($this->endpoint('/api/v1/fines'), [
                        'page'     => $page,
                        'per_page' => 100,
                        'from_date'=> $from,
                    ]);

                if (!$response->successful()) {
                    $this->log('Fines page fetch failed', ['status' => $response->status()]);
                    break;
                }

                $body      = $response->json();
                $fines     = $body['data']    ?? [];
                $lastPage  = $body['meta']['last_page'] ?? 1;

                foreach ($fines as $fine) {
                    $reg       = $fine['registration_number'] ?? null;
                    $vehicleId = $reg ? $this->resolveVehicleId($reg) : null;

                    if (!$vehicleId) { $errors++; continue; }

                    $offenceAt = $fine['offence_date'] ?? null;
                    $booking   = $offenceAt ? $this->resolveBookingForVehicle($vehicleId, $offenceAt) : [];

                    $statusMap = ['unpaid' => 'outstanding', 'paid' => 'paid', 'cancelled' => 'written_off'];

                    $inserted = $this->insertFine([
                        'vehicle_id'           => $vehicleId,
                        'driver_id'            => $booking['driver_id']  ?? null,
                        'booking_id'           => $booking['booking_id'] ?? null,
                        'provider'             => 'intellicheck',
                        'provider_fine_id'     => (string) ($fine['id'] ?? ''),
                        'fine_number'          => $fine['fine_number']          ?? null,
                        'offence_code'         => $fine['offence_code']         ?? null,
                        'offence_description'  => $fine['offence_description']  ?? null,
                        'offence_date'         => $offenceAt,
                        'location'             => $fine['location']             ?? null,
                        'amount'               => $fine['amount']               ?? 0,
                        'outstanding_amount'   => $fine['outstanding_amount']   ?? null,
                        'issuing_authority'    => $fine['issuing_authority']    ?? null,
                        'status'               => $statusMap[$fine['status'] ?? ''] ?? 'outstanding',
                        'due_date'             => $fine['due_date']             ?? null,
                        'aarto_points'         => $fine['aarto_points']         ?? null,
                        'infringement_notice_path' => $fine['notice_url']       ?? null,
                    ]);

                    $inserted ? $synced++ : null;
                }

                $page++;
            } while ($page <= $lastPage);

            $message = "Synced {$synced} fines, {$errors} unmatched vehicles.";
            $this->recordSyncResult('success', $message);
            return ['provider' => 'intellicheck', 'synced' => $synced, 'errors' => $errors, 'message' => $message];

        } catch (\Throwable $e) {
            $this->recordSyncResult('error', $e->getMessage());
            return ['provider' => 'intellicheck', 'synced' => 0, 'errors' => 0, 'message' => $e->getMessage()];
        }
    }

    private function authenticate(): ?string
    {
        if ($this->token) return $this->token;

        $response = Http::timeout(15)->post($this->endpoint('/api/v1/auth/login'), [
            'username' => $this->config['api_key'],
            'password' => $this->config['api_secret'],
        ]);

        $this->token = $response->json('token');
        return $this->token ?: null;
    }
}
