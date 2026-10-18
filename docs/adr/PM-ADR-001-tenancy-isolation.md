# PM-ADR-001 — Tenancy isolation model

**Status:** Accepted
**Date:** 2026-08-06
**Brief's recommendation:** Shared schema + `tenant_id` on every table + row-level security enforced
in the DB, not just the ORM.

## Context

The brief is right about the shape — shared schema, per-row tenant column, RLS in the database — and
right that retrofitting RLS across 40 tables is a multi-month project. That work has already been
done here, under a different column name.

Mkhaya Hub enforces row-level multi-tenancy as:

- `company_id` (bigint) on every tenant-owned table.
- `ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY` with a `tenant_isolation` policy reading
  the `app.company_id` session variable, so the isolation survives raw SQL and a compromised ORM.
- `DEFAULT current_setting('app.company_id')` on the column, so an INSERT that forgets the tenant
  still lands in the right tenant.
- Application layer: the `BelongsToCompany` trait (global `CompanyScope` + auto-stamp on create),
  `ResolveCompany` middleware, and tenant propagation into queued jobs and console commands.
- A NULL session variable means "no active company" (console, platform admin) and disables the
  policy — this is how platform-level jobs and seeders work.

Introducing a `tenant_id` on Project Management tables would produce a module that is invisible to
all of that: `CompanyScope` would not filter it, the RLS policies would not cover it, queue and
command tenant propagation would not reach it, and every join to HR, Finance or Procurement would
need a mapping table.

## Decision

Project Management uses **`company_id` + the existing FORCE RLS mechanism**. Every `pm_*` table:

1. carries `company_id bigint` with the session-variable default,
2. has `ENABLE`/`FORCE ROW LEVEL SECURITY` and the standard `tenant_isolation` policy,
3. has its model using `BelongsToCompany`.

There is no `tenant_id` column anywhere in this module. Where the delivery brief and the data model
say `tenant_id`, read `company_id`.

Partitioned tables (see [PM-ADR-002](PM-ADR-002-evidence-ledger-storage.md)) declare the policy on
the **parent** table; PostgreSQL applies it to every partition, including ones created later.

## Consequences

- Cross-module joins (project member → HR employee, project cost → Finance) work directly, with no
  tenant-key translation.
- The DoD's multi-tenant isolation test is testable the same way as the rest of the platform:
  set `CurrentCompany`, confirm tenant A cannot read or write tenant B's rows by guessing IDs.
- A future move to `tenant_id` (e.g. if tenancy is ever decoupled from companies) is a platform-wide
  rename, not a PM-specific one — which is the correct blast radius.
- Anything reaching PM data outside a request (schedulers, sync workers, the evidence derivation
  job) **must** set the company context explicitly, or RLS will show it nothing. This is the
  established failure mode for untenanted crons in this codebase and is called out in every job we
  add.
