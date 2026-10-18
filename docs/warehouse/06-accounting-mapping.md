# 6. Accounting mapping

**Status: requires Finance approval before production.** The treatments below are implemented and tested; the specification asks Finance to approve the valuation method and who owns each accounting event.

The default rule for every warehouse event, with owner and accounts, is generated from the code: [generated/accounting-events.md](generated/accounting-events.md). Each company's own copy is in `wms_accounting_rules`, viewed and changed (with approval) on the warehouse Accounting screen; a rule can be **suspended**, after which the event is refused rather than posted wrongly.

## Principles

1. **One ledger.** All postings go through Finance's `Ledger` with an idempotency key per event; WHMI never writes journals itself.
2. **Valuation** is Finance's: each item's cost method (`weighted_avg`, `fifo`, `standard`) on `fin_stock_items`. WHMI adds landed costs and revaluations, both posted only after a second person approves.
3. **Stock leaves at dispatch** in warehouses run by WHMI. Finance's invoice relief is skipped there (`INVOICE_RELIEF` = NONE), so a sale is costed once.
4. **Receipts accrue GRNI** (Dr inventory, Cr 2150 Goods Received Not Invoiced); the supplier invoice clears it in Finance.
5. **Moves and status changes carry no value** (transfers, bin moves, quarantine, release).
6. **Client-owned stock (3PL) is never valued.**
7. **Fiscal periods are Finance's.** A closed period refuses the posting, and the stock does not move either (QA-013).

## Accounts added to the chart (module `warehouse`)

| Code | Name | Used for |
|---|---|---|
| 5620 | Inventory Adjustments | Approved adjustments and count variances |
| 5630 | Inventory Revaluation | Approved revaluations |
| 2160 | Landed Cost Clearing | Freight, duty and other landed costs before the supplier's bill |

Existing accounts used: 1300 inventory (or the item's own inventory account), 1310 work in progress (production), 2150 GRNI, 5000 cost of sales, 5610 Scrap & Spoilage.

## Reconciliation

- `wms:reconcile` (nightly 02:30): stock subledger value against GL inventory accounts, and movements that should have a journal and do not → `wms_accounting_issues` until cleared.
- `wms:verify-ledger` (nightly 01:30): quantity projections against the stock ledger.
- The executive dashboard and stock cost report show inventory value, carrying cost, write-offs, landed and price variance.

## Sign-off

| Decision | Approved by (Finance) | Date | Notes |
|---|---|---|---|
| Valuation method per item category | | | |
| Ownership of each accounting event (table in generated/accounting-events.md) | | | |
| Accounts 5620, 5630, 2160 and their reporting lines | | | |
| Stock relieved at dispatch for WHMI warehouses | | | |
| Landed cost split between inventory and cost of sales | | | |
| Write-off approval limits | | | |
