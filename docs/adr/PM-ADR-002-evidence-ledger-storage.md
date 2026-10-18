# PM-ADR-002 — Evidence Ledger storage

**Status:** Accepted
**Date:** 2026-08-06
**Brief's recommendation:** Append-only table, monthly partitions, hash-chained.

## Context

The Evidence Ledger is the module's foundation: every observation about the world (work done,
material installed, hours booked, a photo from site) is an immutable event, and all progress and
cost figures are *derived* from those events rather than typed in. This is what makes drill-down
("why does this task say 62%?") possible, and it cannot be bolted on later without rebuilding all
derived state.

Three properties must be true from the first row written:

1. **Append-only** — an event is never updated or deleted; a mistake is corrected by writing a new
   event that supersedes the old one.
2. **Partitioned** — the ledger is the highest-volume table in the module and is almost always
   queried by time window.
3. **Hash-chained** — each event stores the hash of its predecessor, so tampering is detectable.

## Decision

Adopt the brief in full, with the mechanics pinned down:

**Append-only** is enforced by a `BEFORE UPDATE OR DELETE` trigger on the parent table that raises
an exception. The single exception is setting `superseded_by` on a row where it was previously NULL:
that is the correction mechanism, and the trigger allows exactly that column to change (all other
columns must be identical, and it can never be un-set or re-pointed). Deletes always raise, for
every role, including the migration user. Retention is handled by detaching whole partitions, never
by `DELETE`.

**Partitioning** is PostgreSQL native `RANGE (occurred_at)`, one partition per calendar month, plus
a catch-all `DEFAULT` partition so a late or far-future event can never fail to insert. A scheduled
command creates the next months' partitions ahead of time. Because PostgreSQL requires the partition
key in every unique constraint, the primary key is composite `(id, occurred_at)` and the public UUID
is unique per `(uuid, occurred_at)` with a plain index on `uuid` for lookup. UUIDs are generated
client- or server-side as v7 and are unique in practice; the database-level guarantee is per
partition.

**Hash chain** is per `(company_id, project_id)`: each event hashes its own canonical field set plus
the previous event's hash for that project. A project's chain can therefore be verified in isolation
and one tenant's activity never interleaves into another's chain. The canonical payload, hash
algorithm (SHA-256) and field order are versioned in the ledger service so a future change to what
gets hashed does not invalidate old rows.

Writes go through `EvidenceLedger::append()` — never a direct `insert()`. The service is responsible
for resolving `prev_hash` under a row lock, so two concurrent appends to the same project cannot
produce a forked chain.

## Consequences

- Corrections are visible history, not overwrites: a superseded event stays in the ledger with a
  pointer to its replacement, and derived values recompute from the non-superseded set.
- The trigger makes append-only a property of the *database*, so a future bug, console command or
  raw SQL script cannot quietly rewrite history.
- Verification cost is linear in a project's event count; `verifyChain()` is therefore an on-demand
  and scheduled audit operation, not something on the request path.
- Very old evidence is archived by detaching a partition, which is instant and does not touch the
  chain of live projects.
- Because the PK is composite, foreign keys *into* the ledger are not used; other tables reference
  evidence by UUID array (`pm_derived_values.evidence_ids`) rather than by FK. This is deliberate —
  it also keeps the ledger detachable.
