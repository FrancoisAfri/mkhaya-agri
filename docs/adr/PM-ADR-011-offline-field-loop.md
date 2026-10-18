# PM-ADR-011 — The field loop is offline-first, and conflict-free by construction

**Status:** Accepted · **Date:** 2026-08-07 · **Backlog:** EPIC-060, EPIC-061, PM-ADR-004

## Context

The people who generate most of the evidence work in trenches, basements and yards outside town.
The sync that matters happens on one bar of reception before a bakkie leaves, and the recording
happens hours later with no signal at all.

A conventional web page fails this in two ways: it shows a spinner where the person expected a tick,
and it loses the tap entirely when the request times out.

## Decision

**Everything renders from IndexedDB.** `GET /api/v1/pm/sync` fills a local bundle — the projects a
person can see, their tasks, checklists, and thirty days of evidence. The field page reads only from
that store, so it looks identical with signal and without.

**Every change is written locally first and queued second.** The screen shows what the person just
did, immediately. `local_status` on a cached task is what the UI reads until the next pull overwrites
the row, so their own action is never the thing that vanishes when they scroll away.

**`?since=` makes every sync after the first a delta**, which is what makes syncing on the way out
of the yard realistic rather than theoretical.

## Conflicts are avoided, not resolved

The writes a device can make are deliberately restricted to ones that cannot conflict:

| Write | Why it cannot conflict |
|---|---|
| Task status | A declaration of a final state. Two devices in either order settle on the same answer. |
| Checklist tick | The same. |
| Evidence | Append-only (PM-ADR-002). Two records are two facts, not a conflict. |
| Hours | Additive, guarded by a day total the server enforces. |

Nothing a phone can send requires a merge. That is a constraint on the feature set, chosen
deliberately: a field app with a conflict-resolution dialogue is a field app where someone in a
trench is asked to arbitrate a data model, and they will pick whichever button dismisses it.

## Idempotency is the client's problem too

A device that loses signal mid-sync retries its whole queue. The API accepts `Idempotency-Key` — but
that is worth nothing unless the queued row carries the *same* key on every attempt.

The offline queue therefore stores headers alongside each request and generates the key **when the
row is queued**, not when it is sent. Without that, the retry that recovers from a dropped
connection would book a second eight hours, which is the single worst thing this loop could do.

This changed `PendingRequest` platform-wide (`offlineDb` v3), because the gap applied to every
module using the queue, not only this one.

## Consequences

**The bundle has a ceiling.** 2,000 rows per collection and thirty days of evidence. A phone holding
part of a very large project is told so — `limits.truncated` — because a client that silently
received half a list would show a foreman a day missing the work they came for.

**Storage is finite and shared.** The bundle competes with every other module's offline data. The
existing storage estimate in `useOfflineQueue` reports it; a device that runs out fails at the write
rather than corrupting what it holds.

**WebAuthn, not platform biometrics.** PM-1600 asks for biometric unlock. A PWA cannot reach the
platform biometric APIs, so this is WebAuthn — which on most phones *is* the fingerprint sensor, but
through a different door. This is the one place the field loop knowingly falls short of the brief as
written, and it was accepted when the delivery plan was approved.

**A rejected write has to be visible.** Five failed attempts and the row is dropped, with its label
surfaced on the page. The wording is plain — "nothing has been lost, it just has not arrived" —
because the alternative is a person re-entering a day's work they already did.
