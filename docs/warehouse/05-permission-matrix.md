# 5. Permission matrix

Who may view, create, post, approve, reverse and export each transaction type. The complete list of permissions, the role templates and the check on every single route are generated from the code: [generated/permissions.md](generated/permissions.md).

Beyond the permission, services enforce: segregation of duties (never one's own approval, a different person per approval level, database four-eyes constraints), approval levels by value, warehouse staff restrictions (`restrict_to_staff`), client visibility (3PL), and money hidden without `wms.accounting`.

| Transaction | View | Create | Post | Approve | Reverse / correct | Export |
|---|---|---|---|---|---|---|
| Goods receipt | wms.view | wms.receive | on receipt | wms.receive.exceptions (no-order receipts, discrepancies, repeated delivery notes) | supplier return (wms.supplier_return); Finance reversal | wms.view (movements report) |
| Put-away | wms.view | system / wms.move | wms.move | wms.putaway.override (off the suggestion in a directed warehouse) | opposite move | wms.view |
| Bin move | wms.view | wms.move | wms.move | — | opposite move | wms.view |
| Transfer between warehouses | wms.view | wms.move | wms.move | wms.transfer.approve | recall / cancel | wms.view |
| Replenishment | wms.view | system / wms.replenishment.manage | wms.move | — | — | — |
| Fulfilment order | wms.view | wms.order.manage (also API wms:orders) | — | — | cancel: wms.order.manage | wms.view |
| Pick / pack / dispatch | wms.view | wms.pick / wms.pack / wms.dispatch | same | quality gate decisions: wms.quality.manage | customer return | wms.view |
| Stock adjustment | wms.view | wms.stock.adjust | at once below every limit | wms.stock.approve and every level of the approval policy | Finance reversal | wms.view (values: wms.accounting) |
| Stocktake | wms.view | wms.count.manage (plans), wms.count (entries) | wms.count.manage | through adjustments | — | wms.view |
| Customer return | wms.view | wms.returns | wms.returns | wms.returns.credit (credit note) | — | wms.view |
| Supplier return | wms.view | wms.supplier_return | wms.supplier_return | — | — | wms.view |
| Inspection / quality | wms.view | wms.quality | wms.quality | wms.quality.manage | — | — |
| Recall | wms.view | wms.recall | wms.recall | — | close: wms.recall | — |
| Revaluation | wms.accounting | wms.accounting | on approval | wms.accounting.approve + policy levels | new revaluation | wms.accounting |
| Landed cost | wms.accounting | wms.accounting | wms.accounting.approve | wms.accounting.approve | — | wms.accounting |
| Loss finding / investigation | wms.investigate | wms.investigate | — | wms.loss.approve (write-off), wms.protection.manage | — | wms.investigate (sensitive, audited) |
| Slotting change | wms.view | wms.supervise | on approval | wms.slotting.approve (not the proposer) | — | — |
| Emergency override | wms.override / managers | wms.override | — | review: wms.warehouse.manage (another person) | close early: its owner | audit export |
| Warehouse policies | wms.view | wms.warehouse.manage (draft) | wms.warehouse.manage (activate) | — | restore earlier version | — |
| Alert rules, approval policies, retention | wms.view | wms.warehouse.manage | — | — | — | — |
| Carriers, webhooks, connections | wms.view | wms.integrations.manage | — | integration validation recorded | — | — |
| Audit ledger | wms.audit.view | system | — | — | never | wms.audit.view (export audited) |

## Where the limits live

- **By company:** each company grants roles to its people; role templates are platform-wide starting points.
- **By warehouse:** `restrict_to_staff` and the warehouse staff list; holders of `wms.warehouses.all` work anywhere.
- **By action and transaction type:** the permission per action above.
- **By approval limit:** reason-code thresholds (quantity, value, always) and approval policies (levels by value, deadlines, escalation, delegation).
