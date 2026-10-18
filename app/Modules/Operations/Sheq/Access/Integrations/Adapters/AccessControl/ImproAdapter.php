<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters\AccessControl;

use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;

/**
 * Impro Access (IXP / Access Portal).
 *
 * Widely installed on South African mid-market sites, usually already running the turnstiles before
 * SHEQ arrives. Reads transaction history rather than subscribing: the on-premise server is normally
 * behind the customer's firewall with no route back to us, so polling is the only thing that works
 * without asking their IT for an inbound rule.
 */
class ImproAdapter extends AbstractAccessAdapter
{
    public function testConnection(): array
    {
        $response = $this->http([
            'X-API-Key' => $this->decrypt($this->record->api_key),
        ])->get($this->endpoint('api/v1/sites'));

        return ['ok' => $response->successful(),
            'message' => $response->successful()
                ? 'Connected to Impro.'
                : "Impro returned {$response->status()}."];
    }

    protected function fetchEvents(): array
    {
        $response = $this->http([
            'X-API-Key' => $this->decrypt($this->record->api_key),
        ])->get($this->endpoint('api/v1/transactions'), [
            'siteCode' => $this->config['site_code'] ?? null,
            'from' => $this->since()->toIso8601String(),
        ]);

        if (! $response->successful()) {
            $this->log('Transaction fetch failed', ['status' => $response->status()]);

            return [];
        }

        return collect($response->json('transactions') ?? [])->map(fn ($t) => [
            'identifier' => (string) ($t['cardNumber'] ?? $t['idNumber'] ?? ''),
            'direction' => $this->direction($t['direction'] ?? null),
            'occurred_at' => $t['timestamp'] ?? now()->toIso8601String(),
            'device_ref' => $t['readerName'] ?? null,
        ])->filter(fn ($e) => $e['identifier'] !== '')->values()->all();
    }
}
