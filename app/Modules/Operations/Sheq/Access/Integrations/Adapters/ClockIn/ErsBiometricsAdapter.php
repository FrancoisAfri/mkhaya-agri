<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters\ClockIn;

use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;

/**
 * ERS Biometrics.
 *
 * A South African time-and-attendance system that payroll usually already reads. Clock-in data is
 * useful to SHEQ for a reason that is not obvious: it is the most reliable record of who was
 * actually on site, and it is collected whether or not anyone remembers to use the SHEQ app.
 */
class ErsBiometricsAdapter extends AbstractAccessAdapter
{
    public function testConnection(): array
    {
        $response = $this->http($this->headers())->get($this->endpoint('api/branches'));

        return ['ok' => $response->successful(),
            'message' => $response->successful()
                ? 'Connected to ERS Biometrics.'
                : "ERS returned {$response->status()}."];
    }

    protected function fetchEvents(): array
    {
        $response = $this->http($this->headers())->get($this->endpoint('api/clockings'), [
            'branch' => $this->config['branch_code'] ?? null,
            'from' => $this->since()->format('Y-m-d H:i'),
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('clockings') ?? [])->map(fn ($c) => [
            'identifier' => (string) ($c['employeeNumber'] ?? $c['idNumber'] ?? ''),
            'direction' => $this->direction($c['type'] ?? 'CLOCK_IN'),
            'occurred_at' => $c['clockTime'] ?? now()->toIso8601String(),
            'device_ref' => $c['deviceName'] ?? null,
        ])->filter(fn ($e) => $e['identifier'] !== '')->values()->all();
    }

    private function headers(): array
    {
        return [
            'X-Api-Key' => $this->decrypt($this->record->api_key),
            'X-Api-Secret' => $this->decrypt($this->record->api_secret),
        ];
    }
}
