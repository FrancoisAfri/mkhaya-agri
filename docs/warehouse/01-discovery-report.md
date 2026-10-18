# 1. Existing-system discovery report

What Mkhaya Hub already had that the warehouse reuses, and what had to be added. The rule throughout: **integrate, don't rebuild** — no second product master, no parallel stock tables, no second ledger.

## Reused as the authority

| Need | Existing authority | How WHMI uses it |
|---|---|---|
| Stock ledger, balances, positions, lots, reservations | Finance `StockLedger` / `InventoryService`; tables `fin_stock_movements`, `fin_stock_balances`, `fin_stock_positions`, `fin_stock_lots`, `fin_stock_reservations`, `fin_stock_layers` | Every stock change goes through `StockLedger`. WHMI added movement types (`dispatch`, `cust_return`, `to_supplier`), guards (frozen bins, serial single unit, warehouse staff, bin-of-warehouse) and metadata, never its own ledger. |
| Products | `fin_stock_items` (Finance) with `mfg_items` (Manufacturing profile) and `mfg_uoms` | WHMI adds packaging, barcodes, identifiers and a warehouse profile (`wms_item_*`) keyed to the same item. |
| Warehouses and locations | `fin_warehouses`, `fin_stock_bins` | Extended with type, timezone, calendar, manager, policies, `is_wms_managed`; bins with hierarchy, kind, capacity, status. An optional link to a SHEQ site syncs timezone. |
| Purchasing and receiving | Procurement purchase orders and goods-received notes (`purchase_orders`, `goods_received_notes`, `goods_received_items`) | WHMI receives against Procurement's GRN; `GrnStockPoster` posts the stock through the ledger and accrues GRNI. Purchase requests from replenishment go to Procurement. |
| Sales | Finance sales orders and delivery notes | Fulfilment orders are created from sales orders; dispatch records Finance's delivery. Once a warehouse runs WHMI, dispatch (not the invoice) relieves stock. |
| Accounting | Finance `Ledger`, `AccountMap`, fiscal periods | Postings go through Finance with idempotency keys; account roles resolve through the account map (module `warehouse`, accounts 5620, 5630, 2160 added). |
| Quality, recalls, losses, traceability | Manufacturing `QualityControl`, `Recalls`, `LossFinding`/`LossRules`, `Traceability` | Warehouse inspections, recalls, profit-protection findings and genealogy use these engines with warehouse stages and categories. |
| Integrations | Manufacturing `IntegrationHub` (connections, mappings, idempotent messages, retries, dead letters) | Warehouse ERP messages register on the hub (`handles()`, `exportsWith()`), not a second framework. |
| Supplier performance | Supplier Management | Late deliveries flag the supplier there. |
| People and hours | Users, `hr_people`, `PicksTenantPeople`, Attendance `attendance_records` | Pickers, staff assignment, and labour hours for the receiving-throughput KPI. |
| Files | Database-backed storage (`tenant_files`, encrypted) and `MalwareScanner` | Evidence, PODs, receipt attachments and export files; every upload is scanned. |
| Notifications, audit trail, tenancy, API auth | Laravel database notifications, `notification_logs`, Spatie activity log, `BelongsToCompany` + Postgres RLS, Sanctum | Alerts, sensitive-download log, tenant isolation, API tokens with abilities. |

## Added by WHMI

Tables prefixed `wms_` (see [generated/data-model.md](generated/data-model.md)): the warehouse's own processes (put-away, picking, packing, dispatch, transfers, counts, returns, adjustments with approval), intelligence (forecasts, risk findings, slotting, simulations, assistant), integration (outbox, webhooks, carriers, idempotency keys, offline actions), governance (audit ledger, approval policies and steps, delegations, overrides, policy versions, settings, report runs, KPI snapshots) and operations (heartbeats).

## Deliberately not built

- A second product master, stock table or ledger.
- Live carrier APIs (a framework with a manual carrier; a driver is added per contracted carrier).
- A graphical warehouse map.
- Translations beyond English.
- EPCIS repository conformance (a mapping, see [04-api-contract.md](04-api-contract.md)).

## Gaps found in existing code and fixed on the way

- Unscoped raw queries that could reach another company's rows (report downloads, label templates and printers, slotting, assistant drafts, task reassignment) — found by the tenant-isolation sweep, fixed.
- Warehouse policy keys read by services but silently dropped when saved — fixed with a policy schema.
- `StockHistory::reconcile` counted status changes as stock and reported false differences — fixed.
- The ledger accepted a bin from another warehouse — now refused.
- Receipts against the same order were not serialised — the order row is now locked.
