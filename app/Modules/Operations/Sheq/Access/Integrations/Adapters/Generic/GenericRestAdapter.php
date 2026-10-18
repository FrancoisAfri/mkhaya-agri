<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters\Generic;

use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;

/**
 * Any REST endpoint that lists access events.
 *
 * The adapter that makes the brief's promise true for vendors nobody has written code for. Field
 * names come from `additional_config`, so connecting an unlisted controller is a configuration task
 * rather than a development one — which matters because the long tail of South African access
 * control is enormous and no vendor list will ever cover it.
 */
class GenericRestAdapter extends AbstractAccessAdapter
{
    public function testConnection(): array
    {
        $response = $this->http($this->headers())
            ->get($this->endpoint($this->config['events_path'] ?? ''));

        return ['ok' => $response->successful(),
            'message' => $response->successful()
                ? 'Endpoint reachable.'
                : "Endpoint returned {$response->status()}."];
    }

    protected function fetchEvents(): array
    {
        $response = $this->http($this->headers())
            ->get($this->endpoint($this->config['events_path'] ?? ''), [
                ($this->config['since_param'] ?? 'since') => $this->since()->toIso8601String(),
            ]);

        if (! $response->successful()) {
            return [];
        }

        $idField = $this->config['identifier_field'] ?? 'identifier';
        $timeField = $this->config['timestamp_field'] ?? 'timestamp';
        $dirField = $this->config['direction_field'] ?? 'direction';

        // The payload may be a bare array or wrapped in a key — both are common enough that
        // guessing wrong would make half of these connections fail silently.
        $rows = $response->json($this->config['collection_key'] ?? null) ?? $response->json() ?? [];

        return collect(is_array($rows) ? $rows : [])->map(fn ($r) => [
            'identifier' => (string) data_get($r, $idField, ''),
            'direction' => $this->direction(data_get($r, $dirField)),
            'occurred_at' => data_get($r, $timeField, now()->toIso8601String()),
            'device_ref' => data_get($r, $this->config['device_field'] ?? 'device'),
        ])->filter(fn ($e) => $e['identifier'] !== '')->values()->all();
    }

    private function headers(): array
    {
        $header = $this->config['auth_header'] ?? 'Authorization';
        $prefix = $this->config['auth_prefix'] ?? 'Bearer ';

        return [$header => $prefix.$this->decrypt($this->record->api_key)];
    }
}
