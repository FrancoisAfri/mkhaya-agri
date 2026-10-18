<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Models\Webhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Delivering warehouse events to subscribed endpoints (WMS-172).
 *
 * Each delivery is signed: `X-Mkhaya-Signature: sha256=HMAC(secret, timestamp + "." + body)`, with
 * the timestamp in `X-Mkhaya-Timestamp`, so a receiver can refuse a forged or replayed request. An
 * event is retried with growing back-off and, after the last attempt, kept as a dead letter until a
 * person replays it. An endpoint already sent an event is not sent it again on a retry. Endpoints
 * must be public HTTPS addresses -- the check is repeated at every delivery, so a name that later
 * resolves inside the network is refused then too.
 */
class WebhookDeliveries
{
    public const MAX_ATTEMPTS = 8;

    public function subscribe(int $companyId, array $data, User $user): array
    {
        $this->assertPublicUrl($data['url']);
        $this->assertKnownTypes($data['event_types']);
        $secret = 'whsec_'.Str::random(40);
        $hook = Webhook::create(['company_id' => $companyId, 'name' => $data['name'], 'url' => $data['url'], 'secret' => $secret,
            'secret_hint' => substr($secret, -4), 'event_types' => array_values($data['event_types']), 'warehouse_ids' => $data['warehouse_ids'] ?? null,
            'is_active' => true, 'created_by' => $user->id]);

        return [$hook, $secret];
    }

    public function change(Webhook $hook, array $data): Webhook
    {
        if (isset($data['url'])) {
            $this->assertPublicUrl($data['url']);
        }
        if (isset($data['event_types'])) {
            $this->assertKnownTypes($data['event_types']);
        }
        $hook->fill(array_intersect_key($data, array_flip(['name', 'url', 'event_types', 'warehouse_ids', 'is_active'])));
        if (($data['is_active'] ?? false) === true) {
            $hook->fill(['disabled_at' => null, 'disabled_reason' => null, 'consecutive_failures' => 0]);
        }
        $hook->save();

        return $hook;
    }

    /** A new secret, shown once; the old one stops working at once. */
    public function rotate(Webhook $hook): string
    {
        $secret = 'whsec_'.Str::random(40);
        $hook->update(['secret' => $secret, 'secret_hint' => substr($secret, -4)]);

        return $secret;
    }

    /** Send what is due. Runs across companies from the scheduler; every query names the event's company. */
    public function drain(int $limit = 200): array
    {
        $done = ['delivered' => 0, 'retrying' => 0, 'dead' => 0, 'nothing_subscribed' => 0];
        // Claim a batch with a short lease, so two schedulers never send the same event at once.
        $rows = DB::transaction(function () use ($limit) {
            $rows = DB::table('wms_outbox')->whereNull('published_at')->whereNull('failed_at')->where('available_at', '<=', now())
                ->orderBy('id')->limit($limit)->lock('FOR UPDATE SKIP LOCKED')->get();
            DB::table('wms_outbox')->whereIn('id', $rows->pluck('id'))->update(['available_at' => now()->addMinutes(5)]);

            return $rows;
        });

        foreach ($rows as $row) {
            $hooks = Webhook::withoutGlobalScopes()->where('company_id', $row->company_id)->where('is_active', true)->whereNull('disabled_at')->get()
                ->filter(fn (Webhook $h) => $h->wants($row->event_type, $row->warehouse_id ? (int) $row->warehouse_id : null));
            if ($hooks->isEmpty()) {
                DB::table('wms_outbox')->where('id', $row->id)->update(['published_at' => now()]);
                $done['nothing_subscribed']++;

                continue;
            }
            $attempt = (int) $row->attempts + 1;
            $failed = [];
            foreach ($hooks as $hook) {
                $sent = DB::table('wms_webhook_deliveries')->where('webhook_id', $hook->id)->where('outbox_id', $row->id)->where('delivered', true)
                    ->when($row->replayed_at, fn ($q, $at) => $q->where('created_at', '>=', $at))->exists();
                if (! $sent && ($error = $this->send($hook, $row, $attempt)) !== null) {
                    $failed[] = "{$hook->name}: {$error}";
                }
            }
            if ($failed === []) {
                DB::table('wms_outbox')->where('id', $row->id)->update(['published_at' => now(), 'attempts' => $attempt, 'last_error' => null]);
                $done['delivered']++;
            } elseif ($attempt >= self::MAX_ATTEMPTS) {
                DB::table('wms_outbox')->where('id', $row->id)->update(['failed_at' => now(), 'attempts' => $attempt, 'last_error' => mb_substr(implode('; ', $failed), 0, 1000)]);
                $done['dead']++;
            } else {
                DB::table('wms_outbox')->where('id', $row->id)->update(['attempts' => $attempt, 'last_error' => mb_substr(implode('; ', $failed), 0, 1000),
                    'available_at' => now()->addSeconds(min(3600, (2 ** $attempt) * 15))]);
                $done['retrying']++;
            }
        }

        return $done;
    }

    /** Send an event again: a dead letter, or one a receiver lost. */
    public function replay(string $eventId): void
    {
        $n = DB::table('wms_outbox')->where('event_id', $eventId)
            ->update(['published_at' => null, 'failed_at' => null, 'attempts' => 0, 'last_error' => null, 'available_at' => now(), 'replayed_at' => now()]);
        if ($n === 0) {
            throw new RuntimeException('No such event.');
        }
    }

    /** @return string|null the error, or null when delivered */
    private function send(Webhook $hook, object $row, int $attempt): ?string
    {
        $body = json_encode(WarehouseEvents::envelope($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = (string) now()->timestamp;
        $started = microtime(true);
        $status = null;
        $error = null;
        try {
            $this->assertPublicUrl($hook->url);
            $response = Http::timeout(10)->withHeaders([
                'Content-Type' => 'application/json', 'User-Agent' => 'MkhayaWarehouse/1',
                'X-Mkhaya-Event' => $row->event_type, 'X-Mkhaya-Event-Id' => $row->event_id, 'X-Mkhaya-Schema-Version' => (string) $row->schema_version,
                'X-Mkhaya-Timestamp' => $ts, 'X-Mkhaya-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, (string) $hook->secret),
            ])->withBody($body, 'application/json')->withoutRedirecting()->post($hook->url);
            $status = $response->status();
            if (! $response->successful()) {
                $error = "HTTP {$status}";
            }
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 300);
        }
        DB::table('wms_webhook_deliveries')->insert(['company_id' => $hook->company_id, 'webhook_id' => $hook->id, 'outbox_id' => $row->id, 'attempt' => $attempt,
            'response_status' => $status, 'error' => $error, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'delivered' => $error === null, 'created_at' => now()]);

        if ($error === null) {
            $hook->forceFill(['consecutive_failures' => 0, 'last_delivered_at' => now()])->save();
        } else {
            $hook->forceFill(['consecutive_failures' => $hook->consecutive_failures + 1])->save();
            if ($hook->consecutive_failures >= Webhook::DISABLE_AFTER) {
                $hook->forceFill(['disabled_at' => now(), 'disabled_reason' => "Switched off after {$hook->consecutive_failures} failed deliveries in a row. Last: {$error}"])->save();
            }
        }

        return $error;
    }

    public function assertPublicUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (($parts['scheme'] ?? null) !== 'https' || ! $host) {
            throw new RuntimeException('A webhook must be an https:// address.');
        }
        // Only the plain forms: a host written oddly (encoded, decimal or octal numbers, credentials,
        // trailing dots) can be read one way here and another by the HTTP client.
        if (isset($parts['user']) || isset($parts['pass']) || ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host)
            || (preg_match('/^[0-9.]+$/', $host) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            || preg_match('/(^|\.)0x/i', $host) || (preg_match('/\.[0-9]+$/', $host) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new RuntimeException('Use a plain https address on the standard port, without credentials.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new RuntimeException("{$host} does not resolve.");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException("{$host} points inside a private network; webhooks go to public addresses only.");
            }
        }
    }

    private function assertKnownTypes(array $types): void
    {
        $unknown = array_diff($types, array_keys(WarehouseEvents::TYPES));
        if ($types === [] || $unknown !== []) {
            throw new RuntimeException($types === [] ? 'Choose at least one event.' : 'Unknown event: '.implode(', ', $unknown).'.');
        }
    }
}
