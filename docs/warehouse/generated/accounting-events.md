# Accounting events

Generated Mon, Oct 12, 2026 11:55 PM by `php artisan wms:handover-docs` -- do not edit by hand; regenerate.

The default treatment of every warehouse event. Each company's rules are in `wms_accounting_rules` and may be changed (and suspended) by people with `wms.accounting.approve`; account roles resolve through Finance's account map (module `warehouse`).

| Event | Owner | Treatment | Debit | Credit | In force by default | What happens |
|---|---|---|---|---|---|---|
| GOODS_RECEIPT | PROCUREMENT | POST | INVENTORY | grni | yes | A goods-received note posts Dr inventory, Cr Goods Received Not Invoiced. The supplier's bill later clears GRNI against payables. |
| DISPATCH | WAREHOUSE | POST | cogs | INVENTORY | yes | Stock leaving on a shipment posts Dr cost of sales, Cr inventory, at the item's cost. |
| INVOICE_RELIEF | FINANCE | NONE | — | — | yes | A sales invoice relieves stock only where the warehouse is not run by WHMI; for WHMI warehouses dispatch does it, so the sale is not costed twice. |
| ADJUSTMENT | WAREHOUSE | POST | adjustment | INVENTORY | yes | An approved stock adjustment or count variance posts between inventory and Inventory Adjustments, in the direction of the change. |
| CUSTOMER_RETURN | WAREHOUSE | POST | INVENTORY | cogs | yes | Goods returned by a customer post Dr inventory, Cr cost of sales, at the cost they left at. The credit note is Finance's. |
| SUPPLIER_RETURN | WAREHOUSE | POST | grni | INVENTORY | yes | Goods sent back to a supplier post Dr Goods Received Not Invoiced, Cr inventory. |
| SCRAP | MANUFACTURING | POST | 5610 | INVENTORY | yes | Stock scrapped by an inspection, return or recall posts Dr Scrap & Spoilage, Cr inventory. |
| TRANSFER | WAREHOUSE | NONE | — | — | yes | Moving stock between bins or warehouses changes where it is, not what it is worth. |
| STATUS_CHANGE | WAREHOUSE | NONE | — | — | yes | Quarantine, hold, release and rejection change whether stock may be used, not its value. |
| CLIENT_STOCK | WAREHOUSE | NONE | — | — | yes | Stock held for a third-party client is counted but never valued: it is not this company's asset. |
| LANDED_COST | WAREHOUSE | POST | INVENTORY | landed_cost_clearing | no, needs approval | Freight, duty and other costs of bringing stock in post Dr inventory (or cost of sales for what has already gone), Cr Landed Cost Clearing. |
| REVALUATION | WAREHOUSE | POST | INVENTORY | revaluation | no, needs approval | A revaluation posts the change in value between inventory and Inventory Revaluation, after a second person approves it. |
