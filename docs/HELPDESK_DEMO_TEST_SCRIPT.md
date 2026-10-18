# Help Desk (ITSM) — Demo & Test Script

**Menu location:** `Operations & Support → Help Desk`
**Use this as both a pre-demo test checklist and a live walkthrough.** Each step lists the **Action** and the **Expected result** so you can verify before presenting.

---

## 0. Pre-flight (do 15 min before) ✅

| # | Action | Expected result |
|---|--------|-----------------|
| 0.1 | Log in as an admin/agent user | Dashboard loads, top nav shows **Operations & Support** |
| 0.2 | Confirm seeders ran (catalog, automations, statuses) | Steps below have data; no empty screens |
| 0.3 | Open **Operations & Support → Help Desk** menu | Sub-items: Dashboard, All Tickets, Catalogue, Knowledge Base, Live Chat, Automations, Problems, Changes, Releases, CMDB, Events, Analytics, etc. |
| 0.4 | Quick AI check: open **Help Desk → AI Assistant**, ask "How do I reset a password?" | Either an AI answer, **or** a graceful "AI busy/unavailable" message (see §9 — have a fallback ready) |

> **AI note:** the AI provider (OpenAI) may be rate-limited. If you see *"AI is busy / unavailable"*, it's not a failure — mention it uses a live LLM and show the pre-generated result instead. Test the AI steps **before** presenting so you know which are live.

---

## 1. The Story (15-min golden path) — "One incident, end to end"

This is the spine of the demo. Tell it as a narrative.

### 1.1 Raise an incident
- **Action:** Help Desk → **All Tickets → Create Ticket**. Subject: *"Cannot connect to VPN"*, description, set requester, submit.
- **Expected:** Ticket created with an auto number, status **Open/New**, an **SLA timer** starts, priority/category set.

### 1.2 AI triage
- **Action:** On the ticket, click **AI Triage / Suggest** (or Help Desk → AI Assistant).
- **Expected:** AI proposes a **category, priority, and suggested response / next steps**. *(Live AI — have a screenshot fallback.)*

### 1.3 Assign & collaborate
- **Action:** Assign the ticket to an agent (or agent group). Add an internal note; add a public reply.
- **Expected:** Assignment recorded; internal note is agent-only; reply visible to requester. SLA still ticking.

### 1.4 Knowledge & canned response
- **Action:** Insert a **canned response**, or link a **Knowledge Base** article.
- **Expected:** Response inserted; KB article linked/searchable.

### 1.5 Resolve within SLA
- **Action:** Set status to **Resolved/Closed**, add resolution.
- **Expected:** SLA shows **met/breached**; ticket moves to resolved; resolution captured.

### 1.6 Prove it in analytics
- **Action:** Help Desk → **Analytics / Performance**.
- **Expected:** Ticket volumes, SLA compliance, resolution time, agent performance update to reflect the ticket.

> That single flow demonstrates: intake → AI → SLA → collaboration → knowledge → closure → reporting.

---

## 2. Service Catalog & Requests (self-service + approvals)

| # | Action | Expected result |
|---|--------|-----------------|
| 2.1 | Help Desk → **Catalogue** | Seeded items: **New Laptop Request**, **Software Access Request**, **Employee Onboarding**, **Meeting Room Setup** |
| 2.2 | Open **New Laptop Request** and submit the form | A **service request** is created (72h SLA), status shows **awaiting approval** (this item requires approval) |
| 2.3 | Approve the request (as approver) | Request advances to fulfilment; approval is recorded |
| 2.4 | Help Desk → **Requests** | The new request appears with its status/SLA |
| 2.5 | (Admin) Catalogue → **New item**, add a custom form field | New catalog item appears and is requestable |

**Talking point:** dynamic request forms (text/select/checkbox/textarea), per-item SLA, and approval routing — like a mini service portal.

---

## 3. Automations & Templates (no-code rules)

| # | Action | Expected result |
|---|--------|-----------------|
| 3.1 | Help Desk → **Automations** | List of active rules |
| 3.2 | Help Desk → **Automations → Templates** | Pre-built templates you can enable |
| 3.3 | Enable a template (e.g. auto-assign / auto-priority) → **toggle on** | Rule becomes active |
| 3.4 | Create a ticket that matches the rule's condition | The automation fires (auto-assign / priority / reply) |

**Talking point:** trigger → condition → action, with a library of ready templates. Reduces manual work.

---

## 4. AI Showcase (the wow moment) 🤖

Run these live if the AI is responding; otherwise show pre-captured results.

| # | Action | Expected result |
|---|--------|-----------------|
| 4.1 | Help Desk → **AI Assistant**, ask an ops question | Natural-language answer grounded in help-desk context |
| 4.2 | On a ticket/conversation → **AI Suggest reply** | Drafts a reply you can edit & send |
| 4.3 | On a long conversation → **AI Summarise** | Concise summary of the thread |
| 4.4 | Help Desk → **FAQ → AI Generate** | AI proposes FAQ Q&A entries; **save** the good ones |
| 4.5 | Help Desk → **Insights → Generate** | AI trend/insight summary across tickets |
| 4.6 | (ITIL) On a **Problem** → **AI Root Cause** | Suggested root-cause analysis |

---

## 5. Knowledge Base & FAQ (self-service)

| # | Action | Expected result |
|---|--------|-----------------|
| 5.1 | Help Desk → **Knowledge Base** | Article list / categories |
| 5.2 | **KB → Create**, publish an article, attach a file | Article saved; **attachment stored in the database**; searchable |
| 5.3 | Rate an article / add a comment | Rating + comment recorded |
| 5.4 | Help Desk → **FAQ** | FAQ categories + entries (incl. AI-generated ones from §4.4) |

---

## 6. Live Chat & Agent Collaboration

| # | Action | Expected result |
|---|--------|-----------------|
| 6.1 | Help Desk → **Live Chat** | Active conversations / visitor list |
| 6.2 | Open a chat → send a message → **Convert to ticket** | Chat becomes a tracked ticket |
| 6.3 | Help Desk → **Agent Chat** / **Agent Groups** | Internal agent messaging & group membership |
| 6.4 | Help Desk → **Presence** / **Agent Schedule** | Who's online / on shift |

---

## 7. ITIL Processes (enterprise depth)

| # | Action | Expected result |
|---|--------|-----------------|
| 7.1 | Help Desk → **Problems** | Problem records; link incidents; root-cause (AI in §4.6) |
| 7.2 | Help Desk → **Changes** | Change requests with participants/approvals |
| 7.3 | Help Desk → **Releases** | Release records |
| 7.4 | Help Desk → **CMDB** | Configuration Items with relationships/links |
| 7.5 | Help Desk → **Events** → **Create incident from event** | Monitoring event → incident (matches seeded event rules) |

**Talking point:** full ITIL — Incident, Request, Problem, Change, Release, CMDB, Event management — not just a ticket box.

---

## 8. Reporting, Announcements & Admin

| # | Action | Expected result |
|---|--------|-----------------|
| 8.1 | Help Desk → **Analytics** / **Performance** | Dashboards: volume, SLA, CSAT, agent stats |
| 8.2 | Help Desk → **Reports** / **Scheduled Reports** | Build/schedule reports |
| 8.3 | Help Desk → **Announcements** | Post an announcement to users/agents |
| 8.4 | Help Desk → **Settings** (Inboxes, IP rules, Statuses, Notifications) | Configure inboxes, security IP rules, SLA statuses, notification prefs |
| 8.5 | Help Desk → **API Docs** + `/api/helpdesk/mobile/*` | REST + mobile API for integrations |

---

## 9. If the AI says "unavailable" (have this ready)

- **What it means:** the live LLM provider hit a **rate limit** — the system degrades gracefully instead of erroring.
- **On stage:** say *"This runs on a live AI model; it's momentarily rate-limited — here's the result it produced earlier,"* and show a screenshot.
- **Reduce risk:** run each AI step **once, 10–15 min before** the demo so responses are fresh; avoid clicking AI actions rapidly in succession.

---

## 10. 5-Minute Express Demo (if short on time)

1. **Create a ticket** → show SLA start (§1.1)
2. **AI triage / suggest reply** (§1.2 / §4.2)
3. **Resolve** → SLA met (§1.5)
4. **Catalogue → New Laptop Request** → approval (§2.1–2.3)
5. **Analytics** → it all shows up (§1.6)

---

## Pre-demo Test Checklist (tick before you present)

- [ ] Login works; Help Desk menu visible
- [ ] Create ticket works; SLA timer visible
- [ ] Assign + reply + internal note work
- [ ] Resolve/close updates status & SLA
- [ ] Catalogue shows 4 seeded items; request + approval works
- [ ] Automations page + templates load; a rule toggles
- [ ] KB create + attachment works
- [ ] Live chat → convert to ticket works
- [ ] Analytics/Performance render with data
- [ ] **At least one AI feature tested live** (know which ones respond today)
- [ ] Screenshots captured of AI outputs as fallback
