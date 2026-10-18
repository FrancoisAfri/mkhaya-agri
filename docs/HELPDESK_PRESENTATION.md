# Mkhaya Hub — Service Desk & ITSM
### Client Presentation Content (PowerPoint-ready)

> How to use this deck: each numbered block = one slide. **Title** and **bullets** go on the slide; *Speaker notes* are for you. Keep 4–6 bullets per slide. Colours: use your brand palette; suggested accent for status = green/amber/red.

---

## SLIDE 1 — Title

**Mkhaya Hub Service Desk**
Enterprise IT Service Management — powered by AI & no-code automation

- One platform for support, ITIL operations, and self-service
- Built inside the Mkhaya Hub ERP suite — not a bolt-on
- *Presented by: [Your name] · [Date]*

*Speaker notes: Open with the one-liner — "A complete, AI-first service desk that runs your entire support and IT operations from a single platform, and shares data with the rest of Mkhaya Hub."*

---

## SLIDE 2 — The Challenge

**Support today is fragmented and reactive**

- Tickets, email, chat and WhatsApp live in different tools
- Agents waste time triaging, categorising and routing manually
- No visibility into SLAs, trends, or the impact of an outage
- Knowledge is locked in people's heads, not reused
- Leadership lacks real-time insight into service health

*Speaker notes: Set up the pain. Most organisations run 3–4 disconnected tools and still fly blind. We consolidate and automate.*

---

## SLIDE 3 — The Solution at a Glance

**A single, AI-driven ITSM platform**

- **Omnichannel** ticketing (email, live chat, WhatsApp, Instagram, portal, API)
- **Full ITIL** — Incident, Problem, Change, Release, Request, CMDB, Event
- **AI everywhere** — triage, assistant, insights, knowledge generation
- **No-code automation** — visual workflow builder
- **Open** — REST API, webhooks, and a public status page
- **Governed** — RBAC, SLAs, full audit trail, POPIA-ready

*Speaker notes: This is the "wow" slide — show the breadth. Then the next slides go one capability at a time.*

---

## SLIDE 4 — ITIL Coverage Map

**Complete service-management lifecycle**

| Practice | Delivered |
|---|---|
| Incident Management | ✅ Tickets, SLA, AI triage, omnichannel |
| Service Request & Catalogue | ✅ Catalogue + multi-step approvals |
| Problem Management | ✅ Root-cause, incident linking |
| Change Management | ✅ Approval workflow, participants |
| Release Management | ✅ Change bundling, go/no-go, calendar |
| Event Management | ✅ Monitoring ingestion + correlation |
| Configuration (CMDB) | ✅ CIs, dependencies, impact analysis |
| Knowledge Management | ✅ KB + AI-generated FAQs |

*Speaker notes: Emphasise this is not "ticketing with extras" — it's genuinely full ITIL, which usually costs a fortune in ServiceNow/Jira licensing.*

---

## SLIDE 5 — Core Ticketing & Omnichannel

**Every conversation, one queue**

- Capture from **email, live chat, WhatsApp, Instagram, web portal, and REST API**
- Rich ticket detail: priority, impact, urgency, category, tags, attachments, watchers
- Threaded replies, internal notes, canned responses
- Merge duplicates, link assets, reopen tracking
- Customer satisfaction (CSAT) surveys on resolution

*Speaker notes: One inbox for everything. Agents never switch tools.*

---

## SLIDE 6 — SLA Management

**Never miss a commitment**

- Business-hours-aware SLA engine (respects working hours & holidays)
- Separate **first-response** and **resolution** targets per priority
- Automatic breach detection with approaching-breach warnings
- Escalation notifications to agents and supervisors
- SLA performance visible in reports and AI insights

*Speaker notes: The business-hours awareness is a real differentiator — SLAs pause overnight/weekends automatically.*

---

## SLIDE 7 — AI That Actually Works (1/2)

**AI at the point of work**

- **AI Triage** — suggests category, priority, impact, urgency & best technician on every ticket
- **AI Auto-Triage** — tickets from email/chat/API are classified automatically, with a suggestion note (never overrides a human)
- **AI Root-Cause Analysis** — narrates likely cause + recommendations on problems
- **AI Event Analysis** — summarises monitoring alerts and recommends action

*Speaker notes: Stress "assistive, not autonomous" — AI suggests, humans decide. It fills blanks, never overrides.*

---

## SLIDE 8 — AI That Actually Works (2/2)

**AI for knowledge & leadership**

- **AI Knowledge Assistant** — a chatbot that answers from your knowledge base & FAQs, **with cited sources** and honest "I'm not sure" when it doesn't know
- **AI FAQ Generation** — pick a category, AI drafts FAQs for review & publish
- **AI Insights** — detects trends, anomalies & recommendations from live ticket data
- **AI Report Narration** — plain-language executive summaries of service health

*Speaker notes: The assistant is grounded — no hallucinations, always cites where the answer came from. Insights turn raw numbers into decisions.*

---

## SLIDE 9 — Service Catalogue & Requests

**Self-service that scales**

- Publish requestable services with their own **intake forms, SLAs & pricing**
- Employees browse and request in a few clicks
- **Multi-step, sequential approval chains** — each approver notified only when it's their turn
- Approvers act via secure email links — **no login required**
- Auto-creates a fulfilment ticket for the delivery team

*Speaker notes: Think "New laptop", "Software access", "Onboarding" — standardised, approved, tracked. Overdue requests flagged automatically.*

---

## SLIDE 10 — Problem, Change & Release

**Operate like an enterprise IT team**

- **Problem Management** — group recurring incidents, capture root cause & workaround, AI assistance
- **Change Management** — risk-assessed changes with evaluator → approver → tester workflow and token-based external approvals
- **Release Management** — bundle approved changes, a **release calendar**, and a **go/no-go gate**

*Speaker notes: This is the stuff that prevents outages — controlled change, planned releases, learning from problems.*

---

## SLIDE 11 — Event Management

**From noise to action, automatically**

- Ingest monitoring/alert events via a secure API (`/api/v1/events`)
- A **correlation rules engine** decides what happens:
  - Create an incident · Correlate (de-duplicate a storm) · Suppress noise · Auto-resolve on recovery
- Repeated alerts collapse onto **one** incident with a recurrence counter
- Bridges your monitoring tools straight into the service desk

*Speaker notes: Turns an alert storm into a single actionable incident — and closes it automatically when the "all clear" arrives.*

---

## SLIDE 12 — CMDB & Impact Analysis

**Know what breaks what**

- Track Configuration Items (servers, apps, databases, services)
- Map dependencies between them
- **Blast-radius analysis** — instantly see every downstream service a failure impacts
- **CI health** — live count of open incidents affecting each item
- Link CIs to incidents, changes and problems

*Speaker notes: When something fails, you instantly see the full chain of impact — no more guessing what a server outage takes down.*

---

## SLIDE 13 — No-Code Automation Engine

**Build workflows by dragging, not coding**

- **Visual canvas** — trigger → conditions → actions on a drag-and-connect board
- Triggers: ticket created / updated / assigned / resolved / reopened / SLA breached / time-in-status
- Actions: assign, set priority/status/category, tag, note, escalate, notify, email, AI triage, webhook
- **Branching (Yes/No), waits/delays, and conditions** — real logic, no developer needed
- **Reusable templates** you can manage, save and share

*Speaker notes: This is a headline feature. "If a critical ticket sits unassigned for 2 hours, escalate and notify the manager" — built in minutes, no code.*

---

## SLIDE 14 — Knowledge & Self-Service

**Deflect tickets, empower users**

- Knowledge Base with categories, publishing workflow, and search
- FAQs organised by category — **AI-assisted authoring**
- AI Knowledge Assistant answers questions from your content
- Public self-service portal for submitting & tracking requests
- Every resolution becomes reusable knowledge

*Speaker notes: The best ticket is the one never raised. Self-service + AI assistant deflect volume.*

---

## SLIDE 15 — Open Platform: API & Integrations

**Connect anything**

- Versioned **REST API** (`/api/v1`) — incidents, problems, changes, KB, events
- Secure **token-based** access with read-only / read-write scopes
- **Outbound webhooks** — push ticket events to Slack, Zapier, Make or your systems (HMAC-signed for security)
- **OpenAPI specification** + in-app developer docs
- Rate-limited and monitored

*Speaker notes: We're not a walled garden. Integrate with your monitoring, chatops, and internal tools.*

---

## SLIDE 16 — Notifications, Your Way

**The right alert, the right channel**

- Channels: in-app, email, **SMS, Slack, Microsoft Teams**
- Per-user **notification preference centre** — each person chooses what they get and how
- Workspace-wide chat integration with a one-click "send test"
- Lifecycle alerts: assignment, resolution, SLA breach, approvals, automations

*Speaker notes: No notification fatigue — people tune exactly what reaches them.*

---

## SLIDE 17 — Public Status Page

**Transparent communication during incidents**

- A public, branded status page (`/status`)
- Live operational status per service component
- Incident updates: investigating → identified → monitoring → resolved
- History of past incidents
- Builds trust and cuts "is it down?" tickets

*Speaker notes: Like statuspage.io, built in. During an outage, one page keeps everyone informed and reduces inbound tickets.*

---

## SLIDE 18 — Reporting & Analytics

**Decisions backed by data**

- Real-time dashboards: volumes, open/resolved, SLA breach rate, CSAT
- Agent performance leaderboard
- Trends by category, priority, and recurring issues
- AI-generated executive insights & recommendations
- Exportable ticket data (CSV) with applied filters

*Speaker notes: From front-line agent stats to boardroom-ready AI summaries.*

---

## SLIDE 19 — Security, Governance & Compliance

**Enterprise-grade by default**

- Role-based access control (RBAC) with granular permissions
- Full audit trail on every change (who, what, when)
- **POPIA-ready** — data anonymisation & agent off-boarding tools
- Secure external actions via expiring signed links
- Part of a single, governed ERP data model

*Speaker notes: For a client conversation, compliance & audit matter as much as features. Everything is logged.*

---

## SLIDE 20 — Why Mkhaya Hub Service Desk

**The differentiators**

- **AI-first**, not AI-bolted-on — triage, assistant, insights, generation
- **Full ITIL** without enterprise-tool pricing
- **No-code automation** anyone can build
- **Truly omnichannel** + open API & webhooks
- **One suite** — shares HR, Asset, Finance data across Mkhaya Hub
- **Transparent** — public status page & self-service

*Speaker notes: Summarise the wedge: same power as ServiceNow/Jira SM, integrated into your ERP, at a fraction of the cost and complexity.*

---

## SLIDE 21 — Roadmap

**Where we're heading**

- Native mobile app for agents (API already in place)
- Deeper AI: predictive SLA risk, auto-suggested KB articles
- Extending the automation engine platform-wide (HR, Facilities)
- Additional integrations & marketplace connectors

*Speaker notes: Show momentum — the platform keeps getting smarter.*

---

## SLIDE 22 — Close / Call to Action

**Let's transform your service desk**

- Consolidate your tools into one AI-driven platform
- Cut resolution times and SLA breaches
- Empower self-service and reduce ticket volume
- **Next step:** a tailored demo with your real workflows

- *Contact: [Your name] · [Email] · [Phone]*

*Speaker notes: End on the offer — a hands-on demo mapped to their actual processes.*

---

# APPENDIX

## A. One-paragraph elevator pitch
> Mkhaya Hub Service Desk is a complete, AI-first IT Service Management platform. It unifies omnichannel support (email, chat, WhatsApp, Instagram, portal, API) with full ITIL practices — Incident, Problem, Change, Release, Request, Event and CMDB — and layers AI on top for triage, a grounded knowledge assistant, and executive insights. A no-code visual automation engine, an open REST API with webhooks, a public status page, and enterprise-grade RBAC/audit make it ready for real operations — all inside the broader Mkhaya Hub ERP suite.

## B. 30-second verbal pitch
> "Most teams juggle a ticketing tool, a chat app, a monitoring system, and spreadsheets. Mkhaya Hub Service Desk replaces all of that with one AI-driven platform. AI triages every ticket, a chatbot answers users from your knowledge base, and a drag-and-drop builder automates your workflows — no code. You get full ITIL, an open API, and a public status page, all governed and audited, at a fraction of enterprise-tool cost."

## C. Feature checklist (for a hand-out or backup slide)
- Omnichannel intake: Email · Live Chat · WhatsApp · Instagram · Portal · API
- Incident, Problem, Change, Release, Request, Event, CMDB
- Business-hours SLA engine with escalations
- AI: triage · auto-triage · assistant · insights · root-cause · event analysis · FAQ generation
- Service Catalogue with forms, SLAs, pricing & multi-step approvals
- Event correlation: create/correlate/suppress/auto-resolve
- CMDB blast-radius impact & CI health
- No-code visual automation with branching, waits & templates
- REST API (/api/v1), scoped tokens, HMAC webhooks, OpenAPI docs
- Notifications: in-app, email, SMS, Slack, Teams + per-user preference centre
- Public status page with incident lifecycle
- Dashboards, agent performance, CSAT, CSV export
- RBAC, full audit log, POPIA tools

## D. Suggested demo flow (10 minutes)
1. Raise a ticket from the portal → watch **AI auto-triage** classify it
2. Open the ticket → show **AI triage panel** + KB suggestions
3. Ask the **AI Assistant** a question → grounded answer with sources
4. Build a rule in the **automation canvas** (drag Trigger → Branch → Actions)
5. Submit a **service request** → show the approval chain
6. Show the **CMDB blast-radius** on a critical CI
7. Open **AI Insights** → generate an executive summary
8. Finish on the **public status page**

## E. Talking-point stats to gather from the client (personalise the deck)
- Current number of tools used for support
- Average first-response & resolution time today
- Current SLA breach rate
- % of tickets that are repeat/known issues (deflection opportunity)
- Monthly ticket volume (automation ROI)
