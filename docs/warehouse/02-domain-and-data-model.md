# 2. Domain and data model

The column-level reference (types, foreign keys, unique keys, checks, triggers, row-level security) is generated from the database: [generated/data-model.md](generated/data-model.md). This document explains the shape and the rules.

## The ledger rule (§25.4)

`fin_stock_movements` is the authoritative history. Everything else that shows a quantity is a projection of it:

| Projection | Granularity | Kept in step by |
|---|---|---|
| `fin_stock_balances` | item × warehouse | `InventoryService::applyBalance`, in the same transaction as the movement |
| `fin_stock_positions` | item × warehouse × bin × lot × status | `InventoryService::applyPosition`, same transaction |
| `fin_stock_items.qty_on_hand` | item | same transaction |
| `fin_stock_reservations` | demand × item × warehouse (× lot) | `StockLedger::reserve` / consume / release |

Status changes and re-lots move stock between positions inside one warehouse and change no balance. `LedgerVerifier` (and `wms:verify-ledger`, nightly) rebuilds the projections from the ledger and reports any difference; it never "fixes" one, because which side is wrong is a person's question.

Posted movements cannot be updated or deleted (database trigger). A correction is a reversal movement or an opposite move. Every movement can carry an idempotency key; a retried posting with the same key returns the first movement.

## Effect of each transaction

| Transaction | Physical on hand | Available | Value |
|---|---|---|---|
| Receipt (GRN) | + at the dock bin, in the receipt's status | + if AVAILABLE; quarantine/inspection held | Dr inventory, Cr GRNI |
| Put-away, bin move | 0 (between bins) | 0 | none |
| Transfer dispatch | − source; + destination's in-transit bin | − at source; in-transit is never available | none |
| Transfer receipt | in-transit → destination bin | + at destination | none |
| Reservation / allocation | 0 | − (reserved) | none |
| Pick | 0 (to packing bin) | already reserved | none |
| Dispatch | − | reservation consumed | Dr cost of sales, Cr inventory |
| Status change (quarantine, hold, release, reject) | 0 | ± | none |
| Adjustment (approved) | ± | ± | Dr/Cr Inventory Adjustments |
| Customer return | + into quarantine | 0 until inspected | Dr inventory, Cr cost of sales |
| Supplier return | − | − | Dr GRNI, Cr inventory |
| Scrap | − | − | Dr Scrap & Spoilage, Cr inventory |
| Reversal | opposite of the original | opposite | opposite journal |

## Tenancy and integrity

- Every tenant row carries `company_id`; tables have forced row-level security with the `tenant_isolation` policy, and models use `BelongsToCompany`. Raw queries filter by company explicitly (`ScopesRawQueries`), because a platform administrator has no company and RLS would then permit every row.
- Platform-wide tables have no `company_id` by design: `wms_heartbeats`.
- Quantities are `numeric(18,4)` (or finer), money `numeric(14,2)`/`(18,4)`; never floating point in the database.
- Timestamps are `timestamptz`, stored as instants; screens and EPCIS show them in the warehouse's timezone (QA-021).
- Unique identifiers are scoped to their company (`unique(company_id, …)`) or global where they must be (uuids, event ids). Where a key includes a nullable column, partial unique indexes are used, because Postgres never treats two NULLs as equal.
- Posted inventory never cascades away when a master record is deactivated: items, bins and warehouses are retired, not deleted, while stock or history refers to them.

## Transaction boundaries

| Unit of work | Boundary |
|---|---|
| One stock movement (and its balance, position, journal, events, audit) | One database transaction in `InventoryService`; an outbox event and audit row are written inside it |
| Multi-line documents (transfer dispatch, receipt, dispatch of a shipment, count posting) | One transaction for the whole document (QA-020) |
| Receiving against a purchase order | Serialised by a row lock on the order (QA-002) |
| Allocation | Positions locked `FOR UPDATE` while reserved (QA-003) |
| Approval decisions | The step, the subject's status and its posting in one transaction |
| External effects (webhooks, carrier calls, ERP messages, e-mail) | Outside the business transaction, from durable records (outbox, integration events, notification log) with retries |

## Main entity groups

- **Configuration:** warehouses (`fin_warehouses`), locations (`fin_stock_bins`), staff assignments (`wms_warehouse_staff`), reason codes, label templates, printers, carriers and rates, policy versions, settings.
- **Product master extensions:** `wms_item_profiles`, `wms_item_packaging`, `wms_item_barcodes`, `wms_item_identifiers`, `wms_item_events`.
- **Inbound:** GRNs (Procurement), `wms_receipt_discrepancies`, `wms_receipt_attachments`, `wms_putaway_tasks`, dock appointments, supplier returns.
- **Internal:** transfers, replenishment rules and tasks, location changes, counts (plans, counts, lines, append-only entries).
- **Fulfilment:** `wms_orders`, lines, allocations, pick waves and tasks, packages and contents, shipments, tracking events.
- **Control:** stock adjustments, approval policies/steps, delegations, overrides, loss findings (Manufacturing), recoveries, evidence.
- **Intelligence and integration:** forecast runs and forecasts, supplier terms, simulations, assistant queries/actions, outbox, webhooks and deliveries, idempotency keys, ERP reconciliations, offline actions.
- **Governance:** audit events (hash-chained), notification rules and log, report runs, KPI snapshots and baselines, heartbeats.

## Retention

| Data | Kept | Then |
|---|---|---|
| Stock ledger, journals | Indefinitely (financial records) | — |
| Audit ledger | Company setting, at least 5 years (default 7) | Purged by `wms:retention` only past the period |
| Personal details on shipments, web-shop orders and returns | Company setting, at least 90 days (default 2 years) | Replaced by `[removed]`; quantities and dates stay |
| Export files | Default 30 days | File deleted, run marked EXPIRED |
| Resolved alerts, settled offline scans | Default 1 year / 180 days | Deleted |
| Delivered events | 90 days | Deleted by `wms:outbox` |

Partitioning is not yet needed at the measured volumes (see [performance-results.md](performance-results.md)); `fin_stock_movements` and `wms_audit_events` are the candidates by `company_id` and month when a tenant's volume makes it so.
