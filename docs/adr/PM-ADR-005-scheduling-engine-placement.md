# PM-ADR-005 — Scheduling engine placement

**Status:** Accepted
**Date:** 2026-08-06
**Brief's recommendation:** In-process library, pure functions, no DB access inside the algorithm —
makes the 20k-task/5s budget testable and the engine reusable.

## Context

Critical Path Method scheduling (forward pass, backward pass, float, critical flagging, calendar-aware
date arithmetic) is the module's most algorithmically demanding component and its most performance-
sensitive: the budget is 20,000 tasks in 5 seconds. An engine that queries the database mid-algorithm
cannot meet that budget and cannot be unit-tested without a database.

## Decision

The scheduling engine is a **pure in-process library** under `App\Modules\Operations\ProjectManagement\Scheduling`.

- It takes a plain in-memory graph (tasks, dependencies, calendars, constraints) as value objects
  and returns computed results (early/late start and finish, total and free float, critical flags).
- It performs **no** database access, no HTTP, no cache reads, no `now()`, no config lookups, and no
  logging. Everything it needs — including the clock — is passed in.
- Loading the graph and persisting the results are the caller's job, in a service that wraps the
  engine: read once, compute, write once, inside a single transaction.
- Cycle detection is part of the engine and returns a structured error identifying the cycle, rather
  than throwing or looping.

Determinism is a requirement, not a nicety: given identical inputs the engine must produce identical
outputs, so a schedule can be recomputed and compared (this is what makes baselines and variance
analysis trustworthy).

## Consequences

- The performance budget is measurable in a unit test with a generated 20k-task graph, with no
  database in the loop and therefore no noise from I/O.
- The engine can be reused by the what-if simulator, the baseline comparison, and eventually a
  client-side preview, because it depends on nothing but its inputs.
- Callers must load the whole graph before computing. For a very large project this is a deliberate
  memory cost, bounded by the same budget the engine is tested against.
- Calendar arithmetic (working windows, exceptions, timezones) lives inside the engine as pure
  functions over passed-in calendar data — so a holiday calendar change is an input change, not a
  code change.
