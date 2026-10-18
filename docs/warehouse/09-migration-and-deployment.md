# 9. Migration and deployment plan

## What a deployment contains

- 40 migrations in `database/migrations/warehouse` (loaded by `WarehouseServiceProvider`), run in filename order with the rest of the platform. They depend on Finance's stock migrations (`database/migrations/finance/2026_10_13_160000_…`, `2026_10_16_100000_…`) and Manufacturing's quality, recall, loss and integration tables.
- Permission and module rows through `WarehouseModuleSeeder` (idempotent; re-run by the `*_register_*` migrations and installing the role templates without overwriting changed ones).
- Front-end pages under `resources/js/Modules/Warehouse` (one Vite build), with `@zxing/browser` and `@zxing/library` added.
- Scheduled commands registered by the provider (see [generated/schedule-and-policies.md](generated/schedule-and-policies.md)); a queue worker must run for exports, notifications and per-company jobs.

## Staged rollout (feature flags)

The module is switched on at three levels, which is the staged-deployment control:

1. **Module entitlement per company** (`company_modules`, module `warehouse_management`, plus `security_modules`): no entitlement, no warehouse screens, routes or API.
2. **Per warehouse** (`fin_warehouses.is_wms_managed`): a company can run WHMI in one warehouse while the others stay on plain Finance stock. Only managed warehouses publish events, use dispatch-relief, and appear in warehouse dashboards.
3. **Per person**: permissions (role templates) and, optionally, `restrict_to_staff`.

Recommended order for a customer: one pilot warehouse with receiving and put-away → add counting → add fulfilment and dispatch → accounting rules approved by Finance → intelligence features → integrations.

## Staging

1. Restore a recent production backup to staging.
2. `php artisan migrate --force`; check `php artisan migrate:status` shows every warehouse migration.
3. `php artisan wms:install-roles` (only if the seeder did not run).
4. `php artisan wms:verify-ledger`, `wms:verify-audit`, `wms:verify-restore`, `wms:health` — all green before go-live.
5. `php artisan wms:qa-evidence` against a staging test database; `wms:benchmark` on the staging copy (never on production — it writes a company the immutable ledger cannot delete).
6. UAT with warehouse and finance staff on the pilot warehouse.

## Data migration

WHMI does not copy stock from elsewhere: Finance's ledger already holds it. What a go-live needs:

| Data | How |
|---|---|
| Locations | Created on the Locations screen, singly or in runs (the generator makes a run of aisles, shelves or bins under a parent); there is no CSV import yet |
| Opening positions by bin and lot | A stocktake: open a wall-to-wall count for the warehouse, count, post — variances become adjustments through the approval rules, so the opening balance is evidenced |
| Product packaging, barcodes, GTINs | Products screen (or Finance import for the items themselves) |
| Legacy system history (e.g. abs-intranet) | Not migrated as movements; kept read-only in the old system. Its closing quantities are reconciled against the opening count |
| Open purchase orders, sales orders | Already in Procurement and Finance; they appear as expected receipts and fulfilment demand |

## Rollback

- Every warehouse migration has a `down()`. Rolling back drops WHMI tables; **Finance's stock ledger and anything posted through it stay**, by design: posted stock movements and journals are financial records and are corrected by reversal, not by deleting rows.
- Prefer switching off over rolling back: set `is_wms_managed = false` on a warehouse (stock relief returns to invoices) or remove the company's entitlement. Data stays for when it is switched back on.
- Code rollback is a normal redeploy of the previous release; the migrations added since then must be rolled back first (`php artisan migrate:rollback --step=N`) on staging to prove the path.

## Production verification after deployment

1. `php artisan migrate:status` — no pending warehouse migrations.
2. `php artisan wms:health` — no red checks after the first scheduler cycle (heartbeats appear as commands run).
3. `php artisan wms:verify-ledger` and `wms:verify-audit` — exit 0.
4. Open `/warehouse` as a warehouse manager and as a viewer; the hub cards match their permissions.
5. Post one receipt and one dispatch in the pilot warehouse; check the journal in Finance and the event in the outbox (`/warehouse/integrations/events`).
