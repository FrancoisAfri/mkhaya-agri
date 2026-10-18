# Events and alerts

Generated Mon, Oct 12, 2026 11:55 PM by `php artisan wms:handover-docs` -- do not edit by hand; regenerate.

## Published events (outbox, API feed, webhooks)

| Type | Version | Meaning | Fields |
|---|---|---|---|
| `warehouse.receipt.posted` | 1 | Stock received into a warehouse: a purchase receipt, production output or a customer return. | movement, movement_type, item, warehouse, qty, lot, status, reference, occurred_at |
| `warehouse.stock.moved` | 1 | Any stock movement in the ledger: issue, transfer, adjustment, status change, relot, dispatch. | movement, movement_type, item, warehouse, qty, lot, status, to_status, reason_code, reference, occurred_at |
| `warehouse.reservation.changed` | 1 | Stock reserved for demand, consumed, or released. | reservation, item, warehouse, qty, qty_consumed, status, source_type |
| `warehouse.shipment.dispatched` | 1 | Packages left the warehouse. | shipment, order, external_ref, carrier, service, tracking_reference, packages, dispatched_at |
| `warehouse.shipment.tracking` | 1 | A carrier or a person recorded what happened to a shipment. | shipment, status, occurred_at, location, source |
| `warehouse.shipment.delivered` | 1 | Proof of delivery was recorded. | shipment, order, external_ref, received_by, delivered_at |
| `warehouse.return.status_changed` | 1 | A customer return moved on: authorised, received, inspected, completed or cancelled. | return, status, customer, reference |
| `warehouse.exception.raised` | 1 | An inventory exception: a loss risk, variance or control failure found in a warehouse. | finding, category, priority, warehouse, item, summary |

## Alerts

| Alert | Told by default | Thresholds | Escalates after | To |
|---|---|---|---|---|
| LATE_DELIVERY | wms.receive | {"days_late":1} | 72 h | wms.supervise |
| LOW_STOCK | wms.plan | {"days_short":0} | — |  |
| EXPIRY | wms.supervise | [] | — |  |
| RECALL | wms.recall | [] | 24 h | wms.supervise |
| TASK_FAILED | wms.supervise | [] | 8 h | wms.warehouse.manage |
| DISCREPANCY | wms.receive.exceptions | {"count_value":0} | 72 h | wms.supervise |
| APPROVAL | whoever may decide | [] | — |  |
| ORDER_AT_RISK | wms.supervise | [] | 2 h | wms.order.manage |
| INVESTIGATION | wms.investigate | {"open_days":14} | 168 h | wms.protection.manage |
| INTEGRATION | wms.integrations.manage | [] | 24 h | wms.supervise |
| OVERRIDE | wms.warehouse.manage | [] | 24 h | wms.warehouse.manage |
