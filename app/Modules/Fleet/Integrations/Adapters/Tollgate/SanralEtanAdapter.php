<?php

namespace App\Modules\Fleet\Integrations\Adapters\Tollgate;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SANRAL e-Toll / ETAN API adapter.
 *
 * SANRAL ETAN uses account number + PIN authentication.
 * Endpoint: https://etan.sanral.co.za/api  (or staging equivalent)
 * Docs:     Contact SANRAL for ETAN API documentation.
 */
class SanralEtanAdapter extends AbstractFleetAdapter
{
    private ?string $sessionToken = null;

    private function authenticate(): string
    {
        if ($this->sessionToken) return $this->sessionToken;

        $response = Http::timeout(15)
            ->post($this->endpoint('/auth/login'), [
                'account_number' => $this->config['api_key'],
                'pin'            => $this->config['api_secret'],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SANRAL ETAN auth failed: ' . $response->body());
        }

        $this->sessionToken = $response->json('session_token')
            ?? $response->json('token')
            ?? throw new \RuntimeException('SANRAL ETAN: no session token in response.');

        return $this->sessionToken;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(30)
            ->withHeaders([
                'X-Session-Token' => $this->authenticate(),
                'Accept'          => 'application/json',
            ]);
    }

    public function testConnection(): array
    {
        try {
            $this->authenticate();
            $resp = $this->http()->get($this->endpoint('/account/details'));
            return $resp->successful()
                ? ['success' => true,  'message' => 'SANRAL ETAN connection successful. Account: ' . ($resp->json('account_number') ?? 'verified')]
                : ['success' => false, 'message' => 'SANRAL ETAN returned HTTP ' . $resp->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(): array
    {
        $synced = 0; $errors = 0;

        try {
            // Fetch toll transactions for the last 24 hours
            $transactions = $this->http()->get($this->endpoint('/transactions'), [
                'from_date' => now()->subHours(24)->format('Y-m-d H:i:s'),
                'to_date'   => now()->format('Y-m-d H:i:s'),
                'per_page'  => 500,
            ])->json('transactions', []);

            foreach ($transactions as $tx) {
                $vehicleId = $this->resolveVehicleId($tx['licence_plate'] ?? $tx['registration'] ?? '');
                if (!$vehicleId) { $errors++; continue; }

                // Find the active booking for this vehicle around the toll time
                $tollAt   = $tx['transaction_date'] ?? $tx['transaction_at'] ?? now();
                $bookingId = DB::table('fleet_bookings')
                    ->where('vehicle_id', $vehicleId)
                    ->whereIn('workflow_state', ['in_use', 'approved', 'returned'])
                    ->where('departure_at', '<=', $tollAt)
                    ->orderByDesc('departure_at')
                    ->value('id');

                if (!$bookingId) { $errors++; continue; } // can't store without booking_id (FK constraint)

                $inserted = $this->insertTollgate([
                    'booking_id'   => $bookingId,
                    'vehicle_id'   => $vehicleId,
                    'amount'       => $tx['amount'] ?? 0,
                    'toll_location'=> $tx['gantry_name'] ?? $tx['toll_plaza'] ?? $tx['location'] ?? null,
                    'toll_at'      => $tollAt,
                ]);

                $inserted ? $synced++ : null;
            }

            $this->recordSyncResult('success', "SANRAL ETAN: {$synced} toll transactions imported.");
        } catch (\Throwable $e) {
            $errors++;
            $this->recordSyncResult('error', 'SANRAL ETAN sync error: ' . $e->getMessage());
            $this->log('Sync error', ['error' => $e->getMessage()]);
        }

        return ['synced' => $synced, 'errors' => $errors, 'message' => "SANRAL ETAN: {$synced} synced."];
    }
}
