<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters\AccessControl;

use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;

/**
 * ZKTeco (BioTime / ZKBio).
 *
 * The most common biometric reader on smaller South African sites, largely on price. Its API is
 * token-based and its paging is unreliable, so events are pulled by timestamp window rather than by
 * cursor — a repeated event is harmless here because the decision is idempotent, whereas a missed
 * one is somebody walking through unchecked.
 */
class ZktecoAdapter extends AbstractAccessAdapter
{
    public function testConnection(): array
    {
        $token = $this->token();

        return ['ok' => $token !== null,
            'message' => $token !== null
                ? 'Connected to ZKTeco.'
                : 'ZKTeco rejected the credentials.'];
    }

    protected function fetchEvents(): array
    {
        $token = $this->token();

        if ($token === null) {
            return [];
        }

        $response = $this->http(['Authorization' => "JWT {$token}"])
            ->get($this->endpoint('iclock/api/transactions/'), [
                'start_time' => $this->since()->format('Y-m-d H:i:s'),
                'page_size' => 500,
            ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('data') ?? [])->map(fn ($t) => [
            'identifier' => (string) ($t['emp_code'] ?? ''),
            'direction' => $this->direction($t['punch_state_display'] ?? null),
            'occurred_at' => $t['punch_time'] ?? now()->toIso8601String(),
            'device_ref' => $t['terminal_sn'] ?? null,
        ])->filter(fn ($e) => $e['identifier'] !== '')->values()->all();
    }

    /** ZKTeco issues a short-lived JWT rather than accepting a static key. */
    private function token(): ?string
    {
        $response = $this->http()->post($this->endpoint('jwt-api-token-auth/'), [
            'username' => $this->decrypt($this->record->api_key),
            'password' => $this->decrypt($this->record->api_secret),
        ]);

        return $response->successful() ? $response->json('token') : null;
    }
}
