<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters\ClockIn;

use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;

/**
 * Jarrison Time.
 *
 * Common alongside Impro hardware. Frequently deployed on-premise with no public endpoint, so this
 * adapter supports both a REST bridge and the inbound-webhook path — a customer whose Jarrison
 * server cannot be reached from outside pushes to us instead.
 */
class JarrisonAdapter extends AbstractAccessAdapter
{
    public function testConnection(): array
    {
        if (($this->record->api_endpoint ?? '') === '') {
            // Push-only: nothing to reach, and that is a valid configuration rather than a fault.
            return ['ok' => true,
                'message' => 'Configured for inbound push. Point Jarrison at the webhook URL.'];
        }

        $response = $this->http($this->headers())->get($this->endpoint('api/status'));

        return ['ok' => $response->successful(),
            'message' => $response->successful()
                ? 'Connected to Jarrison.'
                : "Jarrison returned {$response->status()}."];
    }

    protected function fetchEvents(): array
    {
        if (($this->record->api_endpoint ?? '') === '') {
            return [];
        }

        $response = $this->http($this->headers())->get($this->endpoint('api/clockings'), [
            'db' => $this->config['db_name'] ?? null,
            'from' => $this->since()->format('Y-m-d\TH:i:s'),
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('items') ?? [])->map(fn ($c) => [
            'identifier' => (string) ($c['EmployeeCode'] ?? ''),
            'direction' => $this->direction($c['InOut'] ?? null),
            'occurred_at' => $c['ClockDateTime'] ?? now()->toIso8601String(),
            'device_ref' => $c['TerminalName'] ?? null,
        ])->filter(fn ($e) => $e['identifier'] !== '')->values()->all();
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->decrypt($this->record->api_key)];
    }
}
