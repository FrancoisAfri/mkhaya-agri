# HelpDesk — Tender Gap Remediation Task List

> Backlog derived from the requirements-vs-code audit of the `app/Modules/HelpDesk/` module.
> **STATUS (2026-06-03): all code items below implemented.** Only true infra/ops items remain (see last section).

---

## P1 — Missing tender pillars

### Change Management ✅ DONE
- [x] `HdChange` model + `hd_changes` migration
- [x] Fields: change request ID (change_number), description, implementation_date, risk_assessment, backout_plan, change_results
- [x] Comment fields: approver, implementer, evaluator, tester comments
- [x] Workflow: draft → evaluation → approval → testing → approved/rejected → implemented → closed
- [x] Token-gated email actions for participants without system access (`hd_change_participants`, `HdChangeActionMail`, public change portal)
- [x] Controllers, routes, Vue pages (Changes Index/Show + public ChangePortal)
- [x] Audit logging (LogsActivity on HdChange)

### Problem Management ✅ DONE
- [x] `HdProblem` model + `hd_problems` migration
- [x] `hd_problem_incidents` pivot (link multiple incidents)
- [x] `HdTicket::problems()` belongs-to-many
- [x] Auto-close linked incidents when problem closed (`HdProblemObserver`)
- [x] Controllers, routes, Vue pages (Problems Index/Show with link/unlink)

### Asset Management integration ✅ DONE
- [x] `asset_id` on `hd_tickets` + `HdTicket::asset()` relation
- [x] UI to associate a ticket with an asset (`HdTicketActionController::linkAsset` + asset search)
- [x] Asset reports: `by_asset` + `by_asset_category` in `HdReportsController`
- [x] Filter by asset (ticket `scopeFilter`)

### Native Mobile Application (agents) ✅ DONE (server side)
- [x] Mobile API: login/logout, tickets list/detail, comment, acknowledge, reassign, status, meta (`Api/HdMobileController`, Sanctum)
- [x] Device-token storage (`hd_device_tokens`) + registration endpoint
- [x] Push service (`HdPushService`, FCM; APNS-ready) — set `FCM_SERVER_KEY`
- [x] Real-time sync: comments via API land in the same ticket thread shown on web/portal
- [ ] **Native iOS/Android binaries** — separate app project outside this repo (consumes the API above)

---

## P2 — Core service-desk gaps ✅ DONE

### Proactive SLA management
- [x] Scheduled breach/approach monitor (`HdSlaMonitorCommand`, every 5 min)
- [x] Pre-violation notifications to assignee (`HdSlaNotification`)
- [x] Escalation to supervisors/admins on breach (wired to `HelpDeskPolicyGuard::forSlaBreach`)
- [x] Auto-set `sla_breached` + `response_sla_breached` flags via job
- [x] Business-hours-aware SLA calc (`HdSlaService` — skips off-hours, breaks, holidays)

### Ticket reopen
- [x] Reopen action (`HdTicketActionController::reopen`) with reason + audit comment
- [x] RBAC: supervisor / Help Desk Administrator only

### Email-to-ticket threading
- [x] In-Reply-To / References parsed in `ImapClient` + `HdMailboxService::threadReply`
- [x] Replies append as comments; reply to closed ticket reopens it

### Ticket merging
- [x] `merged_into_id` + merge action (consolidates comments, attachments, problem links)
- [x] Merge target search + UI hook

### Public self-service portal
- [x] Unauthenticated submit (`/support`) — `HdPublicController` (routes in `helpdesk-public.php`, outside auth)
- [x] Track by reference + email, or via public token (`/support/track/{token}`)
- [x] No per-user license — public users are not system accounts

---

## P3 — Smaller feature gaps ✅ DONE
- [x] KB article attachments (morphMany on `HdKbArticle` + upload/download/delete in KB controller)
- [x] Report filters: requester, asset, source, date range (ticket `scopeFilter`)
- [x] Phone field on `hd_tickets` (`requester_phone`)
- [ ] Custom report designer UI / general no-code workflow engine — deferred (large, lower tender weight)

---

## P4 — Compliance / security
- [x] POPIA: anonymise PII (right-to-deletion), retention purge command (`HdPopiaService`, `HdRetentionPurgeCommand`, daily)
- [x] Offboarding integration: reassign a departing agent's open tickets (`HdComplianceController::offboardAgent`)
- [x] RBAC tightening: reopen + compliance actions role-gated
- [ ] **AD/LDAP SSO** — requires the `directorytree/ldaprecord-laravel` package + an LDAP server (infra). Azure OAuth already present; LDAP is a config/package add when a directory is available.

---

## Out of scope for code — verify with hosting/ops
- [ ] POPIA hosting within SA borders (data residency)
- [ ] High availability & disaster recovery
- [ ] Encryption at rest + HTTPS/TLS
- [ ] Licensing terms
- [ ] Concurrency / 100+ user load testing
