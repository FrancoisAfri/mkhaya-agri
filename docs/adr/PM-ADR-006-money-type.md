# PM-ADR-006 — Money type

**Status:** Accepted
**Date:** 2026-08-06
**Brief's recommendation:** Integer minor units + ISO currency code. Never floats. "Non-negotiable."

## Context

The non-negotiable part of the brief is **never floats**, and that is not in dispute. The open
question is only *which* exact representation.

The brief proposes `bigint` minor units. This codebase already stores money as PostgreSQL
`numeric(18,2)` with a currency, across Finance/Accounting, Budget, Procurement, Claims, Payroll and
Petty Cash. `numeric` is an arbitrary-precision exact decimal type — it is **not** a float, and it
does not suffer the representation error the brief is guarding against. Both options satisfy the
actual constraint.

What differs is interoperability. Project costs must roll up into budgets, post to the general
ledger, and reconcile against purchase orders and expense claims, all of which are `numeric(18,2)`.
Storing PM money in minor units would put a conversion at every one of those boundaries — and a
conversion is precisely where rounding defects appear.

## Decision

Money in Project Management is stored as **`numeric(18,2)` plus an explicit ISO-4217 currency
column**, matching the rest of the platform. There are no bare amounts: a column holding money
always has a currency column beside it (or inherits the project's, and is documented as doing so).

Supporting rules:

- **Never floats.** No `float`, `double` or `real` column ever holds money. Application code uses
  string/decimal arithmetic; a PHP `float` never touches a monetary value.
- **Explicit currency, always.** Project, task, budget and expense amounts each carry a currency.
  Cross-currency aggregation requires a stated rate and records the rate used — it is never done
  implicitly.
- **Serialise as strings.** Money is emitted in JSON as a decimal string (`"1234.50"`), never as a
  JSON number, so a JavaScript client (which has only IEEE-754 doubles) cannot silently degrade it.
  This closes the same hole the brief's minor-units proposal closes, at the API boundary where it
  actually matters for the mobile client.
- **Quantities are not money.** Measured quantities keep `numeric(18,4)` — four decimals, because
  m³, tonnes and hours legitimately need them.

## Consequences

- PM amounts join, sum and reconcile against Finance, Budget and Procurement with no conversion
  layer, which removes an entire class of rounding defects at module boundaries.
- The mobile client receives strings and must use a decimal library for arithmetic. This is an
  explicit requirement on the field app, not an accident.
- This ADR is a deliberate departure from the brief's stated mechanism. The brief's intent — exact
  arithmetic, explicit currency, no floats anywhere — is fully preserved; only the storage type
  differs, in favour of platform consistency.
- If PM ever needs a currency with a different minor-unit exponent (0 or 3 decimals), the scale is
  a schema decision to revisit — `numeric(18,2)` assumes two. Currencies in use today (ZAR, USD,
  EUR, GBP) are all two.
