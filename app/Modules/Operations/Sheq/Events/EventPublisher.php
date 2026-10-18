<?php

namespace App\Modules\Operations\Sheq\Events;

use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Publishes SHEQ domain events through the transactional outbox (§8.2, SHEQ-ARC-1, SHEQ-INT-1).
 *
 * The outbox table has existed since Phase 0 and nothing ever wrote to it — this is what fills it.
 *
 * Two rules are enforced rather than documented:
 *
 * - **A published event must be inside the transaction that caused it.** Otherwise the two classic
 *   failures appear: an event announcing a write that rolled back, and a write that committed while
 *   its event was lost. Publishing outside a transaction throws.
 * - **The payload must satisfy the registered schema.** A consumer builds against the contract in
 *   `EventRegistry`; shipping an event missing a declared key breaks somebody else's integration
 *   silently, and a loud failure here is far cheaper.
 *
 * The envelope is SHEQ-INT-1's: `event_id`, `event_type`, `event_version`, `tenant_id`,
 * `occurred_at`, `sequence` and `correlation_id`. The sequence comes from the table's own identity
 * column, so ordering is the database's to decide rather than the application's.
 */
class EventPublisher
{
    public function __construct(private readonly EventRegistry $registry) {}

    /**
     * Record a domain event for delivery.
     *
     * @param  array<string,mixed>  $payload  everything a consumer needs without calling back
     * @param  array<string,mixed>  $metadata  actor, device, causation — never business data
     */
    /**
     * Publish only where something is actually listening (INT-5).
     *
     * For events whose volume is a function of foot traffic rather than of anything going wrong.
     * A site with two thousand people produces four thousand gate scans a day, and writing an
     * outbox row for each would duplicate `sheq_access_events` at scale for the benefit of
     * nobody — on the overwhelming majority of installations, where no gate integration exists.
     *
     * So the cost is made proportional to the integrations that exist rather than to the number
     * of people walking through a boom. One indexed existence check, and where a webhook IS
     * subscribed the event is published exactly as any other.
     *
     * Returns null where nothing was listening, which a caller can ignore. Not an error: silence
     * is the correct outcome and the common one.
     */
    public function publishIfSubscribed(
        int $companyId,
        string $eventType,
        string $subjectType,
        int $subjectId,
        array $payload,
        array $metadata = [],
    ): ?string {
        $subscribed = DB::table('sheq_webhooks')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereNull('disabled_at')
            // `event_types` is a json array of the types a subscription wants.
            ->whereJsonContains('event_types', $eventType)
            ->exists();

        if (! $subscribed) {
            return null;
        }

        return $this->publish($companyId, $eventType, $subjectType, $subjectId, $payload,
            $metadata);
    }

    public function publish(
        int $companyId,
        string $eventType,
        string $subjectType,
        int $subjectId,
        array $payload,
        array $metadata = [],
        ?Carbon $availableAt = null,
    ): string {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                "SHEQ event '{$eventType}' was published outside a transaction. It must be written "
                .'in the same transaction as the change that caused it (SHEQ-ARC-1).'
            );
        }

        $schema = $this->registry->get($eventType);
        $missing = $this->registry->missingKeys($eventType, $payload);

        if ($missing !== []) {
            throw new LogicException(sprintf(
                "Event '%s' is missing required payload keys: %s. A consumer builds against the "
                .'registered schema, so an incomplete event breaks their integration, not ours.',
                $eventType, implode(', ', $missing)
            ));
        }

        $eventId = (string) Str::uuid();

        DB::table('sheq_outbox_messages')->insert([
            // The consumer's idempotency key (SHEQ-ARC-2): at-least-once delivery is safe because
            // a repeat carries the same event_id.
            'event_id' => $eventId,
            'company_id' => $companyId,
            'event_type' => $eventType,
            'event_version' => $schema['version'],
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => json_encode($payload),
            'metadata' => json_encode($metadata + [
                // Ties an event back to the request that caused it, across modules.
                'correlation_id' => $metadata['correlation_id'] ?? (string) Str::uuid(),
                'occurred_at' => Timestamps::utc(Carbon::now())->toIso8601String(),
            ]),
            'available_at' => Timestamps::utc($availableAt ?? Carbon::now()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $eventId;
    }

    /**
     * Events waiting to go out, oldest first.
     *
     * Claimed with SKIP LOCKED so two relays can drain the same queue without either duplicating a
     * delivery or blocking on the other.
     *
     * @return list<object>
     */
    public function pending(int $companyId, int $limit = 100): array
    {
        $query = DB::table('sheq_outbox_messages')
            ->where('company_id', $companyId)
            ->whereNull('published_at')
            ->whereNull('failed_at')
            ->where('available_at', '<=', Timestamps::utc(Carbon::now()))
            ->orderBy('id')
            ->limit($limit);

        if (DB::getDriverName() === 'pgsql') {
            return $query->lock('for update skip locked')->get()->all();
        }

        return $query->get()->all();
    }

    public function markPublished(int $messageId): void
    {
        DB::table('sheq_outbox_messages')->where('id', $messageId)->update([
            'published_at' => Timestamps::utc(Carbon::now()),
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Record a failed publish.
     *
     * An exhausted message is parked with `failed_at` set rather than deleted: SHEQ-API-5 requires a
     * replayable dead letter queue, and a message that vanished cannot be replayed or explained.
     */
    public function markFailed(int $messageId, string $error, int $maxAttempts = 8): void
    {
        $attempts = (int) DB::table('sheq_outbox_messages')->where('id', $messageId)->value('attempts') + 1;

        DB::table('sheq_outbox_messages')->where('id', $messageId)->update([
            'attempts' => $attempts,
            'last_error' => Str::limit($error, 900),
            'failed_at' => $attempts >= $maxAttempts ? Timestamps::utc(Carbon::now()) : null,
            // Exponential backoff, capped: a subscriber that is down for an hour should not be
            // hammered, and one that is down for a day should not delay everyone else.
            'available_at' => Timestamps::utc(Carbon::now()->addSeconds(min(3600, 2 ** $attempts * 15))),
            'updated_at' => now(),
        ]);
    }

    /** Replay a dead letter — the operator's escape hatch (SHEQ-API-5). */
    public function replay(int $companyId, string $eventId): bool
    {
        return DB::table('sheq_outbox_messages')
            ->where('company_id', $companyId)->where('event_id', $eventId)
            ->update([
                'failed_at' => null, 'attempts' => 0, 'last_error' => null,
                'available_at' => Timestamps::utc(Carbon::now()),
                'updated_at' => now(),
            ]) > 0;
    }
}
