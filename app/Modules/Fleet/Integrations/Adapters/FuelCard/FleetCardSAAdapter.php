<?php

namespace App\Modules\Fleet\Integrations\Adapters\FuelCard;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Engen / FleetCard South Africa adapter.
 *
 * Uses API Key + Secret in request headers.
 * Endpoint: https://api.fleetcard.co.za/v2
 * Docs:     Contact Engen/FleetCard SA for API documentation.
 */
class FleetCardSAAdapter extends AbstractFleetAdapter
{
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(30)
            ->withHeaders([
                'X-API-Key'    => $this->config['api_key'],
                'X-API-Secret' => $this->config['api_secret'],
                'Accept'       => 'application/json',
            ]);
    }

    public function testConnection(): array
    {
        try {
            $resp = $this->http()->get($this->endpoint('/accounts'), ['limit' => 1]);
            return $resp->successful()
                ? ['success' => true,  'message' => 'FleetCard SA connection successful.']
                : ['success' => false, 'message' => 'FleetCard SA returned HTTP ' . $resp->status() . ': ' . $resp->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sync(): array
    {
        $synced = 0; $errors = 0;
        $since  = now()->subHours(24)->format('Y-m-d\TH:i:s');
        $cardRange = $this->config['card_range'] ?? null;

        try {
            $page = 1;
            do {
                $params = ['from' => $since, 'page' => $page, 'per_page' => 200];
                if ($cardRange) $params['card_prefix'] = $cardRange;

                $resp         = $this->http()->get($this->endpoint('/transactions'), $params);
                $transactions = $resp->json('data', []);
                $meta         = $resp->json('meta', []);

                foreach ($transactions as $tx) {
                    $vehicleId  = $this->resolveVehicleId($tx['registration'] ?? $tx['licence_plate'] ?? '');
                    $cardId     = $vehicleId ? $this->resolveFleetCardId($vehicleId) : null;

                    if (!$cardId) {
                        // Try to match by card number
                        $last4  = substr($tx['card_number'] ?? '', -4);
                        $cardId = DB::table('fleet_cards')
                            ->where('card_number_last4', $last4)
                            ->value('id');
                        if ($cardId && !$vehicleId) {
                            $vehicleId = DB::table('fleet_cards')->where('id', $cardId)->value('vehicle_id');
                        }
                    }

                    if (!$cardId) { $errors++; continue; }

                    $inserted = $this->insertFuelTransaction([
                        'fleet_card_id'    => $cardId,
                        'vehicle_id'       => $vehicleId,
                        'transaction_type' => $this->mapTxType($tx['transaction_type'] ?? 'fuel'),
                        'transaction_at'   => $tx['transaction_date'] ?? now(),
                        'merchant_name'    => $tx['merchant_name'] ?? null,
                        'merchant_city'    => $tx['merchant_city'] ?? null,
                        'amount'           => $tx['amount'] ?? 0,
                        'litres'           => $tx['quantity'] ?? null,
                        'price_per_litre'  => $tx['unit_price'] ?? null,
                        'odometer_reading' => $tx['odometer'] ?? null,
                        'fuel_type'        => $tx['fuel_type'] ?? null,
                        'reference'        => $tx['transaction_reference'] ?? $tx['id'] ?? null,
                        'is_flagged'       => false,
                    ]);

                    $inserted ? $synced++ : null;
                }
                $page++;
            } while (($meta['current_page'] ?? 1) < ($meta['last_page'] ?? 1));

            $this->recordSyncResult('success', "FleetCard SA: {$synced} transactions imported.");
        } catch (\Throwable $e) {
            $errors++;
            $this->recordSyncResult('error', 'FleetCard SA sync error: ' . $e->getMessage());
            $this->log('Sync error', ['error' => $e->getMessage()]);
        }

        return ['synced' => $synced, 'errors' => $errors, 'message' => "FleetCard SA: {$synced} synced."];
    }

    private function mapTxType(string $raw): string
    {
        return match (strtolower($raw)) {
            'fuel', 'petrol', 'diesel' => 'fuel',
            'oil'                      => 'oil',
            'service', 'maintenance'   => 'service',
            'toll'                     => 'toll',
            default                    => 'other',
        };
    }
}
