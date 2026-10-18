# PM-ADR-003 — ID strategy

**Status:** Accepted
**Date:** 2026-08-06
**Brief's recommendation:** UUIDv7 (time-sortable) for all public IDs — sequential integers leak
tenant volume and break offline ID generation.

## Context

Both of the brief's objections to sequential integers are correct and both must be satisfied:

- **Enumeration / volume leakage.** A URL containing `/projects/1487` tells the customer how many
  projects exist and invites ID-guessing across tenants.
- **Offline generation.** A field device with no connectivity must be able to create a task and its
  evidence, and know their IDs immediately (see [PM-ADR-004](PM-ADR-004-offline-id-generation.md)).
  A server-assigned sequence cannot do this.

The platform's established answer to exactly this problem is the Tender Pursuit pattern: a `bigint`
primary key for internal use, plus an indexed `uuid` column that is the only ID ever exposed. Routes
bind on `uuid`, API payloads present `uuid` as `id`, and internal foreign keys stay `bigint`.

Making UUIDs the primary key instead would mean PM's FKs cannot reference — and cannot be referenced
by — the rest of the platform's `bigint` keys without a mapping layer, and would widen every index in
the module's highest-volume tables.

## Decision

Every PM table has:

- `id` — `bigint` primary key, internal only. Never appears in a URL, an API payload, or an export.
- `uuid` — **UUIDv7**, unique, indexed. The public identifier for routing, API responses, offline
  creation, and all cross-system references.

Route model binding uses `uuid`. API resources serialise `uuid` under the name `id` and never emit
the bigint. Foreign keys between PM tables use `bigint`; foreign references from *outside* PM (or
from a client that only knows public IDs) use `uuid`.

UUIDv7 specifically, not v4: the timestamp prefix keeps index locality on insert-heavy tables
(evidence events, timesheet entries) and makes IDs coarsely sortable by creation time, which matters
when reconciling an offline batch.

## Consequences

- URLs and payloads leak nothing about volume, and ID-guessing across tenants fails at both the
  route (UUID unknown) and the database (RLS) — two independent barriers.
- PM joins to HR, Finance and Procurement on native `bigint` keys, with no translation table.
- Two identifiers per row is a real cost: every developer must know that `id` is private and `uuid`
  is public. The models enforce it via `getRouteKeyName()` and the API resources, so the failure mode
  is a compile/route error rather than a silent leak.
- A client may supply the `uuid` on create; it may never supply `id`. Mass-assignment guards exclude
  `id` on every PM model.
