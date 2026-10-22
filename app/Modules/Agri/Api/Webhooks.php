<?php

namespace App\Modules\Agri\Api;

use App\Core\User\Models\User;
use App\Modules\Agri\Models;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\WebhookSubscription;
use App\Modules\Agri\Services\Integrations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Farm events to outside systems.
 *
 * When a harvest is recorded, a lot leaves, an alert is raised (and the others below), every
 * active subscription of that organisation that asked for the event gets one delivery: an
 * integration message ("webhook") keyed on subscription, event and record, so the same thing is
 * never delivered twice, and a failed delivery is retried with back-off by agri:integrations-retry
 * (five attempts, then it waits for an administrator). Deliveries leave only after the change that
 * caused them has committed.
 *
 * Each delivery is signed: `X-Mkhaya-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>"
 * with the subscription's secret>`. A receiver recomputes it, compares in constant time, and
 * rejects a timestamp older than a few minutes.
 */
class Webhooks
{
    /** event => [what it means, the resource its data is, the model] */
    public const EVENTS = [
        'harvest.recorded' => ['A harvest was recorded on a field.', 'harvests', Models\HarvestRecord::class],
        'lot.dispatched' => ['A produce lot left the farm (sold, dispatched or delivered).', 'lots', Models\ProduceLot::class],
        'alert.raised' => ['A farm alert was raised (low stock, an expiring permit, a weather warning, …).', null, Models\FarmAlert::class],
        'activity.completed' => ['A field activity was completed.', 'field-activities', Models\FieldActivity::class],
        'work_order.status_changed' => ['A work order moved to another status.', 'work-orders', Models\FarmWorkOrder::class],
        'grain_ticket.completed' => ['A grain load ticket was completed (weighed and settled).', 'grain-tickets', Models\GrainTicket::class],
    ];

    /** Listen to the records themselves, whichever screen, service, device or import changed them. */
    public static function listen(): void
    {
        Models\HarvestRecord::created(fn ($m) => self::emit('harvest.recorded', $m));
        Models\FarmAlert::created(fn ($m) => self::emit('alert.raised', $m));
        Models\ProduceLot::saved(function ($m) {
            if ($m->status === 'dispatched' && ($m->wasRecentlyCreated || $m->wasChanged('status'))) {
                self::emit('lot.dispatched', $m);
            }
        });
        Models\FieldActivity::updated(fn ($m) => $m->wasChanged('status') && $m->status === Models\FieldActivity::COMPLETED ? self::emit('activity.completed', $m) : null);
        Models\FarmWorkOrder::updated(fn ($m) => $m->wasChanged('status') ? self::emit('work_order.status_changed', $m, $m->status.'@'.$m->getRawOriginal('updated_at')) : null);
        Models\GrainTicket::saved(fn ($m) => $m->status === 'complete' && ($m->wasRecentlyCreated || $m->wasChanged('status')) ? self::emit('grain_ticket.completed', $m) : null);
    }

    public static function emit(string $event, Model $m, ?string $discriminator = null): void
    {
        $companyId = (int) ($m->company_id ?? 0);
        if (! $companyId || ! self::anyFor($companyId)) {
            return;
        }
        $key = $m->uuid.($discriminator ? ':'.$discriminator : '');
        DB::afterCommit(fn () => app(self::class)->dispatch($companyId, $event, $m->fresh() ?? $m, $key));
    }

    /** Whether the organisation has any active subscription: most have none, and every harvest should not cost a lookup more. */
    private static function anyFor(int $companyId): bool
    {
        static $memo = [];
        if (app()->runningUnitTests()) {
            $memo = [];
        }

        return $memo[$companyId] ??= WebhookSubscription::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->exists();
    }

    public function dispatch(int $companyId, string $event, Model $m, string $key): int
    {
        $n = 0;
        $subs = WebhookSubscription::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($s) => $s->wants($event));
        foreach ($subs as $s) {
            if (! $this->reaches($s, (int) ($m->farm_id ?? 0))) {
                continue;
            }
            $payload = ['subscription_id' => $s->id, 'event' => $event, 'body' => $this->envelope($event, $m, $key)];
            app(Integrations::class)->receive($companyId, 'webhook', mb_strimwidth("wh:{$s->id}:{$event}:{$key}", 0, 160), $payload, null, $m->farm_id ?? null);
            $n++;
        }

        return $n;
    }

    /** The integration handler: one signed POST. A non-2xx answer or no answer throws, so the log retries it. */
    public function deliver(array $p, IntegrationEvent $e): array
    {
        $s = WebhookSubscription::withoutGlobalScopes()->where('company_id', $e->company_id)->find($p['subscription_id'] ?? 0);
        if (! $s || ! $s->is_active) {
            return ['skipped' => 'The subscription was removed or switched off.'];
        }
        $body = json_encode($p['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t = now()->timestamp;
        $status = null;
        $error = null;
        try {
            $r = Http::timeout((int) config('agri.api.webhook_timeout', 5))->withHeaders(self::headers($s->secret(), $body, $t, (string) ($p['event'] ?? ''), $e->uuid))
                ->withBody($body, 'application/json')->post($s->url);
            $status = $r->status();
            if (! $r->successful()) {
                $error = "The receiver answered {$status}.";
            }
        } catch (\Throwable $x) {
            $error = 'No answer: '.mb_strimwidth($x->getMessage(), 0, 200);
        }
        $s->forceFill(['last_delivery_at' => now(), 'last_status' => $status, 'deliveries' => $s->deliveries + 1,
            'failures' => $error ? $s->failures + 1 : $s->failures, 'last_error' => $error])->saveQuietly();
        if ($error) {
            throw new RuntimeException($error);
        }

        return ['status' => $status, 'url' => $s->url];
    }

    /** @return array<string, string> */
    public static function headers(string $secret, string $body, int $t, string $event, string $delivery): array
    {
        return ['X-Mkhaya-Event' => $event, 'X-Mkhaya-Delivery' => $delivery, 'X-Mkhaya-Signature' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret),
            'User-Agent' => 'Mkhaya-Agri-Webhooks/1'];
    }

    public static function verify(string $secret, string $body, string $header, int $tolerance = 300): bool
    {
        parse_str(str_replace(',', '&', $header), $parts);
        $t = (int) ($parts['t'] ?? 0);

        return $t > 0 && abs(time() - $t) <= $tolerance && hash_equals(hash_hmac('sha256', $t.'.'.$body, $secret), (string) ($parts['v1'] ?? ''));
    }

    /**
     * @param  array{name: string, url: string, events: list<string>}  $data
     * @return array{subscription: WebhookSubscription, secret: string}
     */
    public function subscribe(int $companyId, array $data, ?User $actor, ?int $clientId = null): array
    {
        $events = array_values(array_unique($data['events'] ?? []));
        if (! $events || array_diff($events, [...array_keys(self::EVENTS), '*'])) {
            throw ValidationException::withMessages(['events' => 'Choose from: '.implode(', ', array_keys(self::EVENTS)).'.']);
        }
        $this->assertSafeUrl((string) ($data['url'] ?? ''));
        $secret = 'whsec_'.Str::random(40);
        $s = new WebhookSubscription(['company_id' => $companyId, 'client_id' => $clientId, 'name' => mb_strimwidth((string) $data['name'], 0, 120),
            'url' => $data['url'], 'events' => $events, 'is_active' => true, 'created_by' => $actor?->id]);
        $s->putSecret($secret);
        $s->save();

        return ['subscription' => $s, 'secret' => $secret];
    }

    /** A ping, so a receiver can be checked before a real event. Delivered like any other. */
    public function test(WebhookSubscription $s): IntegrationEvent
    {
        $body = ['id' => (string) Str::uuid7(), 'type' => 'ping', 'created_at' => now()->utc()->toIso8601ZuluString(), 'data' => ['message' => 'Mkhaya Agri webhook test']];

        return app(Integrations::class)->receive((int) $s->company_id, 'webhook', 'wh:'.$s->id.':ping:'.now()->format('YmdHis').Str::random(4),
            ['subscription_id' => $s->id, 'event' => 'ping', 'body' => $body])['event'];
    }

    public function row(WebhookSubscription $s): array
    {
        return ['id' => $s->uuid, 'name' => $s->name, 'url' => $s->url, 'events' => $s->events, 'active' => (bool) $s->is_active,
            'deliveries' => (int) $s->deliveries, 'failures' => (int) $s->failures, 'last_status' => $s->last_status, 'last_error' => $s->last_error,
            'last_delivery_at' => $s->last_delivery_at?->utc()->toIso8601ZuluString(), 'created_at' => ApiResources::value($s->getRawOriginal('created_at'), 'datetime')];
    }

    private function envelope(string $event, Model $m, string $key): array
    {
        $resource = self::EVENTS[$event][1] ?? null;
        $data = $resource ? app(ApiResources::class)->rows($resource, collect([$m]))[0]
            : ['id' => $m->uuid, 'farm' => DB::table('farms')->where('id', $m->farm_id)->value('uuid'), 'kind' => $m->kind ?? null, 'severity' => $m->severity ?? null,
                'title' => $m->title ?? null, 'message' => $m->message ?? null, 'due_on' => ApiResources::value($m->getRawOriginal('due_on'), 'date'),
                'created_at' => ApiResources::value($m->getRawOriginal('created_at'), 'datetime')];

        return ['id' => hash('sha256', $event.':'.$key), 'type' => $event, 'created_at' => now()->utc()->toIso8601ZuluString(), 'data' => $data];
    }

    /** A subscription made by a token limited to some farms hears only those farms. */
    private function reaches(WebhookSubscription $s, int $farmId): bool
    {
        if (! $s->client_id) {
            return true;
        }
        $farms = Models\ApiClient::withoutGlobalScopes()->find($s->client_id)?->farm_ids;

        return $farms === null || in_array($farmId, array_map('intval', $farms), true);
    }

    /** Only https (http on a developer's machine), and never an address inside our own network. */
    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $local = app()->environment('local', 'testing');
        if (! $host || ! ($scheme === 'https' || ($local && $scheme === 'http'))) {
            throw ValidationException::withMessages(['url' => 'The URL must be https://…']);
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($local ? [] : (@gethostbynamel($host) ?: []));
        foreach ($ips as $ip) {
            if (! $local && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ValidationException::withMessages(['url' => 'That address is inside a private network.']);
            }
        }
        if (! $local && in_array($host, ['localhost', 'metadata.google.internal'], true)) {
            throw ValidationException::withMessages(['url' => 'That address is inside a private network.']);
        }
    }
}
