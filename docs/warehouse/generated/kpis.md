# KPI definitions

Generated Mon, Oct 12, 2026 11:55 PM by `php artisan wms:handover-docs` -- do not edit by hand; regenerate.

Definition version 1. Figures are kept per month with this version (`wms_kpi_snapshots`); a changed definition gets a new version and is not compared with the old.

| KPI | Unit | Better | Definition | Excludes |
|---|---|---|---|---|
| Inventory accuracy | % | higher | Count lines whose first count matched the system quantity (within the count tolerance) ÷ count lines counted, for counts posted in the window. | Recounts are not counted again; lines of cancelled counts are excluded. |
| Picking accuracy | % | higher | Pick tasks completed with no short quantity and no wrong scan ÷ pick tasks completed in the window. | Cancelled pick tasks are excluded. |
| Order cycle time | hours | lower | Median hours from an order's release to its dispatch, for orders dispatched in the window. | Cancelled orders and orders never released are excluded. |
| Receiving throughput | lines per labour hour | higher | Accepted receipt lines posted in the window ÷ labour hours of receiving staff. Labour hours are the hours worked (Attendance) by people assigned to the RECEIVE activity, shared equally across each person's assigned activities. | Receipts without an order awaiting approval are excluded; without Attendance records the KPI is not available. |
| On-time dispatch | % | higher | Orders dispatched by their dispatch deadline ÷ orders with a deadline dispatched in the window. | Orders without a deadline are excluded from both sides. |
| Stock-out rate | % | lower | Order lines released in the window that went short for lack of stock ÷ order lines released in the window. | Cancelled lines are excluded; lines filled by an approved substitute count as filled. |
| Write-off rate | % of stock value | lower | Value of confirmed write-offs posted in the window ÷ inventory value at the end of the window. | Stock held for clients (not valued) is excluded; suspected losses not yet confirmed are excluded. |
| Forecast accuracy | % (100 − WAPE) | higher | 100 − weighted absolute percentage error over forecast periods that have ended, comparing forecast (or its override) with actual outbound demand. | Items with insufficient history (no forecast) are excluded. |
| Supplier delivery accuracy | % | higher | Receipts against an order, received on or before the order's expected date with no quantity discrepancy ÷ receipts against an order in the window. | Receipts without an order are excluded. |
| Investigation resolution | days (median) | lower | Median days from detection to closure of stock-risk investigations closed in the window. | Findings outside warehouses are excluded. |
| Pick-face shortages | per 1,000 picks | lower | Pick tasks that went short ÷ pick tasks completed, × 1,000, in the window -- each a pick face that was not replenished in time. | Cancelled pick tasks are excluded. |
| Inventory carrying exposure | value | lower | Value of stock classed obsolete, non-moving or slow under the excess-and-obsolete policy, at the time of calculation. | Stock held for clients is excluded. |
