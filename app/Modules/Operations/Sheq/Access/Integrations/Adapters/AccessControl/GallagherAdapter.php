<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters\AccessControl;

use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;

/**
 * Gallagher Command Centre.
 *
 * Found on mines and high-security sites. Unusually among these vendors it supports writing back, so
 * this adapter can actually revoke a cardholder's access group when SHEQ refuses them — the strongest
 * form of enforcement available, because it holds even if our integration later goes offline.
 */
class GallagherAdapter extends AbstractAccessAdapter
{
    public function testConnection(): array
    {
        $response = $this->http([
            'Authorization' => 'GGL-API-KEY '.$this->decrypt($this->record->api_key),
        ])->get($this->endpoint('api/'));

        return ['ok' => $response->successful(),
            'message' => $response->successful()
                ? 'Connected to Command Centre.'
                : "Command Centre returned {$response->status()}."];
    }

    protected function fetchEvents(): array
    {
        $response = $this->http([
            'Authorization' => 'GGL-API-KEY '.$this->decrypt($this->record->api_key),
        ])->get($this->endpoint('api/events'), [
            'after' => $this->since()->toIso8601String(),
            'top' => 500,
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('events') ?? [])->map(fn ($e) => [
            'identifier' => (string) ($e['cardholder']['personalDataDefinitions'][0]['value']
                ?? $e['cardholder']['id'] ?? ''),
            'direction' => $this->direction($e['type']['name'] ?? null),
            'occurred_at' => $e['time'] ?? now()->toIso8601String(),
            'device_ref' => $e['source']['name'] ?? null,
        ])->filter(fn ($e) => $e['identifier'] !== '')->values()->all();
    }

    /**
     * Remove or restore the SHEQ access group on the cardholder.
     *
     * Enforcement that survives us: once the group is off, the person stays out even if this
     * integration stops running, which is the opposite of a gate that fails open when the
     * middleware dies.
     */
    protected function pushDecision(string $identifier, bool $allowed, array $reasons): bool
    {
        $group = $this->config['access_group'] ?? null;

        if ($group === null) {
            return false;
        }

        $response = $this->http([
            'Authorization' => 'GGL-API-KEY '.$this->decrypt($this->record->api_key),
        ])->patch($this->endpoint("api/cardholders/{$identifier}"), [
            'accessGroups' => $allowed
                ? ['add' => [['accessGroup' => ['href' => $group]]]]
                : ['remove' => [['accessGroup' => ['href' => $group]]]],
        ]);

        return $response->successful();
    }
}
