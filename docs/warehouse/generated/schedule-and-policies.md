# Scheduled work and policies

Generated Mon, Oct 12, 2026 11:55 PM by `php artisan wms:handover-docs` -- do not edit by hand; regenerate.

## Scheduled commands

| Command | Expected every | What it does |
|---|---|---|
| `wms:outbox` | 1 min | Deliver warehouse events, pull carrier tracking, prune delivered events |
| `wms:replenish` | 30 min | Create replenishment tasks and transfer orders for pick faces and warehouses below their minimum |
| `wms:fulfilment` | 10 min | Re-check allocations, allocate waiting orders and flag orders at risk of missing their SLA |
| `wms:notify` | 15 min | Warehouse notifications and approval escalation |
| `wms:counts` | 1 day(s) | Open the stock counts whose plans are due, and refresh ABC classes monthly |
| `wms:expiry` | 1 day(s) | Warn about lots crossing their expiry warning points |
| `wms:reconcile` | 1 day(s) | Reconcile the stock ledger with itself and with the general ledger, and queue what disagrees |
| `wms:protect` | 1 day(s) | Run the warehouse loss detectors and escalate overdue investigations |
| `wms:retention` | 1 day(s) | Apply warehouse retention periods and anonymise personal information past its time |
| `wms:verify-audit` | 1 day(s) | Verify the warehouse audit ledger hash chain |
| `wms:verify-ledger` | 1 day(s) | Check stock balances, positions and on-hand against the stock ledger |
| `wms:kpi-snapshot` | 31 day(s) | Store the month's warehouse KPI figures |

## Warehouse policies

| Policy | Kind | Allowed | Default |
|---|---|---|---|
| `rotation` | enum | FEFO, FIFO, LIFO, NONE | "FEFO" |
| `allocation` | enum | FEFO, FIFO, LIFO, NONE | "FEFO" |
| `receiving_requires_inspection` | bool | on / off | false |
| `blind_receiving` | bool | on / off | false |
| `putaway` | enum | SUGGESTED, DIRECTED, FREE | "SUGGESTED" |
| `picking` | enum | DISCRETE, BATCH, ZONE, WAVE, CLUSTER | "DISCRETE" |
| `scan_to_confirm` | bool | on / off | true |
| `replenishment` | enum | MIN_MAX, REORDER_POINT, NONE | "MIN_MAX" |
| `dispatch_requires_pack_check` | bool | on / off | true |
| `returns_to_quarantine` | bool | on / off | true |
| `receipt_over_tolerance_pct` | number | 0 to 100 | 0 |
| `max_backdate_days` | int | 0 to 365 | 7 |
| `sla_dispatch_hours` | int | 1 to 720 | 24 |
| `sla_risk_minutes` | int | 5 to 10080 | 60 |
| `returns_require_inspection` | bool | on / off | true |
| `expiry_alert_days` | days_list | list of days 1–3650 | null |
| `carrying_cost_pct` | number | 0 to 100 or empty | null |
| `shift_hours` | number | 1 to 24 | 8 |
| `utilisation` | number | 0.1 to 1 | 0.85 |
| `metres_per_slot` | number | 0.1 to 50 | 1.5 |
| `batch_size` | int | 1 to 100 | 8 |
| `putaway_hours` | int | 1 to 168 | 4 |
| `gln` | gln | 13-digit GLN | null |
| `restrict_to_staff` | bool | on / off | false |
