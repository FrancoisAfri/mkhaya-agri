# Tax Management & Compliance — gap assessment and architecture

SRS: *Mkhaya Hub — Tax Management & Compliance Module, v1.0, 14 October 2026* (NSEZ initial use case).
This document is deliverables 1 and 2 of §27: the codebase audit the SRS requires before any table is
created (§2, §28), and the architecture and data model that follow from it.

**Decision.** Tax Management is a new module, `tax_management`, in the **Finance** category, at
`/finance/tax`. It is a layer over what Finance and Payroll already do. It reads their records; it
never recalculates what they own and never posts around them.

## 1. What already exists

| Capability | Where | Status for the tax module |
|---|---|---|
| Legal entities | `fin_entities`, `BelongsToEntity`, `EntityScope`, `CurrentEntity` (`app/Modules/Finance/Accounting`) | **Reuse.** Every tax record carries `company_id` + `entity_id`. |
| Tenancy | `BelongsToCompany`, `CompanyScope`, Postgres RLS `tenant_isolation` policy per table | **Reuse.** Every tax table gets the same policy. |
| Ledger | `fin_journals` / `fin_journal_lines` (hash-chained), `FinancePoster::emit` → `Ledger::post` with idempotency keys | **Reuse, read-only in Phase 1.** GL stays authoritative (§2.2). |
| Fiscal periods | `fin_fiscal_years`, `fin_fiscal_periods`, `PeriodService` (soft/hard close, lock) | **Reuse for "GL period locked" (§24).** Tax periods are separate (TAX-PER-010). |
| Tax codes | `fin_tax_codes` (code, rate, type output/input/none/wht, control `account_id`); used on `fin_invoice_lines.tax_code/tax_rate/tax_amount`, `fin_expenses.tax_amount` | **Keep as Finance's posting codes.** The tax module adds effective-dated, versioned *rules* keyed by these codes rather than a second code table. |
| AR / AP documents | `fin_invoices` (direction receivable/payable, `doc_type` invoice/credit_note, `applies_to_invoice_id`, currency), `fin_invoice_lines`, `fin_expenses`, `fin_payments`, `fin_payment_allocations` | **Source of the VAT register** (TAX-VAT-001..005). |
| VAT return | `Vat201Service` + `TaxService::vat201()` | **Superseded for the register, kept for continuity.** It derives standard-rated supplies as output tax ÷ 15 %, so zero-rated and exempt supplies are invisible. The register replaces that derivation; existing `sars_submissions` rows remain readable. |
| SARS profile | `sars_tax_configs` — one row per *company* | **Gap.** Registrations must be per legal entity, per tax type, effective-dated (TAX-REG-001/002). Migrated into `tax_registrations`; the old row is left in place for `Vat201Service`. |
| Submission register | `sars_submissions`, `sars_submission_events`, `SubmissionLifecycle` (draft→generated→validated→submitted→accepted/rejected) | **Reuse in Phase 2/4** for returns and submissions; extend with `unknown`, `pending`, `amended` (TAX-SUB-006, -010). |
| Payroll taxes | `PayrollTaxEngine` (PAYE/UIF/SDL/ETI), `sa_tax_years` versioned by tax year, `Emp201Service`, `Emp501Service`, `Ui19Service`, `TaxCertificateService` | **Reuse (Phase 2).** Payroll's calculation is authoritative (§7.1): the tax module consumes approved run totals and reconciles; it does not recalculate PAYE. |
| Approvals | No shared engine. Closest: `Finance/Shared/Services/ApprovalWorkflowService` (module + entity type + id). Budget, Payroll, DMS each have their own. | **Gap.** Tax uses a small, explicit maker-checker in its own services with segregation of duties enforced in code (preparer ≠ reviewer ≠ approver). |
| Documents | DMS `HasDocuments` trait → polymorphic `DocumentLink`; `RetentionService`, `LegalHoldService` | **Reuse.** Tax models use `HasDocuments`; no tax file store (§15). |
| Audit | Spatie activitylog; `FinanceActivityLogger`; `SarsSubmissionEvent` | **Partial.** Tax adds `tax_audit_events`: append-only, hash-chained, written in the same DB transaction as the change (§17). |
| Scheduler | `routes/console.php`; deadline pattern in `CheckWspAtrDeadline`; `RunsPerTenant` | **Reuse** for the compliance calendar. Run with `--sync`: production has no queue worker. |
| Module registration | `WarehouseModuleSeeder` pattern: Spatie permissions, `security_modules` row + ribbons from a `ModuleHubs` class, `billing_modules` row, `module.active` / `module.tier` middleware | **Reuse.** |
| Name collision | `app/Modules/Compliance` is POPIA | Module code is `tax_management`, routes `finance/tax/*`. |

## 2. Gaps the module fills

1. Per-entity, per-tax-type, effective-dated **registrations and obligations** with responsible officers and change history.
2. **Versioned, effective-dated tax rules** with draft → approve → publish, overlap detection and immutability after publication.
3. One **calculation service** with decimal arithmetic and a structured, reproducible result.
4. A **line-level VAT register** built idempotently from Finance documents, with exceptions (missing code, code/rate mismatch, changed source).
5. **Tax periods** independent of fiscal periods, with an explicit lifecycle, lock and audited reopen.
6. **Reconciliation** runs (register ↔ GL control accounts first), versioned and immutable once signed off.
7. **Compliance calendar**: filing and payment deadlines per obligation, reminders and escalation.
8. **Tax audit trail** and **dashboard**.
9. Phase 2+: returns workflow over `sars_submissions`, payroll tax consumption and reconciliation, liabilities and payment allocation, income tax and provisional tax, imports.

## 3. Data model (Phase 1)

All tables: `id`, `uuid`, `company_id` (RLS), `entity_id` where the record belongs to a legal entity,
`created_by`/`updated_by`, timestamps (`timestampTz`), money as `NUMERIC(18,2)`, rates as
`NUMERIC(9,6)`. Names follow §18 where the entity is genuinely new.

| Table | Purpose | Key constraints |
|---|---|---|
| `tax_types` | Global catalogue: VAT, PAYE, UIF, SDL, CIT, PROV, WHT… and whether each has a return, a payment, a period structure | `code` unique |
| `tax_registrations` | Entity × tax type registration: reference, effective from/to, filing frequency, period anchor, preparer, reviewer, authority, status | one *active* registration per entity × type for overlapping dates (enforced in service) |
| `tax_registration_versions` | Every change: snapshot, reason, effective date, author | append-only |
| `tax_rule_versions` | Effective-dated rule for a tax code (rate, treatment, method, rounding, input/output, control account, SARS return field), with lifecycle draft → pending_approval → published → retired | published rows immutable; publishing refuses overlapping effective dates for the same code + entity scope |
| `tax_periods` | Registration × period: start, end, filing due, payment due, status (open, in_preparation, under_review, approved, submitted, accepted, rejected, closed, locked) | unique (registration, start) |
| `tax_period_events` | Status changes, reopen reasons | append-only |
| `tax_transactions` | VAT register line: source module / table / id / line id, source hash, document date, direction, tax code, rule version, taxable base, tax, currency + fx, classification, exception flags, superseded_by | unique (`company_id`, `source_key`, `version`) — the idempotency key |
| `tax_reconciliations` | Run: type, period, cutoff, status (draft, completed, signed_off, reopened), totals, version, supersedes | a new run never overwrites a signed-off one |
| `tax_reconciliation_items` | Item: side, reference, amounts, match state, difference class (timing, rounding, missing, genuine), explanation | |
| `tax_compliance_deadlines` | Filing / payment deadline per period or manual: due date, owner, reviewer, escalation contact, status, reminder log | |
| `tax_audit_events` | Append-only audit with `previous_hash` / `hash` | no update/delete via app; DB trigger refuses UPDATE/DELETE |

## 4. Services

- `TaxCalculator` — pure, `bcmath`; input: base, inclusive/exclusive, rule version; output: the §5.3 structure. Distinguishes *zero* from *not calculated*.
- `TaxRuleService` — draft, submit, approve (≠ author), publish (overlap check), retire; resolves the rule in force for code + entity + date.
- `TaxRegistrationService` — create/update with versioning and reason; completeness check (TAX-REG-004).
- `TaxPeriodService` — generates periods from frequency + anchor; transitions with guards; lock/reopen with reason and permission.
- `VatRegisterService` — builds/refreshes the register for an entity + period from `fin_invoices` (invoices and credit notes, both directions) and `fin_expenses`; idempotent by `source_key`; a changed source produces a new version and marks the old one superseded (TAX-VAT-005, §24).
- `TaxReconciliationService` — VAT register ↔ GL control accounts (output and input) for a period; classifies differences; tolerance; sign-off by someone other than the preparer.
- `ComplianceCalendarService` + `tax:deadlines` command — creates deadlines from periods; reminders at configured offsets; overdue escalation.
- `TaxAudit` — writes `tax_audit_events` inside the caller's transaction.

## 5. Permissions (§16)

`tax.view`, `tax.registration.manage`, `tax.rule.draft`, `tax.rule.approve`, `tax.period.manage`,
`tax.period.reopen`, `tax.prepare`, `tax.review`, `tax.approve`, `tax.reconcile`, `tax.calendar.manage`,
`tax.audit.view`, `tax.payroll.view` (employee-level payroll tax, Phase 2), `tax.admin`.
Roles are configured from these, not hard-coded. Segregation of duties is enforced in services:
a rule's author cannot approve it, a reconciliation's preparer cannot sign it off.

## 6. Phase plan as built

- **Phase 1 (built):** module registration, permissions, registrations, rules + calculator, periods, VAT register, VAT ↔ GL reconciliation, compliance calendar + reminders, audit trail, dashboard.
- **Business list:** with Accounting enabled for the company (plan in SaaS, licence when self-hosted) the
  businesses are Accounting's; without it, the company itself (its default business row). Tax records
  carry `entity_id` but do not take Accounting's session filter; every list filters by business instead.
- **Phase 2 (built):**
  - `tax_return_forms` — VAT201 and EMP201 field mappings and evidence checklists as versioned data.
  - `tax_returns` / `tax_return_lines` / `tax_return_sources` — prepared from the period's register; every
    field records its source lines and their versions; adjustments keep the calculated value and a reason;
    preparer, reviewer and approver must be three people; amendments supersede, never overwrite.
  - Submission is recorded in the existing `sars_submissions` register through `SubmissionStatusService`
    (one submission history for all SARS returns). `submitted` needs the SARS reference; `accepted` needs
    the receipt attached; `pending` and `unknown` record an attempt without claiming it arrived.
  - Payroll: released `payroll_runs` / finalised `payslips` imported per employee per tax into
    `tax_transactions` (PAYE, UIF, SDL, ETI), PAYE as payroll calculated it. Two derivations, as the existing
    EMP201 makes them: employer UIF = employee UIF; SDL = gross × tax-year SDL rate, only with a confirmed
    SDL registration. Employee-level figures need `tax.payroll.view`.
  - Payroll ↔ ledger reconciliation per run (accounts from `AccountMap`: PAYE 2300, UIF 2310, SDL 2320).
    **Finding:** the company payroll engine posts PAYE and employee UIF but not SDL or employer UIF, so those
    show as missing on the ledger side until Payroll accrues them.
  - `tax_liabilities` from approved returns (amendments add only the difference) and recorded assessments;
    `tax_payment_allocations` apply payments recorded in Finance (`fin_payments`); reversed, never deleted.
  - Evidence is uploaded through Document Management (`DocumentService::store`: type guard, malware scan,
    quotas) and linked to the return; approval and acceptance require their checklist items.
- **Phase 3 (built):** corporate income tax computation from the ledger (classifications, adjustments,
  allowances, assessed-loss set-off within the configured limit, provisional credits), provisional tax (IRP6)
  estimates, year position against the SARS assessment, opening balances, historical import (§23) with
  second-person sign-off and `unverified` history.
- **Phase 4 (in part):** the 18 standard reports (§20.2) with §20.3 headings; eFiling capture sheet (TAX-SUB-002);
  notifications and daily chasing of failed, rejected and unconfirmed submissions (TAX-SUB-008); no blind
  re-sending after an unknown outcome (§24); calendar milestones closed by submissions and payments.
  **Not built:** direct electronic submission to SARS — to be scoped once interface availability and terms are
  confirmed (SRS §11) — an external REST API (§19) until a consumer exists. Events are published through a durable outbox (`tax_events`).

User guide: `02-user-guide.md`. Administration and deployment: `03-administration-and-deployment.md`.

## 7. Boundaries carried from the SRS

- No screen states or implies that a return was submitted to or accepted by SARS without recorded evidence (§1, TAX-SUB-010).
- Rates and codes are configuration; NSEZ's tax professionals must approve each treatment before reliance (§28).
- No bank payment is executed by this module (§13).
