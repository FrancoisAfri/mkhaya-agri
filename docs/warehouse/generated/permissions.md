# Permission matrix

Generated Mon, Oct 12, 2026 11:55 PM by `php artisan wms:handover-docs` -- do not edit by hand; regenerate.

## Permissions

| Permission | Meaning |
|---|---|
| `wms.view` | View |
| `wms.warehouse.manage` | Manage Warehouses |
| `wms.location.manage` | Manage Locations |
| `wms.location.block` | Block, restrict and reopen a location. |
| `wms.item.manage` | Create and edit items, their packaging, codes and barcodes. |
| `wms.item.status` | Discontinue, block, recall, quarantine or retire an item -- it stops stock moving. |
| `wms.barcode.approve_duplicate` | Knowingly register a barcode that already belongs to another item. |
| `wms.stock.adjust` | Raise a stock adjustment (posted at once when it is below every approval limit). |
| `wms.stock.approve` | Approve or reject somebody else's adjustment. Never one's own: the schema refuses it. |
| `wms.clients.all` | See every third-party client's stock without a per-client grant. |
| `wms.clients.manage` | Grant and revoke people's access to a client's stock, and set up reason codes. |
| `wms.receive` | Receive deliveries, record what arrived, book dock appointments. |
| `wms.receive.exceptions` | Approve receipts without an order and settle discrepancies outside tolerance. |
| `wms.supplier_return` | Raise, approve and dispatch returns to suppliers. |
| `wms.move` | Perform put-away and bin-to-bin moves. |
| `wms.putaway.override` | Put away somewhere other than a directed bin. |
| `wms.transfer.approve` | Approve a warehouse-to-warehouse transfer, and recall one already on its way. |
| `wms.replenishment.manage` | Set the min/max rules that keep pick faces and warehouses topped up. |
| `wms.order.manage` | Take in, release, allocate and cancel fulfilment orders. |
| `wms.pick` | Pick, pack and dispatch -- the floor work of fulfilment, each its own grant. |
| `wms.pack` | Pack |
| `wms.dispatch` | Dispatch |
| `wms.count` | Enter counts; plan, review and close them. Closing is a separate grant from counting. |
| `wms.count.manage` | Manage Counts |
| `wms.returns` | Customer returns: authorise and receive; crediting the customer is a separate, financial grant. |
| `wms.returns.credit` | Credit Returns |
| `wms.quality` | Hold stock for inspection, record results and decide; plans and defect codes are set up separately. |
| `wms.quality.manage` | Manage Quality |
| `wms.recall` | Open, run and close recalls. |
| `wms.loss` | Loss recoveries and the loss register's evidence. |
| `wms.rotation.override` | Allocate against the rotation rule (an older lot, a chosen bin), with a reason. |
| `wms.accounting` | See valuation and reconciliation, draft landed costs and ask for revaluations; approving them is finance's grant. |
| `wms.accounting.approve` | Approve Accounting |
| `wms.investigate` | Work loss investigations -- the only grant that shows them (WMS-113) -- set the detectors, sign off losses and savings. |
| `wms.protection.manage` | Manage Protection |
| `wms.loss.approve` | Approve Loss |
| `wms.plan` | Run and override forecasts, set supplier terms, draft purchase requests from recommendations. |
| `wms.supervise` | Assign work, set labour standards, propose and apply slotting, run simulations; approving a slotting change is separate. |
| `wms.slotting.approve` | Approve Slotting |
| `wms.integrations.manage` | Carriers, webhooks, API events and connections to other systems (WMS-170..173). |
| `wms.warehouses.all` | Work in every warehouse, including those restricted to their own staff (WMS-200). |
| `wms.override` | Break glass: approve one's own request in an emergency, audited and reviewed afterwards (WMS-201). |
| `wms.audit.view` | Read the warehouse audit ledger (WMS-203). |

## Role templates

The platform administrator and company administrator are the existing `super admin` and `administrator` roles, which hold every warehouse permission.

| Permission | Warehouse manager | Receiving clerk | Inventory controller | Picker and packer | Dispatch clerk | Procurement officer | Sales and fulfilment user | Finance officer | Quality officer | Auditor | Third-party logistics operator | Read-only reporting user |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `wms.view` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| `wms.warehouse.manage` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.location.manage` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.location.block` | ● |  | ● |  |  |  |  |  |  |  |  |  |
| `wms.item.manage` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.item.status` | ● |  | ● |  |  |  |  |  | ● |  |  |  |
| `wms.barcode.approve_duplicate` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.stock.adjust` | ● |  | ● |  |  |  |  |  |  |  |  |  |
| `wms.stock.approve` | ● |  | ● |  |  |  |  |  |  |  |  |  |
| `wms.clients.all` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.clients.manage` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.receive` | ● | ● |  |  |  |  |  |  |  |  | ● |  |
| `wms.receive.exceptions` | ● |  |  |  |  | ● |  |  |  |  |  |  |
| `wms.supplier_return` | ● | ● |  |  |  | ● |  |  |  |  |  |  |
| `wms.move` | ● | ● | ● | ● |  |  |  |  |  |  | ● |  |
| `wms.putaway.override` | ● |  | ● |  |  |  |  |  |  |  |  |  |
| `wms.transfer.approve` | ● |  | ● |  |  |  |  |  |  |  |  |  |
| `wms.replenishment.manage` | ● |  | ● |  |  |  |  |  |  |  |  |  |
| `wms.order.manage` | ● |  |  |  |  |  | ● |  |  |  |  |  |
| `wms.pick` | ● |  |  | ● |  |  |  |  |  |  | ● |  |
| `wms.pack` | ● |  |  | ● | ● |  |  |  |  |  | ● |  |
| `wms.dispatch` | ● |  |  |  | ● |  |  |  |  |  | ● |  |
| `wms.count` | ● |  | ● | ● |  |  |  |  |  |  | ● |  |
| `wms.count.manage` | ● |  | ● |  |  |  |  |  |  |  |  |  |
| `wms.returns` | ● |  |  |  |  |  | ● |  |  |  |  |  |
| `wms.returns.credit` | ● |  |  |  |  |  |  | ● |  |  |  |  |
| `wms.quality` | ● | ● |  |  |  |  |  |  | ● |  |  |  |
| `wms.quality.manage` | ● |  |  |  |  |  |  |  | ● |  |  |  |
| `wms.recall` | ● |  |  |  |  |  |  |  | ● |  |  |  |
| `wms.loss` | ● |  | ● |  |  |  |  |  |  |  |  |  |
| `wms.rotation.override` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.accounting` | ● |  |  |  |  |  |  | ● |  |  |  |  |
| `wms.accounting.approve` | ● |  |  |  |  |  |  | ● |  |  |  |  |
| `wms.investigate` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.protection.manage` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.loss.approve` | ● |  |  |  |  |  |  | ● |  |  |  |  |
| `wms.plan` | ● |  |  |  |  | ● |  |  |  |  |  |  |
| `wms.supervise` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.slotting.approve` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.integrations.manage` |  |  |  |  |  |  |  |  |  |  |  |  |
| `wms.warehouses.all` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.override` | ● |  |  |  |  |  |  |  |  |  |  |  |
| `wms.audit.view` | ● |  |  |  |  |  |  |  |  | ● |  |  |

## Every route and the permission it checks

Read from each controller action's source: the `RoleCatalog` permissions it checks and the policy abilities it authorises. `viewAny` on stock items or warehouses means `wms.view`. Services refuse further (segregation of duties, approval levels, warehouse staff, client access) beyond what is listed.

| Method | Path | Checks |
|---|---|---|
| GET | `/api/v1/warehouse/epcis/events` | `wms.view` |
| GET | `/api/v1/warehouse/event-types` | `wms.view` |
| GET | `/api/v1/warehouse/events` | `wms.view` |
| POST | `/api/v1/warehouse/events/{eventId}/replay` | `wms.integrations.manage` |
| GET | `/api/v1/warehouse/movements` | `wms.view` |
| GET | `/api/v1/warehouse/openapi.yaml` | public by design: the contract only, no data |
| POST | `/api/v1/warehouse/orders` | `wms.order.manage` |
| GET | `/api/v1/warehouse/orders/{ref}` | `wms.view` |
| POST | `/api/v1/warehouse/orders/{ref}/cancel` | `wms.order.manage` |
| GET | `/api/v1/warehouse/reservations` | `wms.view` |
| GET | `/api/v1/warehouse/returns` | `wms.view` |
| GET | `/api/v1/warehouse/shipments/{number}` | `wms.view` |
| POST | `/api/v1/warehouse/shipments/{number}/tracking` | `wms.dispatch` |
| GET | `/api/v1/warehouse/stock` | `wms.view` |
| GET | `/api/v1/warehouse/webhooks` | `wms.integrations.manage` |
| POST | `/api/v1/warehouse/webhooks` | `wms.integrations.manage` |
| PATCH | `/api/v1/warehouse/webhooks/{uuid}` | `wms.integrations.manage` |
| DELETE | `/api/v1/warehouse/webhooks/{uuid}` | `wms.integrations.manage` |
| POST | `/api/v1/warehouse/webhooks/{uuid}/rotate` | `wms.integrations.manage` |
| GET | `/warehouse` | policy:viewAny |
| GET | `/warehouse/accounting` | `wms.accounting`, `wms.accounting.approve` |
| GET | `/warehouse/accounting/costs` | `wms.accounting` |
| POST | `/warehouse/accounting/issues/{uuid}` | `wms.accounting.approve` |
| POST | `/warehouse/accounting/landed` | `wms.accounting` |
| POST | `/warehouse/accounting/landed/{uuid}/post` | `wms.accounting.approve` |
| POST | `/warehouse/accounting/reconcile` | `wms.accounting` |
| POST | `/warehouse/accounting/revaluations` | `wms.accounting` |
| POST | `/warehouse/accounting/revaluations/{uuid}` | `wms.accounting.approve`, whoever may decide the current approval level (own permission or delegation; never the requester) |
| POST | `/warehouse/accounting/rules/{id}` | `wms.accounting.approve` |
| GET | `/warehouse/alerts` | `wms.supervise`, `wms.warehouse.manage`, policy:viewAny |
| POST | `/warehouse/alerts/{event}` | `wms.warehouse.manage` |
| GET | `/warehouse/approvals` | `wms.warehouse.manage`, policy:viewAny |
| POST | `/warehouse/approvals/delegations` | policy:viewAny |
| POST | `/warehouse/approvals/delegations/{delegation}/revoke` | `wms.warehouse.manage` |
| POST | `/warehouse/approvals/policies/{type}` | `wms.warehouse.manage` |
| POST | `/warehouse/approvals/steps/{step}` | whoever may decide the current approval level (own permission or delegation; never the requester) |
| GET | `/warehouse/assistant` | `wms.supervise`, policy:viewAny |
| POST | `/warehouse/assistant` | policy:viewAny |
| POST | `/warehouse/assistant/answers/{uuid}/feedback` | policy:viewAny |
| POST | `/warehouse/assistant/drafts` | policy:viewAny |
| POST | `/warehouse/assistant/drafts/{uuid}/withdraw` | policy:viewAny |
| GET | `/warehouse/assistant/quality` | `wms.supervise` |
| GET | `/warehouse/batches` | policy:viewAny |
| GET | `/warehouse/batches/expiry` | policy:viewAny |
| GET | `/warehouse/batches/serial` | policy:viewAny |
| GET | `/warehouse/batches/{uuid}` | policy:viewAny |
| GET | `/warehouse/counts` | `wms.count.manage`, policy:viewAny |
| POST | `/warehouse/counts` | `wms.count.manage` |
| GET | `/warehouse/counts/accuracy` | policy:viewAny |
| POST | `/warehouse/counts/classify` | `wms.count.manage` |
| POST | `/warehouse/counts/plans` | `wms.count.manage` |
| PATCH | `/warehouse/counts/plans/{uuid}` | `wms.count.manage` |
| POST | `/warehouse/counts/plans/{uuid}/run` | `wms.count.manage` |
| GET | `/warehouse/counts/{uuid}` | `wms.count.manage`, `wms.count`, policy:viewAny |
| POST | `/warehouse/counts/{uuid}` | `wms.count`, `wms.count.manage` |
| POST | `/warehouse/counts/{uuid}/entries` | `wms.count` |
| GET | `/warehouse/floor` | module tier; each card has its own gate |
| GET | `/warehouse/floor/capacity` | policy:viewAny |
| GET | `/warehouse/floor/move` | `wms.move`, policy:viewAny |
| POST | `/warehouse/floor/move` | `wms.move` |
| GET | `/warehouse/floor/putaway` | `wms.move`, policy:viewAny |
| POST | `/warehouse/floor/putaway` | `wms.move` |
| GET | `/warehouse/floor/putaway/{uuid}` | `wms.move`, `wms.putaway.override`, policy:viewAny |
| POST | `/warehouse/floor/putaway/{uuid}` | `wms.move` |
| GET | `/warehouse/floor/replenishment` | `wms.replenishment.manage`, `wms.move`, policy:viewAny |
| GET | `/warehouse/floor/replenishment/bins` | policy:viewAny |
| POST | `/warehouse/floor/replenishment/generate` | `wms.move` |
| POST | `/warehouse/floor/replenishment/purchase-request` | `wms.replenishment.manage` |
| POST | `/warehouse/floor/replenishment/rules` | `wms.replenishment.manage` |
| PATCH | `/warehouse/floor/replenishment/rules/{uuid}` | `wms.replenishment.manage` |
| POST | `/warehouse/floor/replenishment/tasks/{uuid}` | `wms.move` |
| GET | `/warehouse/floor/transfers` | `wms.move`, `wms.transfer.approve`, policy:viewAny |
| POST | `/warehouse/floor/transfers` | `wms.move` |
| POST | `/warehouse/floor/transfers/{uuid}` | `wms.move` |
| GET | `/warehouse/governance` | `wms.audit.view`, `wms.warehouse.manage`, `wms.override` |
| GET | `/warehouse/governance/audit.csv` | `wms.audit.view` |
| POST | `/warehouse/governance/overrides` | `wms.override`, `wms.warehouse.manage` |
| POST | `/warehouse/governance/overrides/{id}` | `wms.override`, `wms.warehouse.manage` |
| POST | `/warehouse/governance/settings` | `wms.warehouse.manage` |
| GET | `/warehouse/health` | `wms.supervise`, `wms.accounting` |
| GET | `/warehouse/inbound` | module tier; each card has its own gate |
| GET | `/warehouse/inbound/appointments` | `wms.receive`, policy:viewAny |
| POST | `/warehouse/inbound/appointments` | `wms.receive` |
| POST | `/warehouse/inbound/appointments/{uuid}/move` | `wms.receive` |
| POST | `/warehouse/inbound/discrepancies/{discrepancy}/resolve` | `wms.receive.exceptions` |
| GET | `/warehouse/inbound/expected` | `wms.receive`, `wms.item.manage`, policy:viewAny |
| POST | `/warehouse/inbound/order-lines/{line}/link` | `wms.item.manage` |
| GET | `/warehouse/inbound/orders/{po}/receive` | `wms.receive`, `wms.receive.exceptions` |
| POST | `/warehouse/inbound/orders/{po}/receive` | `wms.receive` |
| GET | `/warehouse/inbound/receipts` | policy:viewAny |
| GET | `/warehouse/inbound/receipts/{uuid}` | `wms.receive.exceptions`, `wms.receive`, `wms.supplier_return`, policy:viewAny |
| POST | `/warehouse/inbound/receipts/{uuid}/attachments` | `wms.receive` |
| GET | `/warehouse/inbound/receipts/{uuid}/attachments/{attachment}` | policy:viewAny |
| POST | `/warehouse/inbound/receipts/{uuid}/decide` | `wms.receive.exceptions` |
| GET | `/warehouse/inbound/returns` | `wms.supplier_return`, policy:viewAny |
| POST | `/warehouse/inbound/returns` | `wms.supplier_return` |
| GET | `/warehouse/inbound/returns/returnable` | policy:viewAny |
| POST | `/warehouse/inbound/returns/{uuid}/move` | `wms.supplier_return` |
| GET | `/warehouse/inbound/suppliers` | policy:viewAny |
| GET | `/warehouse/inbound/without-order` | `wms.receive` |
| POST | `/warehouse/inbound/without-order` | `wms.receive` |
| GET | `/warehouse/insights` | module tier; each card has its own gate |
| GET | `/warehouse/insights/{kind}` | `wms.investigate`, `wms.accounting`, policy:viewAny |
| GET | `/warehouse/integrations/carriers` | `wms.integrations.manage`, policy:viewAny |
| POST | `/warehouse/integrations/carriers` | `wms.integrations.manage` |
| POST | `/warehouse/integrations/carriers/{uuid}` | `wms.integrations.manage` |
| GET | `/warehouse/integrations/events` | `wms.integrations.manage`, policy:viewAny |
| POST | `/warehouse/integrations/events/{eventId}/replay` | `wms.integrations.manage` |
| GET | `/warehouse/integrations/standards` | `wms.integrations.manage`, policy:viewAny |
| GET | `/warehouse/integrations/standards/epcis` | policy:viewAny |
| POST | `/warehouse/integrations/standards/gln/{warehouse}` | `wms.integrations.manage` |
| GET | `/warehouse/integrations/systems` | `wms.integrations.manage`, policy:viewAny |
| POST | `/warehouse/integrations/systems` | `wms.integrations.manage` |
| POST | `/warehouse/integrations/systems/{uuid}` | `wms.integrations.manage` |
| POST | `/warehouse/integrations/webhooks` | `wms.integrations.manage` |
| POST | `/warehouse/integrations/webhooks/{uuid}` | `wms.integrations.manage` |
| GET | `/warehouse/inventory` | module tier; each card has its own gate |
| GET | `/warehouse/kpis` | `wms.supervise`, `wms.accounting`, `wms.warehouse.manage`, policy:viewAny |
| POST | `/warehouse/kpis` | `wms.warehouse.manage` |
| POST | `/warehouse/labels/print` | policy:viewAny |
| POST | `/warehouse/labels/printers` | `wms.warehouse.manage` |
| GET | `/warehouse/labels/setup` | `wms.warehouse.manage`, policy:viewAny |
| POST | `/warehouse/labels/templates` | `wms.warehouse.manage` |
| GET | `/warehouse/layout` | module tier; each card has its own gate |
| GET | `/warehouse/layout/history` | policy:viewAny |
| GET | `/warehouse/layout/labels` | policy:viewAny |
| GET | `/warehouse/layout/locations` | policy:viewAny |
| GET | `/warehouse/layout/warehouses` | policy:viewAny |
| POST | `/warehouse/layout/warehouses` | policy:create |
| PATCH | `/warehouse/layout/warehouses/{uuid}` | policy:update |
| DELETE | `/warehouse/layout/warehouses/{uuid}` | policy:delete |
| GET | `/warehouse/layout/warehouses/{uuid}/policies` | policy:view |
| POST | `/warehouse/layout/warehouses/{uuid}/policies` | policy:update |
| POST | `/warehouse/layout/warehouses/{uuid}/policies/{version}` | policy:update |
| POST | `/warehouse/layout/warehouses/{uuid}/staff` | policy:update |
| DELETE | `/warehouse/layout/warehouses/{uuid}/staff/{staff}` | policy:update |
| POST | `/warehouse/layout/warehouses/{uuid}/sync` | policy:update |
| POST | `/warehouse/layout/warehouses/{warehouse}/locations` | policy:manageLocations |
| POST | `/warehouse/layout/warehouses/{warehouse}/locations/generate` | policy:manageLocations |
| PATCH | `/warehouse/layout/warehouses/{warehouse}/locations/{uuid}` | policy:manageLocations |
| DELETE | `/warehouse/layout/warehouses/{warehouse}/locations/{uuid}` | policy:manageLocations |
| POST | `/warehouse/layout/warehouses/{warehouse}/locations/{uuid}/status` | policy:blockLocations |
| GET | `/warehouse/losses` | `wms.loss`, policy:viewAny |
| POST | `/warehouse/losses/adjustments/{uuid}/evidence` | `wms.stock.adjust`, `wms.loss` |
| GET | `/warehouse/losses/evidence/{uuid}` | policy:viewAny |
| POST | `/warehouse/losses/recoveries` | `wms.loss` |
| POST | `/warehouse/losses/recoveries/{uuid}` | `wms.loss` |
| GET | `/warehouse/m` | `wms.count`, `wms.pick`, `wms.move`, `wms.receive`, `wms.supervise`, policy:viewAny |
| GET | `/warehouse/m/bundle` | policy:viewAny |
| GET | `/warehouse/m/conflicts` | `wms.supervise` |
| POST | `/warehouse/m/conflicts/{id}` | `wms.supervise` |
| POST | `/warehouse/m/sync` | `wms.count`, `wms.pick`, `wms.move`, `wms.receive`, policy:viewAny |
| GET | `/warehouse/outbound` | module tier; each card has its own gate |
| GET | `/warehouse/outbound/orders` | policy:viewAny |
| POST | `/warehouse/outbound/orders` | `wms.order.manage` |
| GET | `/warehouse/outbound/orders/{uuid}` | policy:viewAny |
| POST | `/warehouse/outbound/orders/{uuid}` | `wms.pick`, `wms.order.manage` |
| POST | `/warehouse/outbound/orders/{uuid}/dispatch` | `wms.dispatch` |
| GET | `/warehouse/outbound/orders/{uuid}/documents` | policy:viewAny |
| POST | `/warehouse/outbound/orders/{uuid}/override` | `wms.rotation.override` |
| GET | `/warehouse/outbound/orders/{uuid}/override-options` | `wms.rotation.override` |
| POST | `/warehouse/outbound/orders/{uuid}/pack` | `wms.pack` |
| GET | `/warehouse/outbound/picking` | policy:viewAny |
| POST | `/warehouse/outbound/picking` | `wms.pick` |
| GET | `/warehouse/outbound/picking/{uuid}` | policy:viewAny |
| POST | `/warehouse/outbound/picks/{uuid}` | `wms.pick` |
| GET | `/warehouse/outbound/service-levels` | policy:viewAny |
| POST | `/warehouse/outbound/shipments/{shipment}/delivered` | `wms.dispatch` |
| GET | `/warehouse/outbound/shipments/{shipment}/pod` | policy:viewAny |
| POST | `/warehouse/outbound/shipments/{shipment}/tracking` | `wms.dispatch` |
| GET | `/warehouse/planning/buying` | `wms.plan`, policy:viewAny |
| GET | `/warehouse/planning/buying/compare/{uuid}` | policy:viewAny |
| POST | `/warehouse/planning/buying/request` | `wms.plan` |
| POST | `/warehouse/planning/buying/terms` | `wms.plan` |
| GET | `/warehouse/planning/excess` | policy:viewAny |
| GET | `/warehouse/planning/forecast` | `wms.plan`, policy:viewAny |
| POST | `/warehouse/planning/forecast` | `wms.plan` |
| POST | `/warehouse/planning/forecast/{id}` | `wms.plan` |
| GET | `/warehouse/production` | `wms.order.manage`, `wms.move`, policy:viewAny |
| POST | `/warehouse/production/put-away/{position}` | `wms.move` |
| POST | `/warehouse/production/{uuid}/pick` | `wms.order.manage` |
| GET | `/warehouse/products` | module tier; each card has its own gate |
| GET | `/warehouse/products/items` | policy:viewAny |
| POST | `/warehouse/products/items` | policy:create |
| GET | `/warehouse/products/items/{uuid}` | policy:view |
| PATCH | `/warehouse/products/items/{uuid}` | policy:update |
| POST | `/warehouse/products/items/{uuid}/barcodes` | policy:update |
| POST | `/warehouse/products/items/{uuid}/barcodes/generate` | policy:update |
| DELETE | `/warehouse/products/items/{uuid}/barcodes/{barcode}` | policy:update |
| PUT | `/warehouse/products/items/{uuid}/bundle` | policy:update |
| POST | `/warehouse/products/items/{uuid}/identifiers` | policy:update |
| DELETE | `/warehouse/products/items/{uuid}/identifiers/{identifier}` | policy:update |
| POST | `/warehouse/products/items/{uuid}/packaging` | policy:update |
| PATCH | `/warehouse/products/items/{uuid}/packaging/{packaging}` | policy:update |
| DELETE | `/warehouse/products/items/{uuid}/packaging/{packaging}` | policy:update |
| POST | `/warehouse/products/items/{uuid}/status` | policy:changeStatus |
| POST | `/warehouse/products/items/{uuid}/substitutes` | policy:update |
| DELETE | `/warehouse/products/items/{uuid}/substitutes/{substitute}` | policy:update |
| GET | `/warehouse/products/labels` | policy:viewAny |
| GET | `/warehouse/products/resolve` | policy:viewAny |
| GET | `/warehouse/products/scan` | policy:viewAny |
| GET | `/warehouse/products/search` | policy:viewAny |
| GET | `/warehouse/protection` | `wms.investigate`, `wms.protection.manage` |
| POST | `/warehouse/protection` | `wms.investigate` |
| GET | `/warehouse/protection/rules` | `wms.investigate`, `wms.protection.manage` |
| PATCH | `/warehouse/protection/rules/{id}` | `wms.protection.manage` |
| POST | `/warehouse/protection/scan` | `wms.protection.manage` |
| GET | `/warehouse/protection/{uuid}` | `wms.investigate`, `wms.loss.approve` |
| POST | `/warehouse/protection/{uuid}` | `wms.investigate`, `wms.loss.approve` |
| POST | `/warehouse/quality/defects` | `wms.quality.manage` |
| PATCH | `/warehouse/quality/defects/{id}` | `wms.quality.manage` |
| POST | `/warehouse/quality/hold` | `wms.quality` |
| GET | `/warehouse/quality/inspections` | `wms.quality`, `wms.quality.manage`, policy:viewAny |
| POST | `/warehouse/quality/inspections/{uuid}/decision` | `wms.quality` |
| POST | `/warehouse/quality/inspections/{uuid}/results` | `wms.quality` |
| POST | `/warehouse/quality/orders/{uuid}` | `wms.quality` |
| POST | `/warehouse/quality/plans` | `wms.quality.manage` |
| PATCH | `/warehouse/quality/plans/{uuid}` | `wms.quality.manage` |
| GET | `/warehouse/quality/setup` | `wms.quality.manage`, policy:viewAny |
| GET | `/warehouse/recalls` | `wms.recall`, policy:viewAny |
| POST | `/warehouse/recalls` | `wms.recall` |
| GET | `/warehouse/recalls/lots` | policy:viewAny |
| GET | `/warehouse/recalls/{uuid}` | `wms.recall`, policy:viewAny |
| POST | `/warehouse/recalls/{uuid}` | `wms.recall` |
| GET | `/warehouse/recalls/{uuid}/report` | `wms.recall`, policy:viewAny |
| GET | `/warehouse/reports` | policy:viewAny |
| POST | `/warehouse/reports` | policy:viewAny |
| GET | `/warehouse/reports/{uuid}` | `wms.accounting`, policy:viewAny |
| GET | `/warehouse/returns` | `wms.returns`, policy:viewAny |
| POST | `/warehouse/returns` | `wms.returns` |
| GET | `/warehouse/returns/sources` | policy:viewAny |
| GET | `/warehouse/returns/{uuid}` | `wms.returns`, `wms.returns.credit`, `wms.quality`, policy:viewAny |
| POST | `/warehouse/returns/{uuid}` | `wms.returns.credit`, `wms.returns` |
| GET | `/warehouse/stock/adjustments` | `wms.stock.adjust`, `wms.stock.approve`, `wms.loss`, policy:viewAny |
| POST | `/warehouse/stock/adjustments` | `wms.stock.adjust` |
| GET | `/warehouse/stock/adjustments/positions` | policy:viewAny |
| GET | `/warehouse/stock/adjustments/{uuid}` | policy:viewAny |
| POST | `/warehouse/stock/adjustments/{uuid}/decide` | `wms.stock.approve`, whoever may decide the current approval level (own permission or delegation; never the requester) |
| GET | `/warehouse/stock/clients` | `wms.clients.all`, `wms.clients.manage`, policy:viewAny |
| POST | `/warehouse/stock/clients/access` | `wms.clients.manage` |
| DELETE | `/warehouse/stock/clients/access` | `wms.clients.manage` |
| GET | `/warehouse/stock/items/{uuid}` | policy:view |
| GET | `/warehouse/stock/movements` | policy:viewAny |
| GET | `/warehouse/stock/overview` | policy:viewAny |
| GET | `/warehouse/stock/reason-codes` | `wms.clients.manage`, policy:viewAny |
| POST | `/warehouse/stock/reason-codes` | `wms.clients.manage` |
| PATCH | `/warehouse/stock/reason-codes/{uuid}` | `wms.clients.manage` |
| GET | `/warehouse/stock/states` | policy:viewAny |
| GET | `/warehouse/work/board` | `wms.supervise`, policy:viewAny |
| POST | `/warehouse/work/board/assign` | `wms.supervise` |
| GET | `/warehouse/work/board/history` | policy:viewAny |
| GET | `/warehouse/work/labour` | `wms.supervise`, `wms.pick`, policy:viewAny |
| POST | `/warehouse/work/labour/standards` | `wms.supervise` |
| GET | `/warehouse/work/performance` | policy:viewAny |
| GET | `/warehouse/work/simulations` | `wms.supervise`, policy:viewAny |
| POST | `/warehouse/work/simulations` | `wms.supervise` |
| GET | `/warehouse/work/slotting` | `wms.supervise`, `wms.slotting.approve`, policy:viewAny |
| POST | `/warehouse/work/slotting` | `wms.supervise`, `wms.slotting.approve` |
