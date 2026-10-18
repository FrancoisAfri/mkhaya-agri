# Procurement Suite — Best-in-Class Roadmap (Contract & Supplier Management)

Goal: take Contract Management + Supplier Management beyond Coupa / SAP Ariba /
Icertis / Ironclad parity. Delivered in waves; each wave is verifiable (migrate,
lint, build, functional test).

Status legend: ✅ done · 🔜 planned

---

## Wave 1 — P0 quick wins + flagship AI ✅ (DONE)

1. **Expiry & renewal engine** ✅
   `app/Console/Commands/Procurement/ProcurementExpiryAlertsCommand.php` (`procurement:expiry-alerts`, scheduled daily 06:00 in `bootstrap/app.php`).
   - Auto-expires contracts past `end_date` (status→expired, status-history logged).
   - Advance renewal alerts at 90/60/30/14/7/1 days → `contract_notifications` + in-app notification to owner.
   - Supplier compliance-document expiry alerts at the same thresholds.

2. **Contract AI extraction** ✅ (live, provider: OpenAI via `App\Core\AI`)
   - `contract_ai` table + `ContractAi` model; `ContractAiService` (text from DB-stored PDF/DOCX/desc → AI → structured JSON).
   - Extracts summary, parties, key dates, obligations, payment/termination/renewal terms, **risk/compliance/complexity scores**, risk factors, key points, recommendations.
   - Obligations with due dates auto-promoted to **milestones**.
   - "Analyze with AI" button + insights panel on the contract Show page (`contracts.analyze`).

3. **Supplier fraud / duplicate-vendor / COI detector** ✅
   - `supplier_risk_flags` table + `SupplierRiskFlag` model + `SupplierRiskService` (runs after-commit via `SupplierObserver`).
   - Detectors: duplicate banking (critical), duplicate registration (high), duplicate email (medium), conflict-of-interest vs employee email (high), supplier-bank == employee-bank (critical).
   - Risk panel + critical alert on supplier Show; open-flags stat on dashboard; resolve/dismiss (`suppliers.risk-flags.resolve`).

4. **E-signature wiring** ✅
   - "Send for signature" on contract Show → creates + sends an ESign envelope (linked by `related_type`/`related_id`), signer status shown (`contracts.sign`). Contract document remains downloadable from the DB.

All audited (LogsActivity + AuditRequests), soft-deletable, and auto-listed in `/settings/restoration`.

---

## Wave 2 — Repository intelligence & self-service ✅ (DONE)

- **Full-text search** across contracts & suppliers (keyword/ILIKE) ✅ — `ContractSearchController` + `Search/Index` page (ribbon "Search"); supplier `advanced` search made real (company/registration/email/status/industry). *(Semantic/embedding search deferred to the Wave-4 copilot.)*
- **Supplier self-service portal** ✅ — public token routes `routes/modules/supplier-portal.php` (`supplier.portal.*`), `portal_token` on suppliers, `SupplierPortalController` + `Portal/Index` page: view/update profile, upload (DB-stored) & download documents.
- **Clause library + playbook + AI deviation** ✅ — `contract_clauses` + `contract_clause_analyses`; `ContractClauseService` (AI compares a contract to the approved library → missing required clauses + deviations with risk); "Clause Library" ribbon, "Playbook check" button → `Clauses/Deviation` page.
- **Obligation tracking** ✅ — `contract_obligations` register (owner, due date, status, source manual|ai); AI extraction now populates it; obligations board on the contract page; due-date alerts added to `procurement:expiry-alerts`.
- **Contract risk scoring surfaced** ✅ — AI risk score chip in the contract list + on the Show page.

## Wave 3 — Risk, performance & compliance depth ✅ (DONE)

- **AI sanctions / PEP / adverse-media screening** ✅ — `supplier_screenings` + `SupplierScreeningService` (AI assesses company + directors → risk level, sanctions/PEP/adverse-media hits, recommendations). "Screen" button + Compliance tab panel on the supplier page; high-risk count on the dashboard. *(Preliminary AI triage — confirm against a licensed feed before action.)*
- **Certificate expiry auto-extract (AI)** ✅ — `SupplierDocumentAiService` reads an uploaded cert (PDF/DOCX) and auto-detects the expiry date; runs automatically on upload (when no date given) and via a per-document "Detect expiry" button → feeds the Wave-1 alert engine.
- **Supplier scorecards + SCAR** ✅ — `supplier_scars` (corrective-action workflow, auto SCAR numbers, status lifecycle) with a "Corrective Actions" ribbon; computed **Scorecard** page (A–D band from appraisal avg + open SCARs + risk flags).
- **Segmentation** ✅ — `segment` (strategic/preferred/approved/tail) on the supplier, set from the Compliance tab, shown as a header chip.
- **Entity resolution / dedup** ✅ — fuzzy company-name matching (normalises Pty/Ltd/etc., ≥85% similarity) raises a `potential_duplicate` flag; `parent_id` hierarchy with a parent-company selector + "Subsidiary of …" chip.
- **B-BBEE scorecard** ✅ — `supplier_bbbee` with level, black/black-women ownership, certificate expiry, and **auto-derived procurement recognition %** (SA generic scorecard mapping); edited from the Compliance tab.

## Wave 4 — Leapfrog (beats point-solutions) ✅ (DONE)

- **Cross-module AI copilot** ✅ — `ProcurementCopilotService` assembles a live snapshot of contracts + suppliers + obligations + risk/compliance and answers natural-language questions over the whole graph; chat page (ribbon "AI Copilot") with history. This is the leapfrog — point-solutions can't because they silo CLM from SRM.
- **Agentic renewal briefs** ✅ — `ContractAiService::draftRenewalBrief` produces a renew/renegotiate/exit recommendation + rationale + next steps ("Renewal brief (AI)" button + panel). Proactive generation hooks into the daily `procurement:expiry-alerts` thresholds.
- **Sourcing / RFx + AI bid evaluation** ✅ — `rfx_events` / `rfx_invitations` / `rfx_responses`; create RFx, invite suppliers, record responses, **AI scores & ranks bids** (`RfxEvaluationService`); award. Ribbon "Sourcing (RFx)".
- **Spend & PO/invoice linkage + leakage** ✅ — `purchase_orders` + `supplier_invoices` linked to contracts/suppliers; **off-contract spend (leakage) report** (ribbons Purchase Orders, Invoices, Spend Analysis).
- **Multi-currency + governing law / jurisdiction** ✅ — `currency`, `governing_law`, `jurisdiction` on contracts; currency-aware formatting; captured on create, shown on the contract page.

---

## Wave 5 — Reporting suite + request-driven contracts ✅ (DONE)

- **Reports** (`/contracts/reports`, `/suppliers/reports`) — tabbed data tables per report key with **PDF / Excel / CSV export** (DomPDF + Maatwebsite Excel via shared `TabularExport` + `reports.tabular` view); report data comes from `ContractReportService` / `SupplierReportService` (also reused by the scheduled command).
- **BI Analytics** (`/contracts/analytics`, `/suppliers/analytics`) — KPI tiles + **VueApexCharts** (status/segment/risk donuts, monthly/onboarding trend lines, type & top-supplier/spend bars, doc-expiry buckets) with period/status/type filters.
- **Scheduled Reports** (`/contracts/scheduled-reports`, `/suppliers/scheduled-reports`) — CRUD on shared `procurement_scheduled_reports`; `procurement:send-scheduled-reports` (hourly) builds due reports as PDF/Excel and emails recipients; `computeNextRun()` handles daily/weekly/monthly.
- **Request-driven contracts** — the new-contract page now **requires an approved Contract Request** (dropdown lists approved, unlinked requests; prefills title + value; links `contract_request_id`).
- Plus from the prior turn: contract **templates** page + template chooser + rich-text body, **bulk upload**, and **background AI processing** with "processing… you'll be notified" + completion notifications.

## Status: all waves delivered ✅
Both modules now span the full best-in-class footprint (CLM + SRM + sourcing + spend) with **9 live AI functions** (contract extraction & scoring, obligation extraction, clause/playbook deviation, renewal briefs, supplier sanctions/PEP/adverse-media screening, certificate expiry auto-extract, RFx bid evaluation, fraud/duplicate detection, cross-module copilot). Remaining future depth: semantic/embedding search, e-auctions, ESG/financial data feeds, multi-language — all additive on this foundation.

---

## Three unfair advantages we're building toward
1. One unified data graph → a copilot that reasons across procurement + HR + finance.
2. SA-native compliance (CIPC/SARS/B-BBEE/POPIA) built in, not bolted on.
3. Agentic, proactive operations on top of the approval + audit + notification infra already in place.
