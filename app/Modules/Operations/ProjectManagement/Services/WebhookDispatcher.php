<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\OutboxMessage;
use App\Modules\Operations\ProjectManagement\Models\Webhook;
use App\Modules\Operations\ProjectManagement\Models\WebhookDelivery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Delivers outbox messages to a tenant's webhooks (EPIC-070).
 *
 * Signed, so a receiver can tell our payload from anyone else's; timestamped inside the signature,
 * so a captured request cannot be replayed against them a week later; and backed off, so a consumer
 * having a bad afternoon is not hammered into a worse one.
 *
 * A subscription that fails twenty times in a row is switched off with a reason recorded. Retrying
 * a dead endpoint forever costs the tenant nothing and costs us a worker, and the silence is how
 * nobody notices for a month.
 */
class WebhookDispatcher
{
    /** A slow consumer must not hold a worker. Their problem, not ours. */
    private const TIMEOUT_SECONDS = 8;

    /** Beyond this the receiver is being replayed an old request; the window is generous for clock skew. */
    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    /**
     * Register a subscription and return the secret — once.
     *
     * @return array{webhook: Webhook, secret: string}
     */
    public function subscribe(string $name, string $url, array $eventTypes = []): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            // Plain HTTP would put the payload — and the signature — on the wire in clear.
            throw new InvalidArgumentException('A webhook URL has to be https.');
        }

        $secret = 'whsec_' . Str::random(40);

        $webhook = Webhook::create([
            'name'        => $name,
            'url'         => $url,
            'secret'      => $secret,
            'secret_hint' => substr($secret, -4),
            'event_types' => array_values($eventTypes),
            'is_active'   => true,
            'created_by'  => Auth::id(),
        ]);

        // Returned here so the tenant can store it. The application never shows it again — not in
        // an edit form, not behind a "reveal" button — because a secret a dashboard can redisplay
        // ends up in screenshots and support tickets.
        return ['webhook' => $webhook, 'secret' => $secret];
    }

    /**
     * Deliver one outbox message to every subscription that wants it.
     *
     * @return array{sent: int, failed: int}
     */
    public function deliver(OutboxMessage $message): array
    {
        $sent = 0;
        $failed = 0;

        foreach (Webhook::active()->get() as $webhook) {
            if (! $webhook->wants($message->event_type)) {
                continue;
            }

            $this->send($webhook, $message) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * The signature a receiver checks.
     *
     * The timestamp is inside the signed string, not merely beside it, so it cannot be altered
     * without invalidating the signature — which is what makes the replay window enforceable.
     */
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function send(Webhook $webhook, OutboxMessage $message): bool
    {
        $body = json_encode([
            'id'         => $message->uuid,
            'event'      => $message->event_type,
            'subject'    => ['type' => $message->subject_type, 'id' => $message->subject_uuid],
            'occurred_at' => $message->created_at?->toIso8601String(),
            'data'       => $message->payload,
        ], JSON_UNESCAPED_SLASHES);

        $timestamp = now()->getTimestamp();
        $started = microtime(true);

        $delivery = [
            'webhook_id' => $webhook->id,
            'outbox_id'  => $message->id,
            'event_type' => $message->event_type,
            'attempt'    => $message->attempts + 1,
        ];

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'Content-Type'       => 'application/json',
                    'X-Mkhaya-Event'     => $message->event_type,
                    'X-Mkhaya-Delivery'  => $message->uuid,
                    'X-Mkhaya-Timestamp' => (string) $timestamp,
                    'X-Mkhaya-Signature' => self::signature($webhook->secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);

            $ok = $response->successful();

            WebhookDelivery::create($delivery + [
                'status_code'  => $response->status(),
                'duration_ms'  => (int) round((microtime(true) - $started) * 1000),
                'delivered_at' => $ok ? now() : null,
                'error'        => $ok ? null : Str::limit($response->body(), 480),
            ]);

            $ok ? $this->recordSuccess($webhook) : $this->recordFailure($webhook);

            return $ok;
        } catch (\Throwable $e) {
            // A refused connection or a DNS failure is a failed delivery, not a failed drain: the
            // worker has other messages to get through.
            WebhookDelivery::create($delivery + [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error'       => Str::limit($e->getMessage(), 480),
            ]);

            $this->recordFailure($webhook);

            return false;
        }
    }

    private function recordSuccess(Webhook $webhook): void
    {
        $webhook->forceFill([
            'consecutive_failures' => 0,
            'last_delivered_at'    => now(),
        ])->save();
    }

    private function recordFailure(Webhook $webhook): void
    {
        $failures = $webhook->consecutive_failures + 1;

        $webhook->forceFill([
            'consecutive_failures' => $failures,
            'is_active'            => $failures < Webhook::FAILURE_LIMIT,
            'disabled_at'          => $failures >= Webhook::FAILURE_LIMIT ? now() : null,
            'disabled_reason'      => $failures >= Webhook::FAILURE_LIMIT
                ? Webhook::FAILURE_LIMIT . ' deliveries in a row failed. Fix the endpoint and switch this back on.'
                : null,
        ])->save();
    }
}
