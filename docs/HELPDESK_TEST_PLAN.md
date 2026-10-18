# Help Desk (ITSM) — Full Functional Test Plan

**Menu:** `Operations & Support → Help Desk`
**How to use:** work top to bottom. Each row = **Action → Expected result**. Tick the box when it passes. Log failures with the test number.

Legend: 🤖 = uses live AI (may show "AI busy" if rate-limited — that's graceful, not a bug). 🔒 = permission-dependent.

---

## A. Access & Navigation
- [ ] **A1** Log in as an agent/admin → Home loads, top nav shows **Operations & Support**.
- [ ] **A2** Open **Operations & Support → Help Desk** → sub-menu lists Dashboard, All Tickets, Catalogue, Knowledge Base, FAQ, Live Chat, Automations, Problems, Changes, Releases, CMDB, Events, Analytics, Reports, Settings, etc.
- [ ] **A3** Click each sub-item once → every page loads with **no error / no blank screen**.
- [ ] **A4** 🔒 Log in as a non-agent/end-user → only permitted items are visible.

## B. Dashboard (`/helpdesk`)
- [ ] **B1** Open Dashboard → KPI cards render (open tickets, SLA, etc.).
- [ ] **B2** Counters are non-zero after seeding / after you create test tickets.
- [ ] **B3** Any "recent tickets / my queue" list links through to a ticket.

## C. Tickets — Incident Management (`/helpdesk/tickets`)
- [ ] **C1** **Create** (`/tickets/create`): fill subject, description, requester, category, priority → save → ticket gets an auto number, status **Open/New**.
- [ ] **C2** **SLA**: on the new ticket an SLA/response timer is shown and counting.
- [ ] **C3** **List**: ticket appears in All Tickets; columns (number, subject, status, priority, assignee, SLA) show.
- [ ] **C4** **Filter/search**: filter by status/priority/assignee and search by keyword → list updates correctly.
- [ ] **C5** **Assign**: assign to an agent (and/or agent group) → assignee updates.
- [ ] **C6** **Public reply**: post a reply → appears in the thread (visible to requester).
- [ ] **C7** **Internal note**: add a private note → clearly marked internal / agent-only.
- [ ] **C8** **Canned response**: insert a canned response (`/canned-responses/search`) → text inserted.
- [ ] **C9** **Attachment**: attach a file to a reply → uploads, **stored in DB**, downloadable.
- [ ] **C10** **Change priority/status**: change priority and move status (Open → In Progress → Resolved) → history/log records it.
- [ ] **C11** **SLA outcome**: on resolve, SLA shows **met** or **breached** correctly.
- [ ] **C12** **Reassign / group** transfer works.
- [ ] **C13** **Merge / link** related tickets (if present) works.
- [ ] **C14** **Export** (`/tickets/export`) → downloads a file (CSV/XLSX).
- [ ] **C15** **Trash** (`/tickets/trash`): delete a ticket → moves to trash; **restore** brings it back; permanent delete removes it.
- [ ] **C16** 🤖 **AI triage** on a ticket → suggests category/priority/response.

## D. Service Catalogue & Requests (`/helpdesk/catalogue`, `/helpdesk/requests`)
- [ ] **D1** Catalogue lists seeded items: **New Laptop Request**, **Software Access Request**, **Employee Onboarding**, **Meeting Room Setup**.
- [ ] **D2** Open **New Laptop Request** → dynamic form renders (text/select/textarea fields).
- [ ] **D3** Submit it → a **service request** is created with the item's SLA (72h), status **awaiting approval** (this item requires approval).
- [ ] **D4** 🔒 Approve as approver → request advances to fulfilment; approval recorded.
- [ ] **D5** Submit **Meeting Room Setup** (no approval) → goes straight to fulfilment.
- [ ] **D6** **Requests** page lists all requests with status + SLA.
- [ ] **D7** 🔒 Admin → Catalogue **create** a new item with custom fields → appears and is requestable.
- [ ] **D8** Admin → **edit** and **delete** a catalogue item works.

## E. Knowledge Base (`/helpdesk/kb`)
- [ ] **E1** KB index lists articles/categories.
- [ ] **E2** **Create** (`/kb/create`): write + publish an article → appears in list.
- [ ] **E3** **Attachment**: upload a file to an article → stored, downloadable.
- [ ] **E4** **Comment** on an article → saved; **delete** comment works.
- [ ] **E5** **Rate** an article → rating recorded.
- [ ] **E6** **Edit** and **delete** an article works.
- [ ] **E7** **Search** finds the article by title/content.

## F. FAQ (`/helpdesk/faq`)
- [ ] **F1** FAQ page lists categories + entries.
- [ ] **F2** **Create** a category and an FAQ entry → appears.
- [ ] **F3** **Edit / delete** FAQ + category works.
- [ ] **F4** 🤖 **AI Generate**: click AI-generate → AI proposes Q&A; **save** the good ones (they persist).

## G. Live Chat & Visitors (`/helpdesk/livechat`, `/helpdesk/visitors`)
- [ ] **G1** Live Chat lists active conversations.
- [ ] **G2** Open a conversation → send a message → appears in thread.
- [ ] **G3** **Assign** conversation to an agent → assignee updates.
- [ ] **G4** **Mark seen** works; unread indicator clears.
- [ ] **G5** **Convert to ticket** → creates a linked ticket from the chat.
- [ ] **G6** **Visitors** page shows site visitors/sessions.
- [ ] **G7** Delete a conversation works.

## H. Agent Collaboration (`/helpdesk/agent-chat`, `/agent-groups`, `/agent-schedule`, `/presence`)
- [ ] **H1** **Agent Chat**: start a thread with another agent, send message, **mark read**, typing indicator.
- [ ] **H2** **Agent Groups**: create a group, **add/remove members**, post a group message.
- [ ] **H3** **Agent Schedule**: create/update a shift; delete a schedule.
- [ ] **H4** **Presence**: shows who is online/available.

## I. Automations & Templates (`/helpdesk/automations`, `/automations/templates`)
- [ ] **I1** Automations lists rules.
- [ ] **I2** **Create** a rule: set trigger + condition + action (uses the automation canvas) → saves.
- [ ] **I3** **Toggle** a rule on/off → status changes.
- [ ] **I4** Trigger the condition (e.g. create a matching ticket) → the action fires (auto-assign/priority/reply).
- [ ] **I5** **Templates** page lists ready templates; **enable** one → it becomes an active rule.
- [ ] **I6** **Edit / delete** rule + template works.

## J. AI Features 🤖 (`/helpdesk/ai-assistant`, `/insights`)
- [ ] **J1** **AI Assistant**: ask an ops question → grounded answer.
- [ ] **J2** **AI Suggest reply** on a ticket/conversation → editable draft.
- [ ] **J3** **AI Summarise** a long conversation → concise summary.
- [ ] **J4** **Insights → Generate** → trend/insight summary across tickets.
- [ ] **J5** **AI Triage** endpoint (bulk/queue) categorises new tickets.
- [ ] **J6** If any AI step shows "AI busy/unavailable" → confirm it's a **graceful message**, not a crash.

## K. Problem Management (`/helpdesk/problems`)
- [ ] **K1** Problems index lists problem records.
- [ ] **K2** **Create** a problem; link one or more incidents/tickets to it.
- [ ] **K3** 🤖 **AI Root Cause** → suggests probable cause.
- [ ] **K4** **Edit / delete** works.

## L. Change Management (`/helpdesk/changes`)
- [ ] **L1** Changes index lists change requests.
- [ ] **L2** **Create** a change (type, risk, schedule).
- [ ] **L3** **Add/remove participants**; approval/participation recorded.
- [ ] **L4** **Edit / delete** works.

## M. Release Management (`/helpdesk/releases`)
- [ ] **M1** Releases index lists releases.
- [ ] **M2** **Create / edit / delete** a release; associate changes if supported.

## N. CMDB — Configuration Items (`/helpdesk/cmdb`)
- [ ] **N1** CMDB lists Configuration Items.
- [ ] **N2** **Create** a CI (type, attributes).
- [ ] **N3** **Add a relationship** to another CI; **add a link** (e.g. to a ticket/asset).
- [ ] **N4** **Remove** relationship/link; **edit/delete** CI works.
- [ ] **N5** `/helpdesk/assets/search` returns assets to link.

## O. Event Management (`/helpdesk/events`)
- [ ] **O1** Events index lists events.
- [ ] **O2** **Event rules**: create a rule (matches seeded ones like "Critical alerts → High-priority incident"); edit/delete.
- [ ] **O3** **Acknowledge** an event → status updates.
- [ ] **O4** **Create incident from event** → generates a linked ticket.
- [ ] **O5** 🤖 **Analyse event** → AI assessment.

## P. Announcements (`/helpdesk/announcements`)
- [ ] **P1** Create an announcement → appears to targeted users/agents.
- [ ] **P2** Edit / delete works.

## Q. Analytics, Performance & Reports (`/analytics`, `/performance`, `/reports`)
- [ ] **Q1** **Analytics**: dashboards render (volume, SLA %, resolution time, CSAT).
- [ ] **Q2** **Performance**: agent-level metrics render.
- [ ] **Q3** Numbers reflect the tickets you created during this test.
- [ ] **Q4** **Reports**: build/run a report → output shows.
- [ ] **Q5** **Scheduled Reports** (`/reports/scheduled`): create a schedule → it's listed.

## R. Settings & Administration (`/helpdesk/settings`)
- [ ] **R1** **Settings** index opens.
- [ ] **R2** **Inboxes** (`/settings/inboxes`): create/configure an email inbox.
- [ ] **R3** **IP Rules** (`/settings/ip-rules`): add an allow/deny rule.
- [ ] **R4** **Statuses** (`/status/manage`): add/edit a ticket status/SLA state.
- [ ] **R5** **Notification preferences** (`/notifications/preferences`): toggle a preference → saves.
- [ ] **R6** 🔒 **Compliance**: `offboard-agent` reassigns their work; `anonymise` scrubs PII (test on a throwaway record).

## S. API & Mobile (`/helpdesk/api-docs`, `/api/helpdesk/mobile/*`)
- [ ] **S1** **API Docs** page renders endpoint reference.
- [ ] **S2** Mobile API: `POST /api/helpdesk/mobile/login` returns a token.
- [ ] **S3** With the token: register device, list/acknowledge a ticket, comment, change status, reassign → each returns success.
- [ ] **S4** `logout` invalidates the token.

## T. Cross-cutting checks
- [ ] **T1** **Attachments** everywhere are stored in the **database** (not the filesystem) and download intact.
- [ ] **T2** **Confirm dialogs** appear before destructive deletes (and match the app's standard modal).
- [ ] **T3** **Permissions**: an end-user cannot see agent-only pages/actions.
- [ ] **T4** **SLA breach**: create a ticket with a past/near due time → it flags as at-risk/breached.
- [ ] **T5** No console errors on the main pages (open dev tools during the run).

---

## Fast smoke test (10 min, if short on time)
1. C1–C2 create ticket + SLA → C5 assign → C6 reply → C10/C11 resolve within SLA
2. D2–D4 catalogue request + approval
3. I3–I4 toggle an automation + see it fire
4. J1 or F4 one AI feature
5. Q1 analytics reflects the new ticket
6. G5 live chat → convert to ticket

## Pre-test setup
- Ensure seeders ran: `php artisan db:seed --force` (live) and, for sample data, `php artisan db:seed --class=DemoDataSeeder --force`.
- Have **two logins** ready (an agent/admin and an end-user) to test permissions and approvals.
- Run each 🤖 AI step once ~10 min early and screenshot results, in case the AI provider is rate-limited during the session.
