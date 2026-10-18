<?php

namespace App\Modules\Fleet\Integrations\Adapters\FuelCard;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * WEX Fleet Card API adapter.
 *
 * WEX uses OAuth2 client_credentials flow.
 * Endpoint: https://api.wexinc.com/v1
 * Docs:     https://developer.wexinc.com
 */
class WexAdapter extends AbstractFleetAdapter
{
    private ?string $token = null;

    private function authenticate(): string
    {
        if ($this->token) return $this->token;

        $response = Http::timeout(15)
            ->asForm()
            ->post($this->endpoint('/oauth/token'), [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->config['api_key'],
                'client_secret' => $this->config['api_secret'],
                'scope'         => 'transactions:read',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('WEX auth failed: ' . $response->body());
        }

        $this->token = $response->json('access_token')
            ?? throw new \RuntimeException('WEX: no access_token in response.');

        return $this->token;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(30)->withToken($this->authenticate())->acceptJson();
    }

    public function testConnection(): array
    {
        try {
            $this->authenticate();
            $resp = $this->http()->get($this->endpoint('/accounts/' . ($this->config['account_id'] ?? '')));
            return $resp->successful()
                ? ['success' => true,  'message' => 'WEX connection successful.']
                : ['success' => false, 'message' => 'WEX returned HTTP ' . $resp->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(): array
    {
        $synced = 0; $errors = 0;
        $accountId = $this->config['account_id'] ?? '';
        $since     = now()->subHours(24)->format('Y-m-d');

        try {
            $resp         = $this->http()->get($this->endpoint("/accounts/{$accountId}/transactions"), [
                'start_date' => $since,
                'end_date'   => now()->format('Y-m-d'),
                'page_size'  => 500,
            ]);
            $transactions = $resp->json('transactions', []);

            foreach ($transactions as $tx) {
                $vehicleId = $this->resolveVehicleId($tx['vehicle']['license_plate'] ?? '');
                $last4     = substr($tx['card_number'] ?? '', -4);
                $cardId    = DB::table('fleet_cards')->where('card_number_last4', $last4)->value('id');

                if (!$cardId) { $errors++; continue; }
                if (!$vehicleId && $cardId) {
                    $vehicleId = DB::table('fleet_cards')->where('id', $cardId)->value('vehicle_id');
                }

                $inserted = $this->insertFuelTransaction([
                    'fleet_card_id'    => $cardId,
                    'vehicle_id'       => $vehicleId,
                    'transaction_type' => strtolower($tx['product_type'] ?? '') === 'diesel' ? 'fuel' : ($tx['product_type'] === 'oil' ? 'oil' : 'fuel'),
                    'transaction_at'   => $tx['transaction_date'] ?? now(),
                    'merchant_name'    => $tx['merchant']['name'] ?? null,
                    'merchant_city'    => $tx['merchant']['city'] ?? null,
                    'amount'           => $tx['amount'] ?? 0,
                    'litres'           => $tx['quantity'] ?? null,
                    'price_per_litre'  => $tx['unit_price'] ?? null,
                    'odometer_reading' => $tx['odometer'] ?? null,
                    'fuel_type'        => $tx['product_type'] ?? null,
                    'reference'        => $tx['transaction_id'] ?? null,
                    'is_flagged'       => false,
                ]);

                $inserted ? $synced++ : null;
            }

            $this->recordSyncResult('success', "WEX: {$synced} transactions imported.");
        } catch (\Throwable $e) {
            $errors++;
            $this->recordSyncResult('error', 'WEX sync error: ' . $e->getMessage());
            $this->log('Sync error', ['error' => $e->getMessage()]);
        }

        return ['synced' => $synced, 'errors' => $errors, 'message' => "WEX: {$synced} synced."];
    }
}
