# SHEQ-ADR-004 — Offline capture and the mobile target

**Status:** Accepted · **Date:** 2026-08-07
**Requirements:** §6 (SHEQ-OFF-1…8), SHEQ-INC-2, SHEQ-INS-6, SHEQ-PTW-7, principle P1

## Context

§2.2 assumes "a single codebase (Flutter or React Native) with SQLite + a sync engine". §6 then
specifies a hard offline contract: local operation log, client-generated idempotency keys, delta
sync by cursor, field-level conflict resolution, server-side re-validation of transitions, and
media uploading separately from records.

The platform has no Flutter or React Native codebase. Mkhaya Hub is an Inertia/Vue PWA which already
ships offline machinery — `useOfflineQueue`, `OfflineBanner`, `OfflineGuard`, per-tenant offline
policies, and a queue that drains on reconnect. `mkhaya-move` is a separate repository, also
Inertia/Vue.

Introducing a third codebase in a native framework would mean a second auth stack, a second
permission implementation, a second i18n catalogue in 12 languages, a second release pipeline, and
a second place for every future module to be re-implemented.

## Decision

Offline SHEQ is delivered on the existing PWA, and §6's contract is implemented as **platform**
offline infrastructure rather than SHEQ-private code:

1. **Operation log with idempotency** (SHEQ-OFF-2) — every mutation is recorded locally with a
   client-generated UUIDv7 `client_id`, sent as the server idempotency key. Replay returns the
   original result rather than creating a duplicate.
2. **Delta sync** (SHEQ-OFF-3) — per-entity server sequence cursors, not full-table pulls.
3. **Field-level conflict resolution** (SHEQ-OFF-4/5) — the spec's table implemented as a policy
   object per entity, with append-only collections unioned and scalar conflicts surfaced in a
   "Needs your attention" queue. Silent loss of a field worker's data is treated as a P0 defect.
4. **Server-side transition re-validation** (SHEQ-OFF-6) — the state machine is authoritative on the
   server; a rejected transition returns the record with an explanation and keeps the user's data.
5. **Media out of band** (SHEQ-OFF-7) — resumable chunked uploads, independent of record sync.

The IndexedDB store replaces SQLite; the contract is unchanged. Where the PWA genuinely cannot
deliver — background media upload after app kill, NFC scanning (SHEQ-INS-5) — those specific
capabilities are flagged rather than silently dropped.

## Consequences

- One auth, permission, i18n and release pipeline. A native shell can be added later (Capacitor over
  the same build) if store presence or background upload becomes a requirement.
- The sync engine is written once and every module inherits it — Fleet inspections, Attendance
  clock-ins and Leave capture have the same offline need today and each solves it partially.
- **Honest limitations:** iOS PWAs evict storage under pressure and have no true background upload,
  so a phone that never reopens the app can hold media longer than a native client would. For sites
  with genuinely no connectivity for days, this needs measuring before it is promised.
- NFC (SHEQ-INS-5) is unavailable on iOS PWAs; QR scanning covers the same workflow and is used.
