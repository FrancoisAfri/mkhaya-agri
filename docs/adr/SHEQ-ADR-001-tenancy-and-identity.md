# SHEQ-ADR-001 — Tenancy and identity

**Status:** Accepted · **Date:** 2026-08-06
**Requirements:** SHEQ-TEN-1, SHEQ-TEN-3, §4 preamble

## Context

The SHEQ specification states every tenant-owned table carries `tenant_id UUID NOT NULL` with an
RLS policy bound to a session variable, and that every table has a `UUID` primary key.

Mkhaya Hub already implements exactly this guarantee with different spellings:

- the tenant key is `company_id` (bigint) referencing `companies`,
- RLS is enabled and **forced** on 1072 tables, with a `tenant_isolation` policy bound to the
  `app.company_id` session variable set per request,
- models use the `BelongsToCompany` trait, which adds the global scope and stamps the key on write,
- primary keys are bigint, with a separate indexed `uuid` column for public exposure; routes bind
  by `uuid` so internal ids never appear in URLs.

Project Management faced the identical mismatch and resolved it the same way (PM-ADR-001, ADR-003).

## Decision

SHEQ uses `company_id` + `BelongsToCompany` + forced RLS on `app.company_id`, and bigint primary
keys with a public `uuid` column. Wherever the spec says `tenant_id`, read `company_id`; wherever it
says a UUID primary key, read "bigint id, uuid public identifier".

The **guarantee** in SHEQ-TEN-1 is met in full: no query relies on an application-layer
`WHERE company_id = ?`, because the database enforces it even for the table owner.

## Consequences

- One tenancy mechanism platform-wide. A second one would mean two ways to get isolation wrong, and
  every cross-module join (HR, LMS, Fleet, Assets — all `company_id`) would need a translation layer.
- Site-level access control (SHEQ-TEN-3) is **not** tenancy and is not solved by RLS. It is a
  separate authorisation concern layered above: see SHEQ-ADR-005.
- `sheq_evidence`, `sheq_audit_log` and the outbox carry `company_id` like everything else, so the
  hash chain and the retention rules are per company by construction.
- External payloads (API, mobile sync, exports) expose `uuid` only.
