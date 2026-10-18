<?php

namespace App\Modules\Fleet\Integrations\Adapters\Fines;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use Illuminate\Support\Facades\Http;

/**
 * DigiTraffic / Fines SA adapter.
 *
 * Authentication: API key passed as X-Api-Key header (no token exchange).
 *
 * Fines endpoint: GET /v2/infringements
 *   Query: api_key (or header), from (YYYY-MM-DD), page, limit
 *   Response: { infringements: [...], total, page, pages }
 *
 * Infringement object:
 *   ref_number, plate_number, offence_type, description, offence_datetime,
 *   location, amount_due, total_amount, authority, payment_status (unpaid|paid),
 *   discount_expires, demerit_points, notice_pdf_url
 */
class DigiTrafficAdapter extends AbstractFleetAdapter
{
    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders(['X-Api-Key' => $this->config['api_key']])
                ->timeout(15)
                ->get($this->endpoint('/v2/infringements'), ['limit' => 1, 'page' => 1]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'DigiTraffic connected successfully.'];
            }

            return ['success' => false, 'message' => 'HTTP ' . $response->status() . ': ' . $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(): array
    {
        try {
            $synced = 0;
            $errors = 0;
            $page   = 1;
            $from   = now()->subDays(30)->toDateString();

            do {
                $response = Http::withHeaders(['X-Api-Key' => $this->config['api_key']])
                    ->timeout(30)
                    ->get($this->endpoint('/v2/infringements'), [
                        'from'  => $from,
                        'limit' => 100,
                        'page'  => $page,
                    ]);

                if (!$response->successful()) {
                    $this->log('DigiTraffic page fetch failed', ['status' => $response->status()]);
                    break;
                }

                $body       = $response->json();
                $items      = $body['infringements'] ?? [];
                $totalPages = $body['pages']         ?? 1;

                foreach ($items as $item) {
                    $plate     = $item['plate_number'] ?? null;
                    $vehicleId = $plate ? $this->resolveVehicleId($plate) : null;

                    if (!$vehicleId) { $errors++; continue; }

                    $offenceAt = $item['offence_datetime'] ?? null;
                    $booking   = $offenceAt ? $this->resolveBookingForVehicle($vehicleId, $offenceAt) : [];

                    $status = match ($item['payment_status'] ?? '') {
                        'paid'  => 'paid',
                        default => 'outstanding',
                    };

                    $inserted = $this->insertFine([
                        'vehicle_id'           => $vehicleId,
                        'driver_id'            => $booking['driver_id']  ?? null,
                        'booking_id'           => $booking['booking_id'] ?? null,
                        'provider'             => 'digitraffic',
                        'provider_fine_id'     => (string) ($item['ref_number'] ?? ''),
                        'fine_number'          => $item['ref_number']         ?? null,
                        'offence_code'         => $item['offence_type']       ?? null,
                        'offence_description'  => $item['description']        ?? null,
                        'offence_date'         => $offenceAt,
                        'location'             => $item['location']           ?? null,
                        'amount'               => $item['total_amount']       ?? 0,
                        'outstanding_amount'   => $item['amount_due']         ?? null,
                        'issuing_authority'    => $item['authority']          ?? null,
                        'status'               => $status,
                        'due_date'             => $item['discount_expires']   ?? null,
                        'aarto_points'         => $item['demerit_points']     ?? null,
                        'infringement_notice_path' => $item['notice_pdf_url'] ?? null,
                    ]);

                    $inserted ? $synced++ : null;
                }

                $page++;
            } while ($page <= $totalPages);

            $message = "Synced {$synced} fines, {$errors} unmatched vehicles.";
            $this->recordSyncResult('success', $message);
            return ['provider' => 'digitraffic', 'synced' => $synced, 'errors' => $errors, 'message' => $message];

        } catch (\Throwable $e) {
            $this->recordSyncResult('error', $e->getMessage());
            return ['provider' => 'digitraffic', 'synced' => 0, 'errors' => 0, 'message' => $e->getMessage()];
        }
    }
}
