<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinPosting;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single door operational modules use to post to the ledger. Writes a
 * durable outbox row, then posts it immediately (real-time). If that inline post
 * fails, the row stays "pending"/"failed" and `processPending()` (a scheduled
 * command) retries it — so no financial event is ever lost.
 */
class FinancePoster
{
    public function __construct(private Ledger $ledger) {}

    /**
     * Record + post a financial event, once.
     *
     * ── The key this deduplicates on ────────────────────────────────────────
     *
     * The idempotency key, which is what callers have always believed. It used to be `event_id` —
     * and `FinancialEvent` mints a fresh uuid for that on every construction unless one is passed,
     * which none of the callers do. So the row it looked for could never already exist, the
     * idempotency key was written and never read, and the deduplication was decorative.
     *
     * What it did *not* cost, and it is worth being exact: no duplicate journal was ever posted.
     * `Ledger::post()` carries its own check on the same key, so the second outbox row resolved to
     * the first one's journal and the books stayed correct. The damage was one layer up — the
     * outbox accumulated a row per attempt, `emit()` returned a different posting each time, and
     * the guarantee every caller was relying on came from a class none of them call.
     *
     * That is a thinner defence than it looks. The ledger's check is the last one; with this layer
     * inert there was nothing in front of it, and any future path that builds a journal without
     * going through `Ledger::post()` would have had no protection at all.
     *
     * `firstOrCreate` still races under concurrency. The narrower fix would be a unique index on
     * the key, which is not added here because an estate that has been running with this bug may
     * already hold duplicate keys, and resolving those means deciding which posted journal to
     * reverse — a data-repair job with a person in it, not a migration.
     */
    public function emit(FinancialEvent $event): FinPosting
    {
        $posting = FinPosting::firstOrCreate(
            ['idempotency_key' => $event->idempotencyKey],
            [
                'event_id' => $event->eventId,
                'source_module' => $event->sourceModule,
                'source_ref' => $event->sourceRef,
                'payload' => $event->toArray(),
                'status' => 'pending',
            ]
        );

        $this->process($posting, $event);

        return $posting;
    }

    /** Attempt to post one outbox row; records the outcome. */
    public function process(FinPosting $posting, ?FinancialEvent $event = null): void
    {
        if ($posting->status === 'posted') {
            return;
        }

        try {
            $event ??= $this->rehydrate($posting);
            $journal = $this->ledger->post($event);

            $posting->update([
                'status' => 'posted',
                'journal_id' => $journal->id,
                'attempts' => $posting->attempts + 1,
                'error' => null,
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $posting->update([
                'status' => 'failed',
                'attempts' => $posting->attempts + 1,
                'error' => $e->getMessage(),
            ]);
            Log::warning('finance: posting failed', ['event' => $posting->event_id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Retry every pending/failed outbox row (scheduled fallback).
     *
     * The rows are *read* untenanted on purpose — an outbox drain that could only see one company's
     * backlog would leave every other company's stuck forever. Each row is then *processed* as its
     * own tenant, which is the half that was missing: the payload carries the company_id so the
     * journal lands in the right books either way, but everything the ledger resolves by lookup on
     * the way there — account by code, open period, numbering sequence — was being resolved with no
     * tenant applied, and so against the whole estate.
     *
     * A row with no company_id (a single-tenant install, or anything posted before Finance was
     * tenanted) is processed with the tenant cleared, which is what it means.
     *
     * The trading entity is the same story one level down, and the same two halves. The read lifts
     * the entity scope for the same reason it is untenanted — a drain that could only see the
     * business somebody happens to be working in would leave the others' backlogs stuck — and each
     * row is then pinned to the entity it was emitted for, so the account lookup, the open period
     * and the numbering sequence are that business's and not whichever one the tenant defaults to.
     */
    public function processPending(int $limit = 500): int
    {
        $done = 0;
        $tenants = app(TenantContext::class);

        $entities = app(CurrentEntity::class);

        FinPosting::withoutEntityScope()
            ->whereIn('status', ['pending', 'failed'])->orderBy('id')->limit($limit)->get()
            ->each(function (FinPosting $p) use (&$done, $tenants, $entities) {
                // process() already records its own failures against the row, so one poison
                // message cannot stop the drain.
                $tenants->run(
                    $p->company_id ? (int) $p->company_id : null,
                    fn () => $entities->run($p->entity_id ? (int) $p->entity_id : null, fn () => $this->process($p))
                );

                if ($p->fresh()->status === 'posted') {
                    $done++;
                }
            });

        return $done;
    }

    private function rehydrate(FinPosting $posting): FinancialEvent
    {
        $p = $posting->payload;

        return new FinancialEvent(
            sourceModule: $p['source_module'], eventType: $p['event_type'],
            debits: $p['debits'], credits: $p['credits'],
            eventDate: $p['event_date'] ?? null, companyId: $p['company_id'] ?? null,
            currency: $p['currency'] ?? 'ZAR', sourceRef: $p['source_ref'] ?? '',
            metadata: $p['metadata'] ?? [], idempotencyKey: $p['idempotency_key'], eventId: $p['event_id'],
        );
    }
}
