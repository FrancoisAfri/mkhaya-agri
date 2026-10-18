# PM-ADR-008 — Event transport

**Status:** Accepted
**Date:** 2026-08-06
**Brief's recommendation:** Transactional outbox → message bus. Webhook and derivation reliability
depends on it.

## Context

Two things must happen reliably when project state changes: derived values recompute, and external
subscribers (webhooks, the mobile sync feed, other modules) are notified. Dispatching either
directly from a request has a well-known failure: the transaction commits but the dispatch is lost
(or worse, the dispatch fires and the transaction rolls back, announcing something that never
happened).

The platform has already solved this once, in Finance: domain events are written to an outbox table
in the same transaction as the state change, and a scheduled command drains it
(`ProcessFinancePostings`, every five minutes, `withoutOverlapping`, with a parity check). PM should
not invent a second mechanism.

## Decision

PM writes domain events to a **transactional outbox** (`pm_outbox`) in the same database transaction
as the state change that produced them, and a scheduled worker drains the outbox to consumers.

- **Same transaction, always.** If the business write rolls back, the event disappears with it.
  There is no code path that publishes outside the transaction.
- **Drained by a scheduled command** with `withoutOverlapping`, following the Finance precedent.
  The command sets the company context per row before dispatching — an untenanted drain would both
  see nothing (RLS) and risk delivering across tenants, which is the recurring failure mode for
  crons in this codebase.
- **At-least-once delivery.** Consumers must be idempotent; every event carries a stable UUID for
  deduplication. Delivery attempts, last error and next retry are recorded on the row, with
  exponential backoff and a dead-letter state after a bounded number of attempts.
- **Ordering is per subject**, not global. Events for one task are delivered in order; no ordering
  is promised between unrelated subjects.
- **Evidence-driven derivation is a consumer**, not a special case: appending evidence enqueues a
  recompute for the affected subject, so derived progress and cost converge asynchronously and
  recover after an outage by replaying the outbox.
- Deferred to a later phase, but not designed out: swapping the drain's transport for a real message
  bus. Producers write to the outbox either way, so that swap does not touch business code.

## Consequences

- No lost notifications and no phantom notifications, which is what "webhook and derivation
  reliability" actually requires.
- Derived values are eventually consistent, by design. The UI must show the derivation timestamp
  rather than implying the number is live to the millisecond.
- The outbox is a queue in a table: it needs monitoring (depth, oldest undelivered, dead letters)
  and a retention policy for delivered rows, both of which ship with the drain command.
- A slow or failing consumer never blocks the user's write — it shows up as outbox depth instead.
