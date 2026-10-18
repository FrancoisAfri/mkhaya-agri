# SHEQ-ADR-002 — Cross-module reads (the ARC-3 / INC-6 tension)

**Status:** Proposed — awaiting product sign-off
**Date:** 2026-08-06
**Requirements:** SHEQ-ARC-3, SHEQ-INC-6, SHEQ-COMP-1, principle P3

## Context

Two P1 requirements pull in opposite directions.

**SHEQ-ARC-3** — "SHEQ MUST NOT read another module's database directly. All cross-module reads go
through that module's published API or a materialised read model built from its events."

**SHEQ-INC-6** — the context snapshot, described in the spec as "the single highest-value feature in
the module", justified precisely because "SHEQ shares a data plane with HR, LMS, Fleet and Assets".

The snapshot needs, at `occurred_at`: competency status per involved employee, last inspection of a
linked asset or vehicle, open hazards and CAPAs at the location, the approved risk assessment and
whether its control was implemented, any valid permit, and contractor compliance.

Today none of HR, LMS, Fleet or Assets publishes domain events or exposes an internal API. Only
Project Management has an outbox. Building ARC-3 literally means first giving six mature modules an
event backbone and building projections for each — a platform programme, before SHEQ writes a table.

The spec's own model concedes this: `sheq_employee_ref` is documented as "projected from HR events",
and HR emits none.

## Decision (proposed)

Adopt an **anti-corruption layer** rather than either extreme:

1. SHEQ defines its own read interfaces — `EmployeeDirectory`, `CompetencyLedger`, `AssetRegistry`,
   `VehicleRegistry`, `ContractorRegistry` — expressed in SHEQ's language and returning SHEQ DTOs.
2. The v1 implementations read the owning module's **Eloquent models** (never its raw tables, never
   its internals), so a change there breaks one adapter, not the module.
3. `sheq_employee_ref` and `sheq_competency_status` remain as tables, but are **materialised
   projections refreshed on a schedule and on demand**, not event-sourced. `source_version` is kept
   in the schema so the switch to events needs no migration.
4. When a module gains an outbox, its adapter switches to consuming events. The interface does not
   change, and neither does any caller.

The context snapshot is written **through these interfaces**, once, at incident creation, and stored
immutably (`sheq_incident_context`). Its evidentiary value comes from being frozen at `occurred_at`
— which is unaffected by how the data was read.

## Consequences

- INC-6 is deliverable in Phase 1 instead of after a platform-wide event programme.
- ARC-3's real intent — no hidden coupling to another module's schema — is honoured; the coupling is
  explicit, in one file per module, and testable with a fake.
- The letter of ARC-3 is not met today. This ADR is the record of that deviation; it needs product
  sign-off, and the alternative (event backbone first) should be costed before it is rejected.
- Staleness is visible, not silent: projections carry `last_synced_at`, and a snapshot taken from a
  projection older than a configurable threshold is flagged on the incident.

## Alternatives considered

**Event-sourced projections now.** Correct end state, wrong starting point: six modules need outbox,
schema and backfill work before SHEQ can classify its first incident.

**Direct joins in SHEQ queries.** Cheapest, and what most of the platform does today. Rejected: it
spreads knowledge of HR's schema through SHEQ's query layer, which is exactly what ARC-3 forbids and
what makes the eventual migration to events unaffordable.
