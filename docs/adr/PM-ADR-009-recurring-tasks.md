# PM-ADR-009 — Recurring tasks are materialised, not computed

**Status:** Accepted · **Date:** 2026-08-07 · **Backlog:** PM-1122

## Context

A site runs on repetition: inspect the scaffold every Monday, count stock on the last day of the
month, service the generator every 250 hours. The backlog asks for RRULE recurrence on tasks.

There are two ways to build it. Either occurrences are **computed on read** — the rule is stored and
the list is expanded when someone looks at it — or they are **materialised**, written to the tasks
table ahead of time.

Computing on read is tidier. It has no generator to schedule, no horizon to choose, nothing to
back-fill after downtime, and the rule can be edited without reconciling anything already produced.

## Decision

Occurrences are materialised, a horizon ahead (default 60 days, hard maximum 365).

A recurrence hangs off a **template task** that nobody works on: `is_recurrence_template` keeps it
out of every list, and each occurrence is copied from it with `occurrence_of_id` and
`occurrence_date` pointing back.

## Why

A computed occurrence does not exist, and everything the module does to a task needs it to.

- It cannot be **assigned**, because there is no row to hang the assignment on.
- It cannot be **commented on** or carry a photo, so the record of what was found on the day has
  nowhere to live.
- It cannot be **completed offline**, which is the whole point of the field app (EPIC-060): the
  phone has to hold the task before the signal drops, not derive it from a rule it also has to hold.
- It cannot be **scheduled**: the CPM engine walks real tasks and real dependencies (PM-ADR-005).

Every one of those would need a "materialise this one first" path, at which point the system
materialises anyway — just unpredictably, at the moment someone happens to tap the right thing.

## Consequences

**A generator has to run.** `pm:generate-recurring-tasks` runs nightly at 02:30, fanning out per
tenant through `TenantContext`. Missing a night is not a data-loss event: the next run's window
starts from `last_occurrence_at`, so the gap fills itself.

**It must be safe to run twice.** `(occurrence_of_id, occurrence_date)` is uniquely indexed, and the
generator both checks before inserting and catches the unique violation — the check is the fast
path, the constraint is the guarantee. A doubled cron cannot double the site's inspection list.

**Editing the rule does not rewrite the past.** Stopping a recurrence cancels future occurrences
that nobody has started and leaves everything else exactly as it is. Deleting a fortnight of
completed inspections because someone ended the rule would destroy the record of work that was done.

**A horizon must be chosen.** 60 days by default. Too short and the field app runs out of work
between syncs; too long and a daily rule fills the table with tasks nobody will look at for a year.

## The RRULE subset

`Support\Recurrence\Rrule` implements FREQ, INTERVAL, COUNT, UNTIL, BYDAY (with ordinals),
BYMONTHDAY (negative counting back), BYMONTH and WKST. Anything else — BYSETPOS, BYWEEKNO, EXDATE —
is **rejected by name**, not ignored. A rule that silently loses a part produces a schedule that is
wrong on exactly the dates its author cared about.

Two behaviours are deliberate and tested:

- **A month too short is skipped, not clamped.** `BYMONTHDAY=31` produces nothing in February.
  Clamping would turn "the 31st" into "the 28th" and drift a monthly stock count three days early.
- **The rule is written in local time.** A 09:00 inspection stays 09:00 across a daylight-saving
  change; an occurrence at a clock time that does not exist that morning shifts forward rather than
  vanishing.

The expander is pure — no clock, no database — which is what makes its fixtures worth trusting.
