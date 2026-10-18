# Tax Management — administration and deployment

For the developers and administrators who deploy and run the module (SRS §27 deliverable 12).

## What ships

| Migration | Creates |
|---|---|
| `finance/2026_10_31_110000_tax_management_foundation` | tax types, registrations (+versions), rule versions, periods (+events), register, reconciliations (+items), deadlines, append-only audit events with an UPDATE/DELETE trigger |
| `finance/2026_10_31_110100_register_tax_management_module` | module, ribbons, `tax.*` permissions, six starter roles, sellable add-on (via `TaxModuleSeeder`) |
| `finance/2026_11_01_100000_tax_returns_liabilities_and_payments` | return forms (VAT201, EMP201 v1), returns, lines, sources, liabilities, payment allocations; ETI tax type |
| `finance/2026_11_02_100000_tax_income_tax_and_provisional` | account classifications, computations, lines, carry-forwards |
| `finance/2026_11_03_100000_tax_import_batches` | import batches; `import_batch_id` on imported records |
| `finance/2026_11_04_100000_tax_settings` | each company's tax settings |
| `finance/2026_11_05_100000_tax_event_outbox` | the tax event outbox (§19) |

Every tenant table has `company_id` under the same `tenant_isolation` row-level-security policy as Finance.
Migrations are additive; `down()` exists but dropping tax tables destroys tax history — never run it in production.

## Deploying

The normal deploy (`cd.yml`) runs `migrate`. After the first deploy that includes the module, run on the server:

```bash
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
$PHP artisan migrate --force
$PHP artisan hubs:sync tax_management     # the menu ribbons
$PHP artisan cache:clear
```

Then, per tenant: enable the `tax_management` add-on, give people the tax roles (Roles & permissions), and have
them set up registrations and rules (user guide §1). Nothing is active for a company until a registration is
confirmed.

## Scheduled work

`tax:compliance` — daily at 06:20 (`routes/console.php`), inline per tenant (`RunsPerTenant`); there is no queue
worker on the production host, so nothing here is queued. Each run:

1. generates the coming periods (config `tax.generate_months_ahead`) and their deadlines for confirmed registrations;
2. sends deadline reminders (14/7/3/1 days) and escalates newly overdue deadlines once;
3. chases submissions still pending or unknown after two days, once a day.

Deadlines that had already passed when a registration was confirmed are recorded as waived history, so
back-dated or imported registrations do not trigger a flood of escalations.

Log: `storage/logs/tax-compliance.log`. Manual run: `php artisan tax:compliance [--company=ID]`.

`tax:events` — every five minutes: delivers the tax event outbox to listeners (`TaxEventOccurred`). Events are
written in the same transaction as the change they describe, de-duplicated by name and payload, carry
identifiers only, and are retried with backoff up to five times; failures show on Tax administration with a
Retry button. Listeners must be idempotent (delivery is at least once). Log: `storage/logs/tax-events.log`.

## Configuration

Each company sets its own values under **Tax Setup → Tax administration** (stored in `tax_settings`, every change
audited with its reason). `config/tax.php` supplies the defaults:

| Key | Default | Meaning |
|---|---|---|
| `reconciliation_tolerance` | 1.00 | Rand difference above which a reconciliation item must be explained |
| `reminder_days` | 14, 7, 3, 1 | Reminder offsets for new deadlines |
| `generate_months_ahead` | 1 | How far ahead periods are generated |
| `assessed_loss.cap_amount` / `cap_percent` | 1 000 000 / 80 | Assessed-loss set-off limit |
| `provisional_first_share` | 50 | Share of the year's tax due with the first IRP6 |

Also used: `finance.vat_output` / `finance.vat_input` (VAT control account codes) and the payroll account map
(`AccountMap` keys `payroll.paye`, `payroll.uif`, `payroll.sdl`).

Due-date rules live in `tax_types.filing_due_rule` / `payment_due_rule` (data, not code). Public holidays are not
applied; adjust a deadline on the calendar with a reason. Return field mappings live in `tax_return_forms`; a SARS
form change is a new row with a new version, never an edit.

## Security notes

- Every action checks its own `tax.*` permission in the controller; the services enforce segregation of duties
  regardless of permissions. Tax routes are exempt from `EnforceCrudPermissions`' derived names (as Manufacturing
  and Warehouse are); `TaxAccessAndComplianceTest` holds every route to refusing a user without tax permissions.
- Record parameters are constrained to UUIDs.
- Denied privileged actions are written to `tax_audit_events`; the table is append-only at the database and
  hash-chained per tenant (`Verify chain` on the audit screen, or `TaxAudit::verifyChain`).
- Exports neutralise spreadsheet formulas and are logged. Evidence is stored through Document Management
  (type guard, malware scan, quotas), never in a tax file store.
- No bank payment and no SARS submission is made by the module. Submission is recorded from evidence.

## Known limitations (to confirm with NSEZ before reliance)

- No verified SARS electronic-submission interface is configured; returns are captured on eFiling from the
  capture sheet and recorded with their SARS reference (SRS §11 allows a controlled manual process).
- The company payroll engine does not post SDL or the employer's UIF to the ledger; the payroll reconciliation
  reports them as missing on the ledger side until Payroll accrues them.
- Employer UIF is taken as equal to the employee's contribution and SDL as gross × the tax year's rate, as the
  existing EMP201 does; payroll does not store either figure.
- VAT201 v1 mapping, CIT rates, due-date rules and the assessed-loss limit are engineering defaults to be
  confirmed by NSEZ's tax representatives.

## Tests

`php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Tax` — the SRS §25 acceptance matrix (TAX-001 to
TAX-022 where in scope) plus access, reporting and import tests. They need the test database migrated
(`DB_DATABASE=mkhaya_hub_test php artisan migrate`).
