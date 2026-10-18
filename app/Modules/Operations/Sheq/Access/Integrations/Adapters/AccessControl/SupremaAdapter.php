<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters\AccessControl;

use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;

/**
 * Suprema BioStar 2.
 *
 * Common on larger industrial estates. Authenticates with a session header rather than a bearer
 * token, which is why the session id is fetched on each call rather than cached — BioStar expires
 * them aggressively and a stale session reads as a credential failure, sending an administrator
 * hunting for a problem that is not there.
 */
class SupremaAdapter extends AbstractAccessAdapter
{
    public function testConnection(): array
    {
        $session = $this->session();

        return ['ok' => $session !== null,
            'message' => $session !== null
                ? 'Connected to BioStar 2.'
                : 'BioStar 2 rejected the credentials.'];
    }

    protected function fetchEvents(): array
    {
        $session = $this->session();

        if ($session === null) {
            return [];
        }

        $response = $this->http(['bs-session-id' => $session])
            ->post($this->endpoint('api/events/search'), [
                'Query' => [
                    'limit' => 500,
                    'conditions' => [[
                        'column' => 'datetime', 'operator' => 4,
                        'values' => [$this->since()->toIso8601String()],
                    ]],
                ],
            ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('EventCollection.rows') ?? [])->map(fn ($e) => [
            'identifier' => (string) ($e['user_id']['user_id'] ?? ''),
            'direction' => $this->direction($e['event_type_id']['code'] ?? null),
            'occurred_at' => $e['datetime'] ?? now()->toIso8601String(),
            'device_ref' => $e['device_id']['name'] ?? null,
        ])->filter(fn ($e) => $e['identifier'] !== '')->values()->all();
    }

    private function session(): ?string
    {
        $response = $this->http()->post($this->endpoint('api/login'), [
            'User' => [
                'login_id' => $this->decrypt($this->record->api_key),
                'password' => $this->decrypt($this->record->api_secret),
            ],
        ]);

        return $response->successful() ? $response->header('bs-session-id') : null;
    }
}
