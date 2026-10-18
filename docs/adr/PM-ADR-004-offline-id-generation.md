# PM-ADR-004 — Offline ID generation

**Status:** Accepted
**Date:** 2026-08-06
**Brief's recommendation:** Client-generated UUIDv7, server accepts client IDs — otherwise every
offline record needs remapping on sync.

## Context

The field app must work with no signal for a full shift: create tasks, log progress, attach photos,
and reference those records from other records created in the same offline session (an evidence
event refers to a task that itself only exists on the device). If IDs were assigned on sync, the
device would hold temporary IDs and the server would have to rewrite every intra-batch reference —
the classic remapping problem, and the source of most duplicate-record bugs in offline systems.

## Decision

**The client generates the UUIDv7 and the server stores it verbatim.** The server assigns the
internal `bigint` (see [PM-ADR-003](PM-ADR-003-id-strategy.md)); it never assigns, rewrites or
rejects a well-formed client UUID.

Rules that make this safe:

1. **Validation, not replacement.** A supplied `uuid` must be a syntactically valid UUID. If it is
   malformed the request is rejected (422) — it is never silently replaced, because a silent
   replacement is exactly the remapping the decision exists to avoid.
2. **Idempotency.** Every mutation accepts an `Idempotency-Key` header, and it is *required* for
   sync endpoints. Re-submitting the same key returns the original result rather than creating a
   second row. A retried sync after a flaky connection is therefore a no-op, not a duplicate.
3. **Uniqueness is a conflict, not a crash.** A collision on `uuid` within a tenant returns 409 with
   the existing resource, so a device that partially synced can reconcile without operator help.
4. **Cross-tenant reuse is impossible.** UUID uniqueness is scoped per table; RLS means a device
   authenticated to tenant A can never observe or overwrite tenant B's row even on a UUID collision.
5. **Occurred-at is the client's, recorded-at is the server's.** Offline records carry the time the
   work actually happened (with the originating timezone); the server separately records when it
   received them. Neither is inferred from the other.

## Consequences

- An offline batch is a plain list of already-identified records: references inside the batch are
  already correct when they arrive, so sync is an insert, not a graph rewrite.
- Sync can be retried arbitrarily without duplicates, which is what makes an unreliable-network
  client tolerable.
- A hostile client could choose its own UUIDs. That is acceptable: UUIDs are not a security boundary
  here — RLS and RBAC are — and a client can only ever write into its own tenant.
- Clock skew on the device affects `occurred_at` and the v7 timestamp prefix, not correctness. The
  server's `recorded_at` is authoritative for audit ordering.
