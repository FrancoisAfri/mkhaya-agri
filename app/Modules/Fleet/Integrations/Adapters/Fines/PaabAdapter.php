<?php

namespace App\Modules\Fleet\Integrations\Adapters\Fines;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use Illuminate\Support\Facades\Http;

/**
 * PAAB (Pinpoint Alert Broker) adapter.
 *
 * Authentication: OAuth2 client_credentials
 *   POST /oauth/token  { grant_type, client_id, client_secret }
 *   → { access_token, expires_in }
 *
 * Fines endpoint: GET /api/fleet/infringements
 *   Headers: Authorization: Bearer {token}
 *   Query:   fleet_account_id, page, page_size, date_from (YYYY-MM-DD)
 *   Response: { results: [...], count, next }
 *
 * Result object:
 *   infringement_id, vehicle_registration, notice_number, offence_code,
 *   offence_detail, infringement_date, infringement_location, fine_amount,
 *   balance_due, issuing_authority_code, infringement_status (OUTSTANDING|PAID|DISPUTED|WRITTEN_OFF),
 *   dispute_reason, dispute_date, payment_date, payment_ref, due_date,
 *   aarto_demerit_points, notice_document_url, remarks
 */
class PaabAdapter extends AbstractFleetAdapter
{
    private ?string $accessToken   = null;
    private ?int    $tokenExpiry   = null;

    public function testConnection(): array
    {
        try {
            $token = $this->authenticate();
            if (!$token) return ['success' => false, 'message' => 'PAAB OAuth failed — check client credentials.'];

            $response = Http::withToken($token)
                ->timeout(15)
                ->get($this->endpoint('/api/fleet/infringements'), ['page_size' => 1]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'PAAB connected successfully.'];
            }

            return ['success' => false, 'message' => 'HTTP ' . $response->status() . ': ' . $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(): array
    {
        try {
            $token = $this->authenticate();
            if (!$token) {
                $this->recordSyncResult('error', 'PAAB OAuth authentication failed.');
                return ['provider' => 'paab', 'synced' => 0, 'errors' => 0, 'message' => 'Auth failed.'];
            }

            $synced      = 0;
            $errors      = 0;
            $page        = 1;
            $accountId   = $this->config['account_id'] ?? '';
            $from        = now()->subDays(30)->toDateString();

            do {
                $response = Http::withToken($token)
                    ->timeout(30)
                    ->get($this->endpoint('/api/fleet/infringements'), [
                        'fleet_account_id' => $accountId,
                        'page'             => $page,
                        'page_size'        => 100,
                        'date_from'        => $from,
                    ]);

                if (!$response->successful()) {
                    $this->log('PAAB page fetch failed', ['status' => $response->status()]);
                    break;
                }

                $body  = $response->json();
                $items = $body['results'] ?? [];
                $hasNext = !empty($body['next']);

                foreach ($items as $item) {
                    $reg       = $item['vehicle_registration'] ?? null;
                    $vehicleId = $reg ? $this->resolveVehicleId($reg) : null;

                    if (!$vehicleId) { $errors++; continue; }

                    $offenceAt = $item['infringement_date'] ?? null;
                    $booking   = $offenceAt ? $this->resolveBookingForVehicle($vehicleId, $offenceAt) : [];

                    $statusMap = [
                        'OUTSTANDING' => 'outstanding',
                        'PAID'        => 'paid',
                        'DISPUTED'    => 'disputed',
                        'WRITTEN_OFF' => 'written_off',
                    ];

                    $inserted = $this->insertFine([
                        'vehicle_id'           => $vehicleId,
                        'driver_id'            => $booking['driver_id']  ?? null,
                        'booking_id'           => $booking['booking_id'] ?? null,
                        'provider'             => 'paab',
                        'provider_fine_id'     => (string) ($item['infringement_id'] ?? ''),
                        'fine_number'          => $item['notice_number']           ?? null,
                        'offence_code'         => $item['offence_code']            ?? null,
                        'offence_description'  => $item['offence_detail']          ?? null,
                        'offence_date'         => $offenceAt,
                        'location'             => $item['infringement_location']   ?? null,
                        'amount'               => $item['fine_amount']             ?? 0,
                        'outstanding_amount'   => $item['balance_due']             ?? null,
                        'issuing_authority'    => $item['issuing_authority_code']  ?? null,
                        'status'               => $statusMap[$item['infringement_status'] ?? ''] ?? 'outstanding',
                        'dispute_reason'       => $item['dispute_reason']          ?? null,
                        'dispute_date'         => $item['dispute_date']            ?? null,
                        'paid_at'              => $item['payment_date']            ?? null,
                        'payment_reference'    => $item['payment_ref']             ?? null,
                        'due_date'             => $item['due_date']                ?? null,
                        'aarto_points'         => $item['aarto_demerit_points']    ?? null,
                        'infringement_notice_path' => $item['notice_document_url'] ?? null,
                        'notes'                => $item['remarks']                 ?? null,
                    ]);

                    $inserted ? $synced++ : null;
                }

                $page++;
            } while ($hasNext);

            $message = "Synced {$synced} fines, {$errors} unmatched vehicles.";
            $this->recordSyncResult('success', $message);
            return ['provider' => 'paab', 'synced' => $synced, 'errors' => $errors, 'message' => $message];

        } catch (\Throwable $e) {
            $this->recordSyncResult('error', $e->getMessage());
            return ['provider' => 'paab', 'synced' => 0, 'errors' => 0, 'message' => $e->getMessage()];
        }
    }

    private function authenticate(): ?string
    {
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry - 60) {
            return $this->accessToken;
        }

        $response = Http::asForm()->timeout(15)->post($this->endpoint('/oauth/token'), [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->config['api_key'],
            'client_secret' => $this->config['api_secret'],
        ]);

        $data = $response->json();
        $this->accessToken = $data['access_token'] ?? null;
        $this->tokenExpiry = $this->accessToken ? time() + ($data['expires_in'] ?? 3600) : null;

        return $this->accessToken;
    }
}
