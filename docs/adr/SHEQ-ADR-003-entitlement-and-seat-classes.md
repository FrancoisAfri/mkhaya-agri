# SHEQ-ADR-003 — Entitlement and seat classes

**Status:** Accepted · **Date:** 2026-08-07
**Requirements:** SHEQ-ENT-1, SHEQ-ENT-2, SHEQ-ENT-3

## Context

SHEQ-ENT-1 defines six seat classes — `REPORTER` (free, unlimited), `CONTRIBUTOR`, `PRACTITIONER`,
`MANAGER`, `EXTERNAL_AUDITOR`, `CONTRACTOR_USER` — with per-seat-class billing.

The platform already has a two-part entitlement model, used by all 44 modules:

- **Module entitlement** — `billing_modules` catalogue plus `CompanyEntitlements::allowsNavModule()`,
  driven by the tenant's plan (SaaS) or signed licence (self-hosted), enforced by
  `EnsureModuleIsActive`.
- **Access tier** — `security_module_access.access_level` 1–5 (READ … SUPERUSER), resolved by
  `HubAccess::level()` and enforced per route by `EnsureModuleTier`, per hub and per card.

Seat classes are a genuinely different axis: they price *users*, not modules. Introducing them
touches subscriptions, invoicing, the plan builder, seat counting, the platform admin console and
every existing module's notion of a user — none of which is SHEQ.

## Decision

SHEQ ships on the existing model, mapped as follows:

| Spec seat class | Platform equivalent |
|---|---|
| `REPORTER` | Tier 1 READ, plus explicit report-only permissions (`sheq.hazard.create`, `sheq.incident.report`) |
| `CONTRIBUTOR` | Tier 2 WRITE |
| `PRACTITIONER` | Tier 3 MODIFY |
| `MANAGER` | Tier 4 ADMIN / 5 SUPERUSER |
| `EXTERNAL_AUDITOR` | Auditor Mode (SHEQ-DASH-6): time-boxed, scoped, read-only, watermarked, session-logged |
| `CONTRACTOR_USER` | Contractor-scoped principal, restricted to their own company's records |

SHEQ-ENT-2 is already satisfied: enforcement is server-side per route, and the 403 names what is
missing so the client can prompt.

**"REPORTER is free and unlimited" is not implemented, and is not SHEQ's to implement.** Today every
user consumes a seat under the tenant's plan. Free unlimited reporters require a billing change at
platform level; SHEQ must not fork entitlement to fake it.

## Consequences

- No parallel permission system. A SHEQ administrator grants access the same way as for any module.
- The commercial promise behind `REPORTER` — get every worker reporting hazards without per-head
  cost — is unmet until platform billing supports non-consuming seat classes. **This is a product
  risk, not an oversight:** hazard reporting rate per 100 employees (SHEQ-DASH-2) is a headline KPI,
  and charging per reporter suppresses exactly the behaviour the module exists to encourage.
- Recommended follow-up, platform-scoped and outside this module: a `seat_class` on the user's
  company membership, with plan limits counted per class. Every module benefits (LMS learners,
  Conferences delegates and Marketplace buyers have the same shape).
- SHEQ-ENT-3 holds: Competency degrades to manual certificate capture when LMS is unlicensed,
  because the LMS adapter is behind an interface (SHEQ-ADR-002) with a manual fallback.
