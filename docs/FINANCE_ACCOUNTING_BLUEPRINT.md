# Finance & Accounting Engine — Blueprint (Laravel Monolith Edition)

**Status:** design, pre-build · **Owner:** engineering · **Target stack:** the existing
Mkhaya Hub monolith (Laravel 12, PHP 8.4, Inertia + Vue 3 + Vuetify, PostgreSQL,
database queue, cPanel deploy).

This adapts the phased "Finance & Accounting Suite" plan to what Mkhaya Hub
*actually is*. It keeps every **principle** of that plan and drops the greenfield
**infrastructure** (Kafka, microservices, schema-per-tenant, ClickHouse,
Terraform) that doesn't fit a single Laravel monolith run by a small team.

---

## 1. Principles (unchanged from the master plan)

1. **One shared accounting engine** — no module keeps its own ledger.
2. **Event-driven posting** — operational modules emit financial events; Finance consumes.
3. **No duplicate capture** — data originates in the operational module, flows to Finance.
4. **Modular** — Finance runs standalone; producers attach later, one at a time.
5. **Real-time** — user-visible numbers are never batch-only.
6. **Audit-first** — every posting is immutable; corrections are new (reversing) entries.

## 2. Architecture decisions (the important part)

| Master plan | Mkhaya Hub decision | Why |
|---|---|---|
| Kafka / NATS event bus | **Laravel events + a `fin_postings` outbox table** (Postgres), drained by a queue worker on the existing `database` queue | No new infra; transactional; already have the queue |
| Outbox + schema registry | **Transactional outbox row** written in the same DB transaction as the source action; a versioned `FinancialEvent` DTO + contract tests | Exactly-once posting without Kafka |
| Microservices / multi-repo | **One module: `app/Modules/Finance/Accounting`** with a single public contract `Ledger::post(FinancialEvent)` | Monolith; enforce the boundary in code, not network |
| Schema-per-tenant | **Single install per client** (as today) + `company_id` row scoping | You're already deployed per-client |
| ClickHouse | **Postgres materialised views** / a reporting schema; add a column store only if a tenant outgrows it | Scale you don't have yet |
| Terraform / Unleash / OTel | Config/DB feature flags now; observability via existing logs + Spatie activity log | cPanel reality |

**Boundary rule:** operational modules **never** write to `journals`/`journal_lines`.
They only (a) emit a `FinancialEvent` or (b) call `Ledger::post()`. Enforced by
keeping those models' write methods inside the Accounting module and code-review.

## 3. The canonical Financial Event (v1 contract)

A PHP value object (`App\Modules\Finance\Accounting\Contracts\FinancialEvent`).
This is the **one shape** every producer emits. Lock this before writing producers.

```php
FinancialEvent {
  string  eventId;          // uuid
  int     companyId;
  string  sourceModule;     // 'payroll' | 'procurement' | 'crm' | 'fleet' | ...
  string  sourceRef;        // e.g. 'payroll_run:42', 'crm_invoice:1001'
  string  eventType;        // 'payroll.run.posted', 'ar.invoice.raised', ...
  Carbon  eventDate;        // accounting date (drives the fiscal period)
  string  currency;         // ISO 4217, default 'ZAR'
  Line[]  debits;           // [{ account (code|id), amount, dimensions{} }]
  Line[]  credits;          // must total == debits total (same currency)
  array   metadata;         // free-form, stored on the journal
  string  idempotencyKey;   // producer-stable; dedupes replays
}
Line { string|int account; float amount; array dimensions; string|null narration; }
```

Rules: `sum(debits) === sum(credits)`; amounts > 0; accounts must exist and be
postable; currency single per event (FX handled by the engine, §5).
**Versioning:** additive only. Consumers must accept v1 forever.

## 4. Core data model (Phase 1 migrations)

All tables prefixed `fin_`. Money as `numeric(18,2)`; store `currency` alongside.

- **`fin_accounts`** (chart of accounts): `id, company_id, code, name, type`
  (`asset|liability|equity|income|expense`), `subtype`, `parent_id` (nestable),
  `is_postable` (bool; parents aren't), `is_active`, `tax_code`, `bank_link`,
  `normal_balance` (`debit|credit`), timestamps. Unique `(company_id, code)`.
- **`fin_fiscal_periods`**: `id, company_id, fiscal_year, period_no, start_date,
  end_date, status` (`open|soft_close|hard_close|locked`). One row per month.
- **`fin_journals`** (header, immutable once posted): `id, uuid, company_id,
  journal_no` (numbering series), `journal_date, period_id, source_module,
  source_ref, event_type, currency, description, status` (`draft|posted|reversed`),
  `reverses_journal_id` (nullable, self-FK), `idempotency_key` (unique),
  `posted_by, posted_at, created_at`. **No updates after `posted`.**
- **`fin_journal_lines`** (immutable): `id, journal_id, account_id, debit, credit`
  (one is 0), `currency, fx_rate, base_debit, base_credit, narration`,
  `dimensions` (jsonb: `{cost_centre, project, branch, vehicle, employee, ...}`),
  `company_id` (denormalised for fast control-account queries). Index
  `(account_id)`, `(journal_id)`, and a GIN on `dimensions`.
- **`fin_postings`** (transactional outbox): `id, event_id (unique), payload
  (jsonb), status` (`pending|posted|failed`), `attempts, error, source_module,
  source_ref, created_at, processed_at`. The producer writes this row **in the
  same DB transaction** as its own change; a queue job drains it into a journal.
- **`fin_exchange_rates`**: `id, company_id, from_ccy, to_ccy, rate, rate_type
  (spot|month_end|budget), effective_date, source`.
- **`fin_number_series`**: per company + doc type, prefix + next number (journals,
  invoices, receipts).

**Dimensions** reuse existing org data — no new dimension tables needed for v1:
`company_id` → `security_modules`/company; cost centre/department →
`company_units`; division → `divisions`; project/vehicle/employee → the source
module's id stored in `dimensions` jsonb. A generic `fin_dimensions` lookup can
come later if free-form dimensions are needed.

## 5. The Ledger engine (`Ledger::post()`)

Single entry point. Flow:

1. **Idempotency** — if a posted `fin_journals` row exists for `idempotencyKey`,
   return it (no double post).
2. **Validate** — accounts exist + `is_postable`; debits total == credits total;
   `eventDate` falls in an **open/soft_close** period (reject `hard_close`/`locked`).
3. **FX** — if `currency` ≠ company functional currency, look up the rate
   (`fin_exchange_rates`), compute `base_debit/base_credit`; realised/unrealised
   FX gain/loss lines auto-added when settling.
4. **Write** — inside a DB transaction: create `fin_journals` (status `posted`) +
   balanced `fin_journal_lines`; assign `journal_no` from `fin_number_series`.
5. **Audit** — Spatie activity log entry; optional hash-chain (`prev_hash`) on the
   journal for tamper-evidence.
6. **Reversal** — `Ledger::reverse(journal, reason)` creates a new journal with
   swapped debit/credit, links `reverses_journal_id`, marks the original
   `reversed`. Originals are **never** edited or deleted.

Producers use one of two paths:
- **Async (default):** emit → write `fin_postings` outbox row in-transaction → a
  `ProcessFinancialPostings` job calls `Ledger::post()`. Survives failures; replayable.
- **Sync:** call `Ledger::post()` directly when the user must see the journal
  immediately (e.g. a manual journal screen).

## 6. RBAC, periods, immutability

- **RBAC:** reuse the existing role/permission system (`EnforceCrudPermissions`).
  Add posting-limit rules: `can post journal to company X up to amount Z`,
  `can open/close period`, `can reverse`. Segregation-of-duties = permission pairs.
- **Periods:** `open` → normal; `soft_close` → only corrections with reason;
  `hard_close` → reversal-only; `locked` → nothing. Year-end job rolls P&L into
  retained earnings and opens next year's balances.
- **Immutability:** enforced in the model (`saving` guard blocks updates to
  `posted` journals/lines) + the audit trail.

## 7. Module posting map (first producers)

Generic recipe per module: (1) map operational action → `FinancialEvent`,
(2) write outbox row in the source transaction, (3) posting rules live in Finance,
(4) contract + replay tests, (5) a **subledger↔GL parity report**, (6) backfill.

First three producers (biggest value, data already exists):

- **Payroll** (`payroll_runs`, `payslips`, `payroll_earnings_lines`): on run
  posted → Dr salary/wages expense (by dept/cost-centre dimension), Cr net-pay
  liability, Cr PAYE/UIF/SDL/pension/medical liabilities. Leave & bonus provisions.
- **Procurement** (`purchase_orders`, `invoices`, matching): PO = commitment
  (memo only); GRN = Dr GRNI/inventory, Cr accrual; supplier invoice = Dr expense/
  GRNI, Cr AP; payment = Dr AP, Cr bank.
- **CRM** (invoices/receipts, once CRM invoicing lands): invoice = Dr AR, Cr income
  + Cr VAT; receipt = Dr bank, Cr AR. (Quotations/orders: statistical, no posting.)

Later: Fleet (fuel/repairs → expense by vehicle dimension; depreciation feed),
Assets (capitalise, depreciate financial + tax books), Property, Projects (WIP).

## 8. Reporting (Phase 1 set)

Server-generated from `fin_journal_lines`, real-time, drill-to-journal:
Trial Balance, General Ledger (by account / by dimension), Balance Sheet, Income
Statement, Cash Flow (indirect). Start as SQL; promote hot reports to Postgres
**materialised views** refreshed on post. AR/AP ageing arrives with those subledgers.

## 9. Cross-cutting

- **Subledger integrity monitor:** daily job asserts each subledger balance ==
  its GL control account; drift raises an alert (mirrors the CRM import-parity idea).
- **Reprocessing:** failed `fin_postings` rows are inspectable + replayable (like a DLQ).
- **Testing:** the double-entry engine gets exhaustive unit tests (balance,
  idempotency, period rejection, reversal); each producer gets a contract test.
- **Currency:** ZAR default; multi-currency built into the engine from day one.

## 10. Build order for this stack (pragmatic phases)

- **P1 — GL core (build now, "A"):** `fin_accounts` + SA-SME CoA seed,
  `fin_journals`/`fin_journal_lines`, `fin_fiscal_periods`, `Ledger::post()` +
  `reverse()`, manual-journal screen, Trial Balance + Balance Sheet + Income
  Statement. **DoD:** onboard a company, import a CoA, capture openings, post
  journals, produce a balanced TB + statements; p95 report < 2s at 50k lines.
- **P2 — first producer + outbox:** `fin_postings` outbox + `ProcessFinancialPostings`
  job; wire **Payroll** as producer; subledger↔GL parity report. Proves the contract.
- **P3 — AR/AP subledgers**, then **Banking & reconciliation**, then remaining
  producers (Procurement, Fleet, Assets…), then the dashboard, then AI-on-top
  (the AI reads a semantic/reporting layer, never raw tables, and can never bypass
  RBAC or auto-post without approval).

## 11. Non-goals (explicitly out, for now)

Kafka/NATS, microservices split, schema-per-tenant, ClickHouse, Terraform,
LaunchDarkly, distributed tracing. Revisit only if real scale demands it.

---

### Immediate next step ("A")
Build **P1 — GL core** as `app/Modules/Finance/Accounting`: migrations for
`fin_accounts` / `fin_journals` / `fin_journal_lines` / `fin_fiscal_periods`, the
`FinancialEvent` DTO, the `Ledger` service (post + reverse + idempotency +
balancing + period guard), an SA-SME CoA seeder, a manual-journal UI, and the
Trial Balance report — with the double-entry engine unit-tested.
