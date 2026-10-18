<?php

namespace App\Modules\Operations\Sheq\Events;

use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers outbox events to tenant-registered webhooks (SHEQ-API-5).
 *
 * Every request is signed with HMAC-SHA256 over the **raw body**, not over a re-encoded copy: a
 * subscriber verifies the bytes they received, and re-serialising JSON changes key order and
 * whitespace, so a signature computed over a re-encode fails for no reason anyone can debug.
 *
 * A subscriber that keeps failing is disabled rather than retried forever. An endpoint that has been
 * dead for a week is not coming back this minute, and a queue that spends its life retrying it
 * delays every healthy subscriber behind it.
 */
class WebhookDispatcher
{
    /** Consecutive failures before a subscription is switched off and the tenant told. */
    private const FAILURE_LIMIT = 20;

    /**
     * Deliver one outbox message to every subscription that wants it.
     *
     * @return array{delivered:int, failed:int, skipped:int}
     */
    public function dispatch(int $companyId, object $message): array
    {
        $subscriptions = DB::table('sheq_webhooks')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get();

        $delivered = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($subscriptions as $subscription) {
            if (! $this->wants($subscription, $message->event_type)) {
                $skipped++;

                continue;
            }

            $this->deliver($companyId, $subscription, $message) ? $delivered++ : $failed++;
        }

        return ['delivered' => $delivered, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** A subscription with no event list wants everything; otherwise only what it named. */
    private function wants(object $subscription, string $eventType): bool
    {
        $types = json_decode((string) $subscription->event_types, true);

        return ! is_array($types) || $types === [] || in_array($eventType, $types, true);
    }

    private function deliver(int $companyId, object $subscription, object $message): bool
    {
        // Built once and signed as-is. The subscriber verifies these exact bytes.
        $body = json_encode([
            'event_id' => $message->event_id,
            'event_type' => $message->event_type,
            'event_version' => (int) $message->event_version,
            'tenant_id' => $companyId,
            'occurred_at' => $message->created_at,
            'sequence' => (int) $message->id,
            'correlation_id' => json_decode((string) $message->metadata, true)['correlation_id'] ?? null,
            'subject' => ['type' => $message->subject_type, 'id' => (int) $message->subject_id],
            'data' => json_decode((string) $message->payload, true),
        ], JSON_UNESCAPED_SLASHES);

        $timestamp = (string) Carbon::now()->getTimestamp();
        // The timestamp is inside the signed material, so a captured request cannot be replayed
        // later against a subscriber that checks freshness.
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $subscription->secret);

        $started = microtime(true);
        $status = null;
        $responseBody = null;
        $error = null;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Sheq-Event' => $message->event_type,
                'X-Sheq-Event-Id' => $message->event_id,
                'X-Sheq-Timestamp' => $timestamp,
                'X-Sheq-Signature' => 'sha256='.$signature,
            ])->timeout(10)->withBody($body, 'application/json')->post($subscription->url);

            $status = $response->status();
            $responseBody = Str::limit($response->body(), 500);
        } catch (Throwable $e) {
            $error = Str::limit($e->getMessage(), 500);
        }

        $ok = $status !== null && $status >= 200 && $status < 300;

        DB::table('sheq_webhook_deliveries')->insert([
            'company_id' => $companyId,
            'webhook_id' => $subscription->id,
            'event_id' => $message->event_id,
            'event_type' => $message->event_type,
            'attempt' => (int) $message->attempts + 1,
            'response_status' => $status,
            'response_body' => $responseBody,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'error' => $error,
            'delivered_at' => Timestamps::utc(Carbon::now()),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($ok) {
            DB::table('sheq_webhooks')->where('id', $subscription->id)->update([
                'consecutive_failures' => 0,
                'last_delivered_at' => Timestamps::utc(Carbon::now()),
                'updated_at' => now(),
            ]);

            return true;
        }

        $failures = (int) $subscription->consecutive_failures + 1;

        DB::table('sheq_webhooks')->where('id', $subscription->id)->update([
            'consecutive_failures' => $failures,
            // Switched off, with the reason recorded, so the tenant can see why it stopped rather
            // than discovering months later that their integration went quiet.
            'is_active' => $failures < self::FAILURE_LIMIT,
            'disabled_at' => $failures >= self::FAILURE_LIMIT ? Timestamps::utc(Carbon::now()) : null,
            'disabled_reason' => $failures >= self::FAILURE_LIMIT
                ? "Disabled after {$failures} consecutive failures. Last error: "
                    .($error ?? "HTTP {$status}")
                : null,
            'updated_at' => now(),
        ]);

        return false;
    }
}
