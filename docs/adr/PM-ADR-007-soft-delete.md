# PM-ADR-007 — Soft delete

**Status:** Accepted
**Date:** 2026-08-06
**Brief's recommendation:** `deleted_at` everywhere; no hard deletes on project data — audit and
retention requirements.

## Context

Project data is evidence in disputes: a deleted task, a removed dependency or a dropped cost line
can be the difference between a defensible claim and an unprovable one. Retention obligations also
mean records must survive their apparent deletion. The platform already applies `deleted_at` broadly
and has migrations that retrofitted soft deletes across modules.

## Decision

Every PM table carries `deleted_at` and its model uses `SoftDeletes`. No PM code path issues a hard
`DELETE` on project data.

Specific rules:

- **The Evidence Ledger is exempt in the other direction** — it is append-only and has no delete at
  all, soft or hard (see [PM-ADR-002](PM-ADR-002-evidence-ledger-storage.md)). Evidence is corrected
  by supersession.
- **Deleting a parent does not orphan children.** Deleting a WBS node or a task soft-deletes its
  subtree in the same transaction, so a restore brings back a coherent structure rather than
  fragments.
- **Deleted rows stay tenant-scoped.** RLS and `CompanyScope` apply to soft-deleted rows exactly as
  to live ones; `withTrashed()` never crosses a tenant boundary.
- **Deletion is audited** like any other mutation, with the actor and time.
- **Restore is a first-class operation**, not a DBA task: a soft-deleted project can be restored
  through the API by a user with the relevant permission.
- **Purge is separate and deliberate.** Genuine erasure (e.g. a POPIA erasure request) goes through
  the platform's compliance/anonymisation path, which is a distinct, audited, permissioned operation
  — never an ordinary delete.

## Consequences

- Nothing routine is unrecoverable, which is the point.
- Every PM query must be scope-aware; a report that forgets soft deletes will over-count. The global
  `SoftDeletes` scope handles this by default, and raw SQL in reports must filter explicitly.
- Unique constraints must tolerate soft-deleted rows: a project code freed by deletion can be reused,
  so uniqueness on `(company_id, code)` is enforced among non-deleted rows via a partial unique
  index rather than a plain unique constraint.
- Storage grows monotonically until an explicit retention job archives it. Accepted.
