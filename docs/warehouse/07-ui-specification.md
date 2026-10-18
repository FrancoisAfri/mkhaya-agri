# 7. UI specification

86 Inertia/Vue pages under `resources/js/Modules/Warehouse`, built with the Mkhaya Hub design system (Vuetify components, the platform layout and navigation, hub landings with permission-gated cards). Every route, with its controller action, is listed in [generated/routes.md](generated/routes.md). English only.

## Navigation

The module's landing page is the **operations dashboard** (`/warehouse`). Hubs group the screens; each card is shown only to people who may open it:

| Hub | Screens |
|---|---|
| Outbound | Fulfilment orders (list, detail with pick/pack/dispatch/carrier/tracking), picking waves, service levels |
| Floor work | Put-away, capacity, bin moves, transfers, replenishment, **floor screen** (mobile), task board, labour, slotting, performance, simulations |
| Inbound | Expected receipts, receipts (with discrepancies and attachments), receiving without an order, dock appointments, supplier returns, customer returns, inspections, supplier scorecards |
| Inventory | Stock overview, movements, adjustments, stock states, reason codes, clients (3PL), counts, count accuracy, losses, batches, expiry, recalls, quality set-up, accounting, stock costs, profit protection, assistant, forecast, buying, excess, production materials |
| Products | Items (master detail), scan, barcode duplicates |
| Warehouse layout | Warehouses (with policies and history), locations explorer, location labels, location history, label set-up, carriers, events and webhooks, connected systems, GS1/EPCIS mapping |
| Insights | Inventory, supplier, profit-protection and executive dashboards, KPIs, reports and exports, approvals, alerts, health, audit and governance |

## Desktop management screens

Common conventions on every screen:

- A title and a one-sentence purpose; filters at the top; the list or table below with `overflow-x` for wide data.
- Empty states say what would appear and what to do to get it.
- Refusals from the server appear as a readable message (`refused`), never as a stack trace; validation messages sit on the field.
- State is shown in words and colour together: saved, posted, pending approval, failed, synchronisation pending (offline), dead letter, escalated.
- Money appears only for people with `wms.accounting`; otherwise the screen says why values are hidden.
- Destructive or irreversible actions ask for a reason where the record needs one (adjustments, rejections, overrides, set-asides).

## Mobile warehouse task screens

`/warehouse/m` — the floor screen for handheld use:

- **Scan first:** the ZXing camera scanner or a hardware wedge scanner into the same input; GS1 barcodes are parsed (GTIN, batch, serial, expiry, SSCC) and invalid ones refused with the reason.
- **One task at a time:** put-away, picking and counting, with the next step stated; the expected quantity is never shown on a blind count.
- **Offline:** counting, picking and put-away work without a signal. Actions queue in an encrypted on-device store and are sent with an idempotency key when the signal returns; an action that no longer fits is held as a conflict for a supervisor (`/warehouse/m/conflicts`), never forced through.
- Large touch targets, labels on every control, and status feedback announced (`role="status"`).

## Accessibility (WMS-232)

Target WCAG 2.2 AA, subject to testing. Built in: keyboard-reachable controls (Vuetify), visible focus, labels on inputs, `aria-label` on icon-only buttons and charts, status alerts with `role="status"`, colour never the only signal (chips carry words). Outstanding before release: an assisted audit with a screen reader and on the supported handheld devices — see [definition-of-done.md](definition-of-done.md).
