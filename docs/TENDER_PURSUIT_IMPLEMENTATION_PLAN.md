# Tender Pursuit Management — Implementation Plan

A new top-level module under **Operations & Support** (`code_name: tender_pursuit`, path `/tenders`), mirroring the ITSM build approach: phased, each phase shippable, reusing Mkhaya Hub patterns (BaseModuleAI, Spatie ActivityLog, RBAC, nav ribbons, Inertia/Vuetify).

## Phasing (18 spec modules → delivery phases)

### Phase 1 — Foundation *(this build)*
- **Tender Register** (spec Module 2) — the heart: full tender record (basic info, dates, stage, status, financials, team, AI scores), list with filters, create, full detail/edit.
- **Opportunity Dashboard** (Module 1) — real KPIs (open, closing this week, submitted, won/lost/cancelled, award pending, win rate, pipeline value, values submitted/won) + charts (pipeline funnel by stage, monthly wins/losses, win-rate by department, upcoming deadlines).
- Module registration, nav ribbons, permissions.

### Phase 2 — Workflow & Documents ✅ DONE
Surfaced as tabs on the tender workspace (Show page: Details · Bid/No-Bid · Proposal Plan · Documents · Compliance), with a live **% ready** indicator.
- ✅ **Bid/No-Bid Decision** (M5) — 10-question scored decision matrix + **AI recommendation** (`TenderAI::bidNoBid`) returning bid/no_bid/review with win-probability, risk & profit scores; saved per tender.
- ✅ **Proposal Planning** (M6) — one-click **default plan** (8 stages) + task CRUD, status (todo/in-progress/done/blocked), owner, due dates auto-back-scheduled from closing date, progress bar.
- ✅ **Document Management** (M7) — upload/download/delete bid documents (DB-stored via FileStorage), 14 categories, **expiry tracking** with expired badges + count.
- ✅ **Compliance Checklist** (M8) — one-click **standard 12-item checklist** + item CRUD, status cycle (pending/met/missing/expired/na), met-progress bar.
- Tables: `tender_bid_decisions`, `tender_tasks`, `tender_compliance_items`, `tender_documents`. Verified end-to-end.

### Phase 3 — Calendar, Submission & Award ✅ DONE
- ✅ **Tender Calendar** (M4) — new nav ribbon + page: **month grid + agenda** views, prev/next/today nav, all key dates rolled up (closing, briefing, questions, evaluation, award, contract start), **colour-coded** by urgency (red=today, orange≤3d, amber≤7d, green future) and by type; click an event → open the tender.
- ✅ **Submission Management** (M12) + **Award Tracking** (M13) — a "Submission & Award" workspace tab: submission status/method/date/confirmation-number/notes, and award status (awaiting_evaluation → preferred_bidder → awarded/lost…), winning company/amount, reason lost, client feedback, lessons learned. Fields on the tender.
- ✅ **Notifications & Reminders** (M16) — `TenderReminderNotification` (in-app + email) + `tenders:send-reminders` command (scheduled daily 06:45) firing at **30/14/7/3/1/0-day** marks to the whole bid team, **once per mark** (dedup via `reminders_sent`). Verified: fires + records + doesn't resend.
- Competitor capture is folded into Phase 5 (Competitor Intelligence).

### Phase 4 — AI Suite ✅ DONE
Built on `TenderAI` (BaseModuleAI). New ribbons: AI Discovery, Knowledge Library, AI Copilot. New tender tabs: Proposal, Pricing.
- ✅ **AI Tender Discovery** (M3) — discovery inbox; AI **match-scores** opportunities against the company profile (from the Knowledge Library) with pursue/review/skip; one-click **convert to tender**.
- ✅ **AI Proposal Writer** (M9) — generate any of 14 proposal sections in 5 tones, grounded in relevant Knowledge Library content; edit + save per tender.
- ✅ **Pricing Module** (M10) — BOQ/cost-sheet line items (labour/materials/equipment/…), live subtotal → markup → discount → VAT → **bid total**, plus an **AI price-range** suggestion.
- ✅ **Knowledge Library** (M17) — reusable content (company profile, methodologies, past projects, CVs, standard answers…); feeds the writer & discovery scoring.
- ✅ **AI Executive Copilot** (M15) — chat that answers NL questions ("which close next week?", "which need pricing?", "bids over R20m?") over a live pipeline snapshot.
- Tables: `tender_discoveries`, `tender_proposal_sections`, `tender_knowledge_items`, `tender_pricings`, `tender_pricing_items`. Verified end-to-end.

### Phase 5 — Analytics, Collaboration, Intelligence ✅ DONE
New ribbons: Analytics, Competitors. New tender tab: Collaboration (+ competitor capture on the Award tab).
- ✅ **Tender Performance Analytics** (M14) — win/loss rate, submission success, pipeline value, revenue won, avg bid cycle, and win-rate by industry/client/department, with **AI insights + recommendations** (`TenderAI::analyticsInsights`).
- ✅ **Collaboration** (M11) — per-tender comments with **@mentions** (notify mentioned users, in-app + email) and a **decision log** (comments flagged as decisions).
- ✅ **Competitor Intelligence** (M18) — capture competitors per tender (name, winner, bid amount) with winner-uniqueness; a module-wide **competitor register** (encounters / wins / win-rate-vs-us / avg bid) with **AI pattern detection** (`TenderAI::competitorPatterns`).
- Tables: `tender_comments`, `tender_competitors`. Verified end-to-end.

### Phase 6 — 🏆 AI Tender War Room *(the differentiator)* ✅ DONE
A **War Room** tab (first tab on every tender) with a one-click **"Run AI War Room"**:
- ✅ `TenderAI::warRoomAnalysis` reads the tender (details + attached doc titles + available knowledge categories) and extracts **requirements, deadlines, forms, evaluation criteria, risks, our weaknesses, a win estimate, suggested knowledge to reuse**, plus a recommended checklist & task plan.
- ✅ **Auto-builds** the compliance checklist and proposal plan from the analysis (when empty), and seeds the win estimate/probability.
- ✅ **Live submission-readiness score** — a weighted 0–100 gauge across Bid decision (10) · Compliance (25) · Proposal (20) · Documents (20) · Pricing (15) · Plan (10), with a component breakdown; the header chip shows it on every tab. *Verified deterministically: empty 0% → filled 73%.*
- Persisted on the tender (`war_room` JSON + `war_room_run_at`); graceful AI fallback (no partial writes on failure).

---

## ✅ Status: all 6 phases delivered (18 spec modules + War Room). Remaining spec "monitor external portals" for M3 is a live-scraping/integration concern (the discovery **inbox + AI scoring + convert** are built); wire real portal feeds when integration credentials are available.

## Cross-module integrations (per spec)
CRM (leads → opportunities) · Document Mgmt · Project Mgmt (proposal projects) · HR (team by skills/availability) · Contract Mgmt (award → contract) · Finance (bid cost, revenue, profitability) · Helpdesk (internal requests during bid prep).

## Conventions
- Code: `app/Modules/TenderPursuit/{Models,Http/Controllers,Services,AI,Notifications}`.
- Tender numbers: `TND-00001`. Money: `decimal(15,2)`, ZAR default, multi-currency later.
- Reuse `BaseModuleAI` for all AI; suggestions only (human-approved), consistent with the ITSM AI approach.
