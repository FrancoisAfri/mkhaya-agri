# SHEQ-ADR-005 — The platform timezone discrepancy

**Status:** Open — needs a platform decision
**Date:** 2026-08-07
**Affects:** every module, not only SHEQ

## What was found

While testing the KPI projection's staleness figure, a snapshot written moments earlier reported a
lag of exactly 7 200 seconds. Two hours is the SAST offset, which is rarely a coincidence.

```
app.timezone         : UTC
db session TimeZone  : Africa/Johannesburg
php  now()           : 2026-08-07T13:10:07+00:00
db   now()           : 2026-08-07 15:10:07+02      ← the same instant, correctly
stored via now()     : 2026-08-07 13:10:07+02      ← two hours early
```

The application runs in UTC and sends a naive `13:10:07` to the database. The session interprets
naive input in *its* zone — Africa/Johannesburg — and stores `13:10+02`, which is `11:10 UTC`. The
instant is wrong by the offset.

This affects **every `timestamptz` column written through Eloquent or the query builder**, in every
module, on any deployment where the database session zone differs from `app.timezone`.

## Why it matters here more than most

SHEQ compares stored timestamps to the current time to decide things with legal consequences:

- whether a statutory notification deadline has passed (SHEQ-REG-5)
- whether a hot-work fire watch has elapsed before a permit may be closed (SHEQ-PTW-4)
- whether a permit has run out of validity (SHEQ-PTW-4)
- whether a hazard's triage SLA has been breached (SHEQ-HAZ-2)

An error of two hours in either direction on any of those is a defect with a regulator attached.
Stored-to-stored comparisons stay self-consistent — a due date derived from an occurrence and
compared to another stored value behaves correctly, which is why the obligation tests pass. It is
comparisons against `now()` that drift.

## Interim decision

`KpiService::snapshot()` measures projection lag with the **database's** clock
(`now() - computed_at` evaluated in SQL) rather than PHP's. That sidesteps the mismatch for the one
figure that exposed it, without changing platform behaviour.

Nothing else has been changed, because the real fix is platform-wide and is not SHEQ's to make
unilaterally.

## The real fix, for a platform decision

Add an explicit timezone to the `pgsql` connection so the session and the application agree:

```php
'pgsql' => [
    // …
    'timezone' => '+00:00',   // match config('app.timezone')
],
```

Consequences that need weighing before anyone does this:

- **Going forward** naive timestamps are interpreted as UTC, matching what the application meant.
- **Existing rows are not corrected.** They were written under the mismatch and remain shifted by
  the offset. Whether that warrants a backfill depends on which columns matter — payroll periods,
  leave dates and attendance clock-ins are the ones to look at first, and some of those are date
  columns and therefore unaffected.
- **A deployment whose database session already runs UTC is unaffected**, so production may not
  share the symptom. That should be checked before assuming the data is wrong everywhere.

Recommended: confirm the session zone in each environment, then decide on the connection setting and
a backfill scope. Until then, code that compares a stored timestamp to the current time should do so
in SQL.
