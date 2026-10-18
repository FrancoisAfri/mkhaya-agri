# ITSM / HelpDesk — Implementation Plan to Close the RFI Gaps

**Context.** The HelpDesk module already covers ITIL Incident + Problem + Change + Knowledge Base + SLA + omnichannel (37 models, 40 tables, 42 screens, 22 permissions). This plan closes the *gaps* identified in the RFI comparison, ordered by ROI and self-containment. Each item names the existing code it reuses, so estimates are realistic.

Effort key: **S** ≈ 1–2 days · **M** ≈ 3–5 days · **L** ≈ 1–2 weeks · **XL** ≈ 3–6 weeks.

---

## PHASE 1 — Bring Incident Management (Feature 1.1) to 100% *(highest ROI, self-contained)* — ✅ DONE + world-class pass
> AI triage (Create + Show), root-cause on Problems, SMS/Teams/Slack channels + settings, CSV export — all shipped, built and verified.
>
> **World-class hardening:** (a) **AI auto-triage** — an `HdTicketObserver` dispatches `HdAutoTriageJob` for tickets arriving via non-agent channels (email/chat/api/portal); it posts AI suggestions as an internal note and fills only the blank fields (never overrides a human). (b) Slack/Teams/SMS now also fire on **ticket assigned / resolved** (`HdTicketLifecycleNotification`), plus a **"Send test"** button validating the webhooks live.


### 1.1 AI Ticket-Triage Suite — **M**
The biggest functional gap. Current AI (`app/Modules/HelpDesk/AI/HdSupportAI.php`) is chat-assist; add ticket triage.
- **New** `HdTriageAI` (extends `BaseModuleAI`, mirrors `HdSupportAI`):
  - `categorise(HdTicket)` → suggested `category_id` from `HdCategory` list.
  - `prioritise(HdTicket)` → suggested `priority_id` + impact/urgency.
  - `suggestTechnician(HdTicket)` → best agent (from `HdAgentPresence` availability + past category resolutions).
  - `suggestResolution(HdTicket)` → draft resolution + matching `HdKbArticle`s (reuse `suggestKbArticles`).
  - `rootCause(HdProblem|HdTicket)` → AI root-cause narrative (writes `HdProblem.root_cause`).
- **Wire-in:** call `categorise`/`prioritise`/`suggestTechnician` on `TicketController@store` and `@create` (return as *suggestions*, applied on accept — never silently overwrite).
- **UI:** an "AI Suggestions" panel on `Create.vue` + `Show.vue` (accept/dismiss chips). Routes under existing `POST /helpdesk/ai/*` (already rate-limited).
- **Acceptance:** logging a ticket shows AI category/priority/technician/resolution suggestions; accepting applies them; audit records the AI source.

### 1.2 Notification Channels: SMS / Teams / Slack — **M**
Today: Email + database + push (`HdPushService`, `hd_device_tokens`). Add three channels.
- **SMS:** new `SmsChannel` (Twilio/Vonage via an integration credential row, mirror `CrmWhatsAppService` config pattern).
- **Teams / Slack:** incoming-webhook channels (`TeamsChannel`, `SlackChannel`) — per-workspace webhook URL in HelpDesk settings.
- **Wire-in:** extend `HdSlaNotification::via()` and ticket lifecycle notifications (created/assigned/resolved/escalated) to honour per-user + per-event channel prefs.
- **Settings UI:** a "Notifications" settings page (channel toggles + webhook URLs), reuse `HdSettingsController` pattern.
- **Acceptance:** an SLA breach / assignment fires email + in-app + (if configured) SMS/Teams/Slack.

### 1.3 Export — **S**
- **Permission:** add `helpdesk.tickets.export` (in `RolesAndPermissionsSeeder`).
- **Endpoint:** `GET /helpdesk/tickets/export` (+ reports export) → streamed CSV/XLSX, reuse the CRM report-export streaming pattern (`ReportController::export`).
- **UI:** "Export" button on `Index.vue` and `Reports/Index.vue` (respects current filters).
- **Acceptance:** filtered ticket list exports to CSV/XLSX, gated by the new permission.

### 1.4 (Optional) 1:1 schema models — **S–M, only if the contract mandates exact tables**
Current columns/JSON already cover these functionally. Build only if the RFI requires literal tables:
- `HdTicketType` (`hd_ticket_types`) replacing the impact/urgency columns' "type" role.
- `HdTicketSource` (`hd_ticket_sources`) replacing the `source` string.
- `HdResolution` (`hd_resolutions`) replacing `resolution_notes`.
- `HdWatcher` (`hd_ticket_watchers`) replacing `cc_emails` JSON (per-user watch + notify prefs) — recommended even if optional, since it enables per-watcher channel routing.
- `IncidentHistory`: keep Spatie activity log (already the audit source); add a read-API view if a dedicated endpoint is required.

**Phase 1 deliverable:** Incident Management Feature 1.1 at 100% RFI coverage (AI triage, all notification channels, export, full audit).

---

## PHASE 2 — REST API Gateway + Mobile *(API-first contract)* — **L** ✅ (core delivered)
RFI expects `/api/incidents…`; today actions are Inertia web routes (+ one mobile controller).
- ✅ **`/api/v1` REST controllers** for incidents/problems/changes/kb — thin wrappers over existing `TicketService`, Sanctum-authenticated, JSON resources (`app/Modules/HelpDesk/Http/Resources/*`).
  - ✅ `POST/GET /api/v1/incidents`, `GET/PUT/DELETE /api/v1/incidents/{id}`, `/assign`, `/resolve`, `/close`, `/comment` — the RFI's 9 verbs, live and smoke-tested.
  - ✅ Problems (CRUD-lite), Changes (read), KB (read + `?q=` search).
- ✅ **OpenAPI spec** — `docs/helpdesk-api-openapi.yaml` (hand-authored 3.0.3). ✅ **Token management UI** — HelpDesk → Settings → **API Access** (`HdApiTokenController`, read-only vs read-write scopes).
- ✅ **Token scoping** — endpoints gated by the user's RBAC permission **and** the Sanctum token ability (`helpdesk:read` tokens are 403'd from writes).
- ⚙️ **Also fixed:** the `api` middleware group referenced `throttle:api` with no limiter registered (latent 500 on every API route) — added `RateLimiter::for('api', 120/min)` in `AppServiceProvider`.
- ✅ **World-class pass:** **outbound webhooks** — `hd_webhooks` subscriptions, HMAC-SHA256-signed queued deliveries (`X-Hd-Signature`), retry-with-backoff, fired from the shared `HdTicketObserver` on created/assigned/resolved/sla_breached, managed in a Settings → **Webhooks** tab. **`GET /api/v1/meta`** discovery endpoint (lookup IDs + resource index) and the **OpenAPI spec served** at `GET /api/v1/openapi.yaml` (now documents events + meta + webhooks).
- ⏳ **Remaining (optional):** extend `Api/HdMobileController` to full parity + push; generated (vs hand-authored) OpenAPI.
- **Acceptance:** ✅ the RFI's 9 incident endpoints respond as documented REST; tokens scoped to permissions.

---

## PHASE 3 — Complete Core ITSM (Volume 1 gaps) — ✅ DONE
Reuse the ticket engine + the SLA service + the Change workflow pattern.

| Module | Status | Delivered |
|---|---|---|
| **Service Catalogue** | ✅ | `HdCatalogItem` (per-item form schema, SLA, price, approval chain), browse + manage UI, "Request" flow. Seeded starter catalogue (`HdItsmSeeder`). |
| **Request Management** | ✅ | `HdServiceRequest` + participants, `RequestService` multi-step **sequential approval** → fulfilment, token-gated email approval portal (mirrors Change), optional linked fulfilment incident. |
| **Event Management** | ✅ | `HdEvent` + `HdEventRule` engine, ingestion `POST /api/v1/events`, auto create-incident / correlate (dedup) / suppress. Events + Rules UI. |
| **Release Management** | ✅ | `HdRelease` bundling `HdChange`s (pivot), release calendar, go/no-go gate. Index (list + calendar) + Show. |
| **CMDB (true)** | ✅ | `HdCi` + `HdCiRelationship` dependency graph + polymorphic `HdCiLink` to incidents/changes/problems, **impact analysis** view, soft `asset_id` link to the Asset module. |

Cross-cutting: 17 new permissions (`helpdesk.catalogue/requests/events/releases/cmdb.*`), 5 new nav ribbons, all migrations guarded + applied, `npm run build` green, workflows smoke-tested (approval chain, event→incident, CMDB impact, release calendar).

---

## PHASE 4 — Automation Engine for HelpDesk (independent copy of the CRM engine) — ✅ DONE (HelpDesk engine)
Rather than extract the CRM engine into a shared service (which would couple the modules), HelpDesk gets its **own fully independent** automation engine — the CRM design *copied*, not shared. **CRM is untouched.**
- ✅ **Tables:** `hd_automations`, `hd_automation_runs`, `hd_flow_runs` (graph + steps columns). **Models:** `HdAutomation` (TRIGGERS / ACTION_TYPES / CONDITION_FIELDS / STEP_TYPES / TEMPLATES), `HdAutomationRun`, `HdFlowRun`.
- ✅ **Engine** (`HdAutomationEngine`): trigger dispatch → trigger-config gate → condition eval (10 operators, ALL/ANY) → run flat actions **or** a multi-step flow **or** a visual-canvas **graph** (nodes/edges) with **wait / condition-gate / branch (YES/NO)** control-flow, a 500-hop cycle guard, `hd_flow_runs` state + `processDueFlows()` resume, and `hd_automation_runs` logging.
- ✅ **Triggers:** ticket.created / updated / assigned / status_changed / resolved / reopened / sla_breached + time_in_status (scheduler). **Actions:** assign, set status/priority/category, add tags, add note, escalate, notify agent, email requester, **AI triage**, HTTP request. Fired from the shared `HdTicketObserver` with a re-entrancy guard (`HdAutomationEventBus`); ticket writes are quiet so automations never loop.
- ✅ **Scheduler:** `hd:run-automations` (every 15 min) fires time-based triggers + resumes paused flows.
- ✅ **Visual canvas UI:** `Automations.vue` + `AutomationCanvas.vue` (@vue-flow/core) + `AutomationActionFields.vue` — free-form node graph with Action/Wait/Gate/Branch, condition builder, and 5 starter templates. Nav ribbon added.
- ⏳ **Remaining (future):** generalise to a platform `App\Core\Automation` for HR/Facilities; Forms Builder; ESM workspaces.

---

## PHASE 5 — AI Platform (Volume 3) — ✅ DONE
Built on `BaseModuleAI`, extending the module's existing AI (triage, auto-triage, root-cause, KB suggestions, event analysis, FAQ generation).
- ✅ **Classification & Routing** — AI triage + **auto-triage** on inbound tickets (Phases 1 & 1+2 world-class pass).
- ✅ **Knowledge Assistant (chatbot over KB+FAQ)** — `HdSupportAI::answerFromKnowledge()` does grounded, cited Q&A: keyword-retrieves published KB articles + active FAQs, answers strictly from them, flags low confidence. Agent-facing chat page (`AiAssistant.vue`) + `POST /helpdesk/ai/ask`.
- ✅ **Trend Detection + Recommendations + Report Generation** — `HdInsightsAI::detectTrends()` narrates real aggregated metrics (30d vs prior-30d volume, SLA-breach rate, CSAT, reopen rate, by-category/priority, recurring subjects) into an executive summary + trends + anomalies + recommendations. `Insights.vue` page (`GET /helpdesk/insights`, `POST /helpdesk/insights/generate`).
- ✅ **Root-Cause Analysis** — from Phase 1.1. **Knowledge Search** — `suggestKbArticles` + the assistant retrieval.
- Two nav ribbons added (AI Assistant, AI Insights). Metrics compute on live data; AI calls have 503/429 fallbacks.

---

## PHASE 6 — Platform Hardening (Volume 4) — ✅ DONE (mobile deferred)
Mostly present (User Mgmt, RBAC, SLA, Integrations, Reporting, Dashboards, Security, Audit, Settings). Remaining items closed:
- ✅ **API gateway docs / rate-limits / versioning** — `/api/v1` (Phase 2), `api` rate limiter 120/min, served OpenAPI, and an in-app **API Docs page** (`HdApiDocsController` + `ApiDocs.vue`): grouped endpoint reference, auth + rate-limit notes, token-management link, spec download, curl example.
- ✅ **Notification preference centre** — `hd_notification_preferences` + a per-user **"My Notifications"** page (in-app / email / SMS per event). A shared `AppliesNotificationPreferences` trait rewires every HelpDesk notification's `via()` to honour the recipient's prefs (verified: email-off → in-app only; all-off → nothing sent). Workspace Slack/Teams stay governed by global settings.
- ✅ **Status page** — public, unauthenticated `/status` (`Public/Status.vue`) with an overall banner, per-component operational status, active + past incidents; admin management (`HdStatusController` + `StatusAdmin.vue`) for components & incident posts. 5 default components seeded.
- ⏳ **Full mobile** — the mobile API (`Api/HdMobileController`) exists; full parity is deferred (needs a native client to build against).

---

## Recommended sequencing
1. **Phase 1** (M, ~2 weeks) — closes Feature 1.1 to 100%; immediately demoable against the RFI.
2. **Phase 2** (L) — API/mobile contract.
3. **Phase 3** (L–XL) — Request/Catalogue/Release/Event/CMDB to complete Core ITSM.
4. **Phase 4** (XL) — generalise engines; unlocks ESM cheaply.
5. **Phases 5–6** — AI platform + hardening, in parallel where staffed.

## Verification (each phase)
- Unit/feature tests per new controller/service; `php artisan test`.
- Seed defaults idempotently (follow `HdDefaultsSeeder` / template-seeder pattern, seeded inactive where they trigger side effects).
- Migrations guarded (`hasColumn`/`hasTable`), `php artisan migrate` clean, `npm run build` green.
- Each RFI acceptance criterion mapped to a QA test case.
