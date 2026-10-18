<?php

namespace App\Modules\Operations\Sheq\Access\Integrations\Adapters;

use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared behaviour for every access-control and clock-in adapter.
 *
 * A concrete adapter's whole job is to answer two questions for its vendor: can we reach it, and
 * what events have happened since we last looked. Everything after that — deciding whether the
 * person may enter, writing the decision, pushing it back to the device — is identical across
 * vendors and lives here.
 *
 * That split is what makes the brief's requirement achievable. Adding a vendor is a `fetchEvents()`
 * and a `pushDecision()`; it is never a change to the gate logic, because the gate logic is a safety
 * decision and must not vary by whose turnstile is bolted to the ground.
 */
abstract class AbstractAccessAdapter
{
    protected object $record;

    protected array $config;

    public function __construct(object $integrationRecord)
    {
        $this->record = $integrationRecord;
        $this->config = json_decode((string) ($integrationRecord->additional_config ?? '{}'), true) ?: [];
    }

    /** Can we reach the vendor with these credentials? */
    abstract public function testConnection(): array;

    /**
     * Events since the last sync, normalised.
     *
     * @return list<array{identifier:string, direction:string, occurred_at:string, device_ref:?string}>
     */
    abstract protected function fetchEvents(): array;

    /**
     * Tell the device about a decision, where the vendor supports it.
     *
     * Optional: many controllers are read-only from our side, and enrolment is done in their own
     * console. Returning false is not a failure.
     */
    protected function pushDecision(string $identifier, bool $allowed, array $reasons): bool
    {
        return false;
    }

    /**
     * Pull events, decide on each, and record.
     *
     * The decision is made here rather than in the adapter so that every vendor gets exactly the
     * same rules — including the monitor-mode default, which is the setting that stops a botched
     * rollout stranding a shift outside.
     */
    public function sync(): array
    {
        $events = $this->fetchEvents();
        $decisions = app(AccessDecisionService::class);
        $refused = 0;

        foreach ($events as $event) {
            $decision = $decisions->decideAndRecord((int) $this->record->company_id,
                $event['identifier'], [
                    'integration_id' => $this->record->id,
                    'site_id' => $this->record->site_id,
                    'direction' => $event['direction'] ?? 'ENTRY',
                    'device_ref' => $event['device_ref'] ?? null,
                    'occurred_at' => $event['occurred_at'] ?? now(),
                    'enforce' => (bool) $this->record->enforce_access,
                ]);

            if (! $decision['allowed']) {
                $refused++;
            }

            if ((bool) $this->record->enforce_access) {
                $this->pushDecision($event['identifier'], $decision['allowed'], $decision['reasons']);
            }
        }

        return ['ok' => true, 'events' => count($events), 'refused' => $refused,
            'message' => sprintf('%d event(s), %d would be refused%s.', count($events), $refused,
                $this->record->enforce_access ? '' : ' (monitor mode)')];
    }

    protected function decrypt(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return decrypt($value);
        } catch (\Throwable) {
            // Tolerates a value stored before encryption was applied, as elsewhere in the module.
            return $value;
        }
    }

    protected function endpoint(string $path = ''): string
    {
        return rtrim((string) $this->record->api_endpoint, '/').'/'.ltrim($path, '/');
    }

    /** Only events since the last successful sync — vendors page badly and repeat themselves. */
    protected function since(): Carbon
    {
        return $this->record->last_sync_at !== null
            ? Carbon::parse($this->record->last_sync_at)
            : Carbon::now()->subDay();
    }

    protected function http(array $headers = [])
    {
        return Http::timeout(20)->withHeaders($headers)->acceptJson();
    }

    protected function log(string $message, array $context = []): void
    {
        Log::info("[SHEQ access:{$this->record->provider}] {$message}",
            $context + ['integration' => $this->record->id]);
    }

    /** Normalise whatever the vendor calls a direction. */
    protected function direction(?string $raw): string
    {
        $raw = strtoupper((string) $raw);

        return match (true) {
            str_contains($raw, 'OUT'), str_contains($raw, 'EXIT') => 'EXIT',
            str_contains($raw, 'CLOCK') => 'CLOCK_IN',
            default => 'ENTRY',
        };
    }
}
