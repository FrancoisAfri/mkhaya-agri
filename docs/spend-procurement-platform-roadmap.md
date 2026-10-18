# AI Spend Governance, Budget & Procurement Platform — Build Roadmap

End-to-end flow: **Request → policy check → budget check → approval → reserve funds →
requisition → supplier/RFQ/marketplace → PO → delivery/GRN → invoice match → asset/inventory →
finance reconcile → central logging → AI insights.**

Decisions: reuse & extend existing Supplier/Contract/PO/RFx/Invoice modules; everything lives
under the **Supply Chain & Procurement Management** category; built in **strict spec sprint order**;
marketplace = **plugin framework + mock adapter** (real APIs when credentials provided).

Status: ✅ done · 🔜 planned

---

## Sprint 1 — Budget Management (Module 1) ✅ DONE
Module `budget_management` (`/budgets`) in the Supply Chain category.
- **Tables (13):** financial_years, budget_periods, budget_categories, budgets, budget_allocations, budget_owners, budget_approvals, budget_adjustments, budget_overrides, budget_reservations, budget_commitments, budget_consumptions.
- **Fund lifecycle** (`BudgetService`): `createBudget`, `allocateBudget`, `approveBudget/reject`, `adjustBudget`/`increaseBudget`/`approveAdjustment` (increase/decrease/transfer), `requestBudgetOverride`/`approveBudgetOverride`, `validateBudgetAvailability`, and the **reserve → commit → consume** ledger (`reserveBudget`, `releaseReservedBudget`, `commitBudget`, `consumeBudget`) with `amounts()` (allocated/reserved/committed/actual/overrides/available). Verified: reserve→commit→consume convert without double-counting; overrides add headroom; shortfall detection correct.
- CAPEX/OPEX, department/project/category/general scopes, financial years + periods + categories.
- Policy guard (`budgets.*`), full **audit** (LogsActivity) + **soft-delete + restoration** (5 models auto-listed), 5 controllers, 5 Vue pages (Dashboard, Budgets, Create, Show with fund bar + adjustments/overrides/ledger, Settings). Build passes; controllers render; routes + 4 ribbons live.

**The reserve/commit/consume + validateBudgetAvailability functions are the integration points the next sprints call.**

## Sprint 2 — Employee Purchase Request + Validation + Approval + Reservation ✅ DONE
Module `procurement_requests` (`/purchase-requests`).
- **13 tables:** purchase_requests/items/attachments/statuses/history; procurement_policy_rules/violations/exceptions/enforcement_logs; procurement_approvals/steps/comments/escalations.
- **M2:** purchase request with line items, DB-stored attachments, justification, dept/project/budget/urgency/delivery, auto PR number, full history.
- **M3 (`ProcurementValidationService`):** emergency-justification, approved-budget-required, **budget availability** (`BudgetService::validateBudgetAvailability`), quotation-threshold warning, duplicate + split detection; builds the **approval matrix** from configurable `procurement_policy_rules`; logs enforcement; persists violations.
- **M4 (`ProcurementApprovalService`):** multi-step chain (manager ≥10k / finance ≥50k / executive ≥100k / IT ≥25k — seeded), approve/reject/escalate/comment, **no self-approval**; on final approval calls **`BudgetService::reserveBudget()`**.
- Policy guard (`procurement.*`), audit, soft-delete + restoration; 4 controllers, 5 Vue pages, module + 4 ribbons.
- **Verified end-to-end:** request → validate → 2-step approve → **budget reserved** (available reduced); over-budget & no-budget submissions blocked; self-approval blocked.

## Sprint 3 — Requisition + Supplier (reuse) + RFQ ✅ DONE
Requisitions in the `procurement_requests` module (`/requisitions`).
- **4 tables** (requisitions/items/attachments/history) + `is_preferred` on suppliers.
- **`RequisitionService`:** `convertFromRequest` (approved PR → requisition, copies items + carries the budget reservation, PR → converted), `assignOfficer`, `confirmMethod`, `confirmReservation`, `createRfq` (**reuses the RFx module**: RfxEvent + invitations → quoting), `chooseSupplier` (→ awarded), cancel + history.
- "Convert to requisition" on an approved request; preferred suppliers listed first; audit + soft-delete + restoration; 2 Vue pages, "Requisitions" ribbon.
- **Verified end-to-end:** approved PR → convert → officer → confirm reservation → issue RFQ (RfxEvent + invitations) → award; re-conversion blocked.

## Sprint 4 — PO + Delivery/GRN + Asset/Inventory ✅ DONE
Purchase order P2P flow in the `procurement_requests` module (`/purchase-orders`).
- **Extended existing `purchase_orders`** (added `procurement_requisition_id`, `budget_id`, `budget_commitment_id`, `source`, `expected_delivery`, `delivered_at`, `received_status`) + **7 new tables**: `purchase_order_items` (w/ `received_qty`, `is_asset`), `delivery_notes`, `goods_received_notes`, `goods_received_items`, `purchase_order_amendments`, `procurement_asset_links`, `procurement_inventory_links`.
- **`PurchaseOrderService`:** `generateFromRequisition` (awarded requisition → PO, copies items, **`commitBudget()` converts the reservation → commitment**, requisition → `po_created`), `createManual`, `approve`/`send`, `captureDeliveryNote`, `createGRN` (receive/partial/damaged; draws the commitment down and records consumption per receipt → no double-count; auto asset/inventory links; sets `received_status`), `amend` (value amendment adjusts PO total + commitment), `cancel` (releases outstanding commitment).
- "Generate PO" on an awarded requisition; PO Index + Show (approve/send, GRN receipt grid, delivery capture, GRN history, **live budget snapshot**, created asset/inventory lists, amendments); "Purchase Orders" ribbon; audit + soft-delete.
- **Verified end-to-end:** reserve 30k → generate PO (reserved→committed, available steady at 70k) → partial GRN 15k (committed 15k / actual 15k) → final GRN (committed 0 / actual 30k, received) → 2 asset links (laptops) + 2 inventory links (paper).

## Sprint 5 — Marketplace + Supplier API hub (framework + mock) ✅ DONE
New `marketplace` module (`/marketplace`) in the Supply Chain category.
- **6 tables:** `marketplace_integrations`, `marketplace_products` (cached catalog), `marketplace_carts`, `marketplace_cart_items`, `marketplace_orders` (linked to the P2P PO), `marketplace_sync_logs`.
- **Plugin framework:** `MarketplaceAdapter` contract (key/label/healthCheck/fetchCatalog/fetchPrice/fetchStock/placeOrder/fetchInvoice/fetchShipment) + `AbstractMarketplaceAdapter` (credential/config access, shared price/stock lookups) + **`MockMarketplaceAdapter`** (8-product sandbox catalog, accepts orders) + **`MarketplaceManager`** registry (singleton; `extend($key,$class)` to register real providers — Takealot/Makro/etc. when credentials supplied).
- **`MarketplaceService`:** create/update integration, `syncCatalog` (adapter → upsert products + log), `healthCheck`, activity logging.
- **`MarketplaceOrderService`** (direct ordering): cart management, `validateCart` (budget gate), **`checkout`** → validate budget → **reserve** → adapter `placeOrder` → create P2P **PurchaseOrder (source=marketplace, approved)** → **commit budget** (reservation→commitment) → record marketplace order. On adapter rejection the reservation is released. Auto-resolves/creates the integration's supplier so the PO is valid.
- **5 Vue pages:** Browse (catalog grid + add-to-cart), Cart (qty/remove + budget-charge checkout), Orders Index/Show (provider payload + link to the PO), Integrations (CRUD, sync, health, enable/disable). Ribbons: Browse / Cart / Orders / Integrations. `marketplace.view|order|manage` perms + policy. Default mock integration seeded + catalog synced.
- **Verified end-to-end:** add laptop+paper (R40,888) → checkout (committed 40,888 / available steady) → marketplace order `confirmed` + PO created → the PO **receives via the Sprint 4 GRN path** (committed→actual, 1 asset + 1 inventory link).

## Sprint 6 — Invoice AI matching + Finance reconciliation ✅ DONE
Invoices in the `procurement_requests` module (`/invoices`), reusing/extending the existing `supplier_invoices`.
- **Extended `supplier_invoices`** (budget_id, goods_received_note_id, subtotal, tax_amount, source, match_status, matched_at, payment_status, variance_amount, reconciled_at/by, budget_consumption_id, ai_* + FileStorage doc columns) + **2 new tables**: `supplier_invoice_items`, `invoice_match_results`.
- **`InvoiceAiService`:** extracts header + line items + totals from an uploaded PDF/DOCX/text doc (pdftotext/zip) **or pasted text** via the AI agent (`procurement`), writes back fields + rebuilds line items.
- **`InvoiceMatchingService`:** **3-way match** (PO ↔ goods-received ↔ invoice; falls back to 2-way when no GRN) — amount/GRN/quantity/price checks within a configurable tolerance, records discrepancies; **`reconcile`** posts the price variance to the budget actual so **actual == invoiced (no double-count vs the GRN)** and releases the PO's residual commitment; `markPaid`.
- **`InvoiceService`:** create-from-PO (prefills lines), manual create, line rebuild. **`InvoiceController`** + routes (index/create/store/show/document/extract/items/match/reconcile/pay); "Invoices" ribbon; AP perms reuse `procurement.*`.
- **3 Vue pages:** Index, Create (from-PO/manual + doc upload + run-AI), Show (AI extract w/ paste, lines, 3-way match panel with per-check chips + discrepancies, reconcile accept/adjust/dispute, mark-paid, budget snapshot, PO-vs-received).
- **Verified end-to-end:** exact invoice → matched, variance 0, actual unchanged (no double-count). Overbilled invoice (R21k vs R20k PO) → **exception** flagged with amount+GRN+price discrepancies → reconcile(adjust) posts +R1k → **actual=21,000, committed=0, available=79,000** → mark paid.

## Sprint 7 — AI Procurement & Spend Intelligence ✅ DONE
Spend intelligence in the `procurement_requests` module (`/procurement/intelligence`).
- **2 tables:** `procurement_insights` (typed findings, deduped by signature), `procurement_risk_scores` (supplier/budget/overall composite scores).
- **`SpendIntelligenceService`** — rule-based detectors over the live P2P data: **duplicate invoices, order splitting** (just-under-threshold clustering), **inflated pricing** (vs historical median), **emergency abuse**, **supplier dependency/favoritism** (spend concentration), **budget leakage** (over-spend + stale reservations), **off-contract spend** (guarded on the contracts schema), **savings** (vs marketplace price), and **forecast overruns** (run-rate projection). Persists via `updateOrCreate` on a stable signature.
- **`RiskScoringService`** — rolls open findings into 0-100 supplier/budget/overall scores with bands. **`SpendNarrativeService`** — AI executive briefing from the metrics (deterministic fallback when AI off).
- **`SpendIntelligenceController`** (dashboard / run / narrative / insight status) + routes; "Spend Intelligence" ribbon; narrative cached (no extra schema). **Dashboard Vue:** KPIs, AI briefing, findings list w/ status actions, severity donut + by-type bar (apexcharts), top supplier/budget risk scores.
- **Verified end-to-end:** seeded duplicate invoices, split requests, inflated item, emergency cluster, dominant supplier, over-spent budget → engine produced **7 distinct insight types**, overall risk **95/critical**, supplier+budget scores rolled up.

## Sprint 8 — Dashboards + Notifications + Escalations + Exec AI ✅ DONE
- **`ProcurementDashboardController`** + role-adaptive `Dashboard.vue` (`/procurement/dashboard`, "Dashboard" ribbon): resolves the viewer's role (overridable via `?view=`) and surfaces **Employee** (my requests), **Manager** (awaiting my approval), **Procurement** (requisitions/POs in flight/late deliveries), **Finance** (invoice match/reconcile/pay queue), **Executive** (budget totals, overall risk, savings, forecast, top suppliers, AI briefing) widgets — all over the shared P2P data.
- **`ProcurementAlertService`** (pull-based watchdog) + **`ProcurementEscalationsCommand`** (scheduled **hourly** in `bootstrap/app.php`): scans **overdue approvals** (escalates via `procurement_escalations`, deduped per step), **delivery delays** (PO past `expected_delivery`, not received), **budget overruns** (available < 0 → notify owners), **open policy violations** (notify admins). In-app notifications via the existing `GenericInAppNotification` (bell), deduped per day via cache.
- **Executive AI** reuses Sprint 7's `SpendNarrativeService` (cached briefing surfaced on the exec dashboard + intelligence page).
- **Verified:** alert scans fired delivery + budget overrun notifications (2 sent), overdue approval escalated once and **deduped on re-run**; command runs clean on empty data and is registered in the scheduler.

## Status: all 8 sprints complete ✅
End-to-end P2P live: request → policy/budget validate → approve (reserve) → requisition → RFQ/award → PO (commit) → deliver/GRN (consume) → asset/inventory; plus marketplace direct ordering, invoice AI + 3-way match + reconcile, spend intelligence, and role dashboards + escalations — all on one budget spine with central activity logging, soft-delete/restoration and AI throughout.

## Already in place (reused across sprints)
Central activity logging (Module 15 ✅), notifications infra, restoration, policies, FileStorage, AI agent layer, Supplier module (onboarding/KYC/risk/screening/scorecards/SCAR/RFx/PO/invoices/spend-leakage), Contract module (CLM + approval engine), reports/analytics/scheduled-reports.
