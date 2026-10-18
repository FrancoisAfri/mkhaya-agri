<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters\ClockIn;

use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;
use Illuminate\Support\Carbon;

/**
 * Uniclox.
 *
 * Long-established in South African manufacturing. The API is plain and stable; the only wrinkle is
 * that it returns local time with no offset, so timestamps are read in the site's timezone rather
 * than assumed to be UTC — an eight-hour error in an access log is worse than no log.
 */
class UnicloxAdapter extends AbstractAccessAdapter
{
    public function testConnection(): array
    {
        $response = $this->http($this->headers())->get($this->endpoint('api/ping'));

        return ['ok' => $response->successful(),
            'message' => $response->successful()
                ? 'Connected to Uniclox.'
                : "Uniclox returned {$response->status()}."];
    }

    protected function fetchEvents(): array
    {
        $response = $this->http($this->headers())->get($this->endpoint('api/transactions'), [
            'since' => $this->since()->format('Y-m-d H:i:s'),
        ]);

        if (! $response->successful()) {
            return [];
        }

        $timezone = $this->config['timezone'] ?? 'Africa/Johannesburg';

        return collect($response->json() ?? [])->map(function ($t) use ($timezone) {
            $raw = $t['datetime'] ?? null;

            return [
                'identifier' => (string) ($t['employee_no'] ?? ''),
                'direction' => $this->direction($t['direction'] ?? null),
                // Local time with no offset: interpreted in the site's zone, not assumed UTC.
                'occurred_at' => $raw === null ? now()->toIso8601String()
                    : Carbon::parse($raw, $timezone)->toIso8601String(),
                'device_ref' => $t['clock_id'] ?? null,
            ];
        })->filter(fn ($e) => $e['identifier'] !== '')->values()->all();
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->decrypt($this->record->api_key)];
    }
}
